<?php
namespace App\Consultant\app\Services\Valuation;

use App\Consultant\app\Repositories\Valuation\PnlSnapshotRepository;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Param\ParamService;

/**
 * Valorisation des boutiques et du réseau — AUCUNE constante en dur (multiple
 * et marge cible lus depuis consultant_param).
 *
 *   Valo à l'objectif = CA annuel × marge cible × multiple
 *   Valo actuelle     = marge nette moyenne (12 mois) × CA annuel × multiple
 *
 * La marge nette 12 mois vient des snapshots mensuels (shop_monthly_pnl) : à
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
        $now   = new \DateTimeImmutable('first day of this month');
        $curY  = (int)$now->format('Y');
        $curM  = (int)$now->format('n');
        foreach ($ids as $id => $name) {
            $pnl = $this->shopService->getPnl($id, 'month');
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

        // Snapshots par boutique (marge nette captée).
        $rows = $this->snap->forShopsSince(array_keys($ids), $sinceY, $sinceM);
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

        // 3) Valorisation par boutique.
        $shopsOut = [];
        $sumCa = 0.0; $sumActuelle = 0.0; $sumObjectif = 0.0; $allMargins = [];
        foreach ($ids as $id => $name) {
            $ca12 = (float)($this->shopService->getSalesKpis($id, $from12, $to12)['ca'] ?? 0);
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
            if ($valoActuelle !== null) { $sumActuelle += $valoActuelle; }
            foreach ($margins as $m) { $allMargins[] = $m; }
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
                'avg_margin'    => $allMargins !== [] ? array_sum($allMargins) / count($allMargins) : null,
                'valo_actuelle' => $sumActuelle,
                'valo_objectif' => $sumObjectif,
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
