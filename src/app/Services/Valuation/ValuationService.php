<?php
namespace App\Consultant\app\Services\Valuation;

use App\Consultant\app\Repositories\Valuation\PnlSnapshotRepository;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Param\ParamService;

/**
 * Valorisation des boutiques et du réseau — AUCUNE constante en dur (multiple
 * et marge cible lus depuis mac_consultant_param).
 *
 *   Valo à l'objectif = CA annuel × marge cible × multiple
 *   Valo actuelle     = marge nette moyenne (12 mois) × CA annuel × multiple
 *
 * La marge nette 12 mois vient des snapshots mensuels (mac_shop_monthly_pnl) : à
 * chaque calcul on capture le mois courant (P&L : résultat ÷ CA), et la moyenne
 * se construit au fil des mois. Le CA annuel vient des KPIs ventes (12 mois).
 */
class ValuationService
{
    private const MONTHS = 12;

    public function __construct(
        private ShopService $shopService,
        private ParamService $params,
        private PnlSnapshotRepository $snap
    ) {}

    /**
     * Données de valorisation pour le bouton « Valeurs réseau » : valeur de
     * chaque boutique + de la chaîne, à l'objectif et actuelle, + série
     * d'évolution 12 mois (boutique et réseau).
     */
    public function build(): array
    {
        $multiple     = $this->params->getFloat('valuation_multiple', 4.5);
        $targetMargin = $this->params->getFloat('valuation_target_net_margin_pct', 15.0);

        $shops = $this->shopService->getAllShops();
        $ids   = [];
        foreach ($shops as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = (string)($s['representative_name'] ?? $s['name'] ?? ('#' . $id));
            }
        }

        // 1) Capture du mois courant (CA + marge nette) par boutique.
        //    P8 : le P&L de TOUTES les boutiques en un appel ; à défaut,
        //    curl_multi (jamais une boucle séquentielle — N × 10 s de timeout
        //    possible dépasserait le temps d'exécution de la page).
        $now   = new \DateTimeImmutable('first day of this month');
        $curY  = (int)$now->format('Y');
        $curM  = (int)$now->format('n');
        $pnls  = $this->shopService->getPnlSummaryAllShops('month')
            ?? $this->shopService->getPnlMany(array_keys($ids), 'month');
        foreach ($ids as $id => $name) {
            $pnl = is_array($pnls[$id] ?? null) ? $pnls[$id] : [];
            $ca  = $this->pnlValue($pnl['turnover'] ?? null);
            $res = $this->pnlValue($pnl['result'] ?? null);
            $lab = $this->pnlValue($pnl['labour'] ?? null);
            $ovh = $this->pnlValue($pnl['overhead'] ?? null);
            $mg  = ($ca !== null && $ca > 0 && $res !== null) ? ($res / $ca) * 100 : null;
            if ($ca !== null || $mg !== null) {
                $this->snap->upsertMonth($id, $curY, $curM, $ca, $mg, $res, $lab, $ovh);
            }
        }

        // 2) Fenêtre 12 mois glissants.
        $since   = $now->modify('-' . (self::MONTHS - 1) . ' months');
        $sinceY  = (int)$since->format('Y');
        $sinceM  = (int)$since->format('n');
        $from12  = date('Y-m-d', (int)$since->format('U'));
        $to12    = date('Y-m-d');

        // P2 — historique P&L MENSUEL réel : la marge nette 12 mois devient
        // exacte immédiatement (plus besoin d'attendre l'accumulation des
        // snapshots). Repli sur les snapshots si l'endpoint est absent.
        $fromYm = sprintf('%04d-%02d', $sinceY, $sinceM);
        $toYm   = sprintf('%04d-%02d', $curY, $curM);
        $rows    = [];
        $p2Shops = 0;
        // Toutes les boutiques en parallèle : une boucle séquentielle paierait
        // N fois la latence de l'API (et jusqu'à N × 10 s si elle traîne).
        $hists = $this->shopService->getMonthlyPnlMany(array_keys($ids), $fromYm, $toYm);
        foreach ($ids as $id => $name) {
            $hist = $hists[$id] ?? null;
            if (!is_array($hist)) {
                // Pas de données pour CETTE boutique : on continue, sans
                // sacrifier les autres (une seule boutique muette ne doit pas
                // vider toute la valorisation).
                continue;
            }
            $p2Shops++;
            foreach ($hist as $ym => $p) {
                $ca = $p['turnover'];
                if ($ca === null || $ca <= 0) {
                    continue;
                }
                [$y, $m] = array_map('intval', explode('-', $ym));
                $rows[] = [
                    'id_shop'        => $id,
                    'year'           => $y,
                    'month'          => $m,
                    'ca'             => $ca,
                    'net_margin_pct' => $p['result'] !== null ? $p['result'] / $ca * 100 : null,
                ];
            }
        }
        if ($p2Shops === 0) {
            // Aucune boutique servie par P2 (endpoint absent) → snapshots
            // (marge nette captée mois après mois).
            $rows = $this->snap->forShopsSince(array_keys($ids), $sinceY, $sinceM);
        } elseif ($p2Shops < count($ids)) {
            // Couverture partielle : on complète avec les snapshots des
            // boutiques que P2 n'a pas servies.
            $served = [];
            foreach ($rows as $r) {
                $served[(int)$r['id_shop']] = true;
            }
            $missing = array_values(array_diff(array_keys($ids), array_keys($served)));
            if ($missing !== []) {
                foreach ($this->snap->forShopsSince($missing, $sinceY, $sinceM) as $r) {
                    $rows[] = $r;
                }
            }
        }
        $marginsByShop = [];   // shopId => [pct, ...]
        $seriesByShop  = [];   // shopId => ['YYYY-MM' => valorisation mensuelle annualisée]
        foreach ($rows as $r) {
            $sid = (int)$r['id_shop'];
            $mg  = $r['net_margin_pct'] !== null ? (float)$r['net_margin_pct'] : null;
            $ca  = $r['ca'] !== null ? (float)$r['ca'] : null;
            if ($mg !== null) {
                $marginsByShop[$sid][] = $mg;
            }
            if ($mg !== null && $ca !== null) {
                $ym = sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']);
                // Valorisation « annualisée » du mois : CA mensuel ×12 × marge × multiple.
                $seriesByShop[$sid][$ym] = ($ca * 12) * ($mg / 100) * $multiple;
            }
        }

        // 3) Valorisation par boutique. Le CA 12 mois de toutes les boutiques
        //    porte la MÊME fenêtre : un seul appel multi-boutiques (P3), sinon
        //    curl_multi — au lieu d'un aller-retour par boutique.
        $ca12s = $this->shopService->getSalesKpisBatch(array_map(
            fn($id) => ['shop' => $id, 'from' => $from12, 'to' => $to12],
            array_keys($ids)
        ));
        $shopsOut = [];
        $sumCa = 0.0; $sumActuelle = 0.0; $sumObjectif = 0.0;
        // CA des seules boutiques dont la marge est RÉELLE : c'est la base de
        // la valorisation actuelle, donc la seule base sur laquelle la marge
        // moyenne de la chaîne puisse être recalculée sans mentir.
        $sumCaReal = 0.0; $shopsReal = 0;
        foreach ($ids as $id => $name) {
            $ca12 = (float)($ca12s["{$id}|{$from12}|{$to12}"]['ca'] ?? 0);
            $margins = $marginsByShop[$id] ?? [];
            $avgMargin = $margins !== [] ? array_sum($margins) / count($margins) : null;

            $valoObjectif = $ca12 * ($targetMargin / 100) * $multiple;
            $valoActuelle = ($avgMargin !== null) ? $ca12 * ($avgMargin / 100) * $multiple : null;

            $shopsOut[] = [
                'id'            => $id,
                'name'          => $name,
                'ca12m'         => $ca12,
                'avg_margin'    => $avgMargin,
                'valo_actuelle' => $valoActuelle,
                'valo_objectif' => $valoObjectif,
                'months_seen'   => count($margins),
            ];
            $sumCa += $ca12;
            $sumObjectif += $valoObjectif;
            if ($valoActuelle !== null) {
                $sumActuelle += $valoActuelle;
                $sumCaReal   += $ca12;
                $shopsReal++;
            }
        }
        usort($shopsOut, fn($a, $b) => ($b['valo_actuelle'] ?? 0) <=> ($a['valo_actuelle'] ?? 0));

        // 4) Série d'évolution : liste des mois présents + réseau (somme) et par boutique.
        $monthsSet = [];
        foreach ($seriesByShop as $bym) {
            foreach ($bym as $ym => $_) { $monthsSet[$ym] = true; }
        }
        ksort($monthsSet);
        $months = array_keys($monthsSet);
        $network = [];
        foreach ($months as $ym) {
            $tot = 0.0;
            foreach ($seriesByShop as $bym) { $tot += $bym[$ym] ?? 0; }
            $network[] = $tot;
        }
        $byShopSeries = [];
        foreach ($seriesByShop as $sid => $bym) {
            $byShopSeries[$sid] = array_map(fn($ym) => $bym[$ym] ?? null, $months);
        }

        return [
            'multiple'          => $multiple,
            'target_margin_pct' => $targetMargin,
            'shops'             => $shopsOut,
            'network'           => [
                'ca12m'         => $sumCa,
                // Marge de la chaîne PONDÉRÉE par le CA, et déduite des chiffres
                // affichés : une moyenne simple des marges de chaque boutique
                // donnerait autant de poids à la plus petite qu'à la plus
                // grosse, et ne se retrouverait pas dans la valorisation.
                'avg_margin'    => ($sumCaReal > 0)
                    ? $sumActuelle / ($multiple * $sumCaReal) * 100 : null,
                'ca12m_real'    => $sumCaReal,
                'valo_actuelle' => $shopsReal > 0 ? $sumActuelle : null,
                'valo_objectif' => $sumObjectif,
                // De quoi dire à l'écran si « actuelle » repose sur des marges
                // réelles, et sur combien de boutiques.
                'shops_real'    => $shopsReal,
                'shops_total'   => count($ids),
            ],
            'series' => [
                'months'  => $months,
                'network' => $network,
                'by_shop' => $byShopSeries,
            ],
            'captured_month' => sprintf('%04d-%02d', $curY, $curM),
        ];
    }

    /** Valeur numérique d'un nœud P&L ({value:…} ou scalaire numérique). */
    private function pnlValue($node): ?float
    {
        if (is_int($node) || is_float($node)) {
            return (float)$node;
        }
        if (is_string($node) && is_numeric($node)) {
            return (float)$node;
        }
        if (is_array($node) && isset($node['value']) && is_numeric($node['value'])) {
            return (float)$node['value'];
        }
        return null;
    }
}
