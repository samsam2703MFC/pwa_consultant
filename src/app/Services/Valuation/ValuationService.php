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
 * La marge nette est RECALCULÉE à partir des postes du P&L :
 *
 *   résultat net = CA − coût matière − main d'œuvre − frais généraux
 *
 * et non lue dans le champ `result` de l'API. Ce champ ne déduit pas toujours
 * le coût matière : la marge montait alors bien au-delà de ce qu'un point de
 * vente peut dégager, et la valorisation avec elle. Le champ `result` ne sert
 * plus que de repli, quand les postes ne sont pas tous renseignés.
 *
 * La FENÊTRE d'observation est plus longue que l'année valorisée : on regarde
 * 18 mois et on ramène le CA à 12 (valuation_window_months /
 * valuation_annual_months). Douze mois seulement laissent une saison
 * exceptionnelle — ou un mois de fermeture — décider de la valeur ; dix-huit
 * mois lissent l'accident sans effacer la tendance. La marge nette est
 * mesurée sur la même fenêtre.
 */
class ValuationService
{
    private const WINDOW_MONTHS = 18;   // fenêtre d'observation, par défaut
    private const ANNUAL_MONTHS = 12;   // durée sur laquelle le CA est ramené

    public function __construct(
        private ShopService $shopService,
        private ParamService $params,
        private PnlSnapshotRepository $snap
    ) {}

    /**
     * Données de valorisation pour le bouton « Valeurs réseau » : valeur de
     * chaque boutique + de la chaîne, à l'objectif et actuelle.
     */
    public function build(): array
    {
        $multiple     = $this->params->getFloat('valuation_multiple', 4.5);
        $targetMargin = $this->params->getFloat('valuation_target_net_margin_pct', 15.0);
        // Fenêtre d'observation et durée de référence, en mois. Bornées : une
        // fenêtre nulle ferait une division par zéro, et une fenêtre plus
        // courte que l'année valorisée extrapolerait au lieu de lisser.
        $windowMonths = max(1, (int)$this->params->getFloat('valuation_window_months', (float)self::WINDOW_MONTHS));
        $annualMonths = max(1, (int)$this->params->getFloat('valuation_annual_months', (float)self::ANNUAL_MONTHS));
        // Borne de plausibilité : au-delà, la marge nette ne vient pas d'un
        // point de vente mais d'un poste de coût manquant dans le P&L. On ne
        // corrige rien en douce — on le signale à l'écran.
        $maxMargin    = $this->params->getFloat('valuation_max_net_margin_pct', 15.0);

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
            $lab = $this->pnlValue($pnl['labour'] ?? null);
            $ovh = $this->pnlValue($pnl['overhead'] ?? null);
            $mat = $this->pnlValue($pnl['material'] ?? null);
            // Résultat net RECALCULÉ (CA − matière − main d'œuvre − frais) :
            // le champ `result` de l'API ne déduit pas toujours la matière.
            $res = $this->netResult($ca, $mat, $lab, $ovh, $this->pnlValue($pnl['result'] ?? null));
            $mg  = ($ca !== null && $ca > 0 && $res !== null) ? ($res / $ca) * 100 : null;
            if ($ca !== null || $mg !== null) {
                $this->snap->upsertMonth($id, $curY, $curM, $ca, $mg, $res, $lab, $ovh);
            }
        }

        // 2) Fenêtre d'observation glissante (18 mois par défaut).
        $since   = $now->modify('-' . ($windowMonths - 1) . ' months');
        $sinceY  = (int)$since->format('Y');
        $sinceM  = (int)$since->format('n');
        $fromWin = date('Y-m-d', (int)$since->format('U'));
        $toWin   = date('Y-m-d');

        // P2 — historique P&L MENSUEL réel : la marge nette devient exacte
        // immédiatement (plus besoin d'attendre l'accumulation des
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
                $net = $this->netResult($ca, $p['material'], $p['labour'], $p['overhead'], $p['result']);
                $rows[] = [
                    'id_shop'        => $id,
                    'year'           => $y,
                    'month'          => $m,
                    'ca'             => $ca,
                    'material'       => $p['material'],
                    'labour'         => $p['labour'],
                    'overhead'       => $p['overhead'],
                    'net_margin_pct' => $net !== null ? $net / $ca * 100 : null,
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
        // Cumuls 12 mois des postes, par boutique. La marge se déduit d'EUX, pas
        // d'une moyenne des pourcentages mensuels : moyenner des pourcentages
        // donne autant de poids à un mois creux qu'à un mois plein, et le
        // résultat ne se retrouve pas dans le P&L cumulé.
        $costsByShop = [];   // shopId => ['ca','material','labour','overhead','months']
        foreach ($rows as $r) {
            $sid = (int)$r['id_shop'];
            $ca  = $r['ca'] !== null ? (float)$r['ca'] : null;
            if ($ca === null) {
                continue;
            }
            $costsByShop[$sid]['ca']     = ($costsByShop[$sid]['ca'] ?? 0.0) + $ca;
            $costsByShop[$sid]['months'] = ($costsByShop[$sid]['months'] ?? 0) + 1;
            foreach (['material', 'labour', 'overhead'] as $k) {
                if (($r[$k] ?? null) !== null) {
                    $costsByShop[$sid][$k] = ($costsByShop[$sid][$k] ?? 0.0) + abs((float)$r[$k]);
                    $costsByShop[$sid][$k . '_n'] = ($costsByShop[$sid][$k . '_n'] ?? 0) + 1;
                }
            }
            // Repli : quand les postes ne sont pas tous là, on cumule le
            // résultat net mois par mois plutôt que de perdre la boutique.
            if (($r['net_margin_pct'] ?? null) !== null) {
                $costsByShop[$sid]['net'] = ($costsByShop[$sid]['net'] ?? 0.0)
                    + $ca * (float)$r['net_margin_pct'] / 100;
            }
        }

        // 3) Valorisation par boutique. Le CA de référence est celui du P&L —
        //    le même que celui dont sont issus les coûts. Mélanger le CA des
        //    KPIs de vente avec des coûts venus du P&L revient à diviser deux
        //    grandeurs qui ne comptent pas la même chose. Les KPIs de vente ne
        //    servent que de repli, quand aucun P&L n'est disponible.
        $caWins = $this->shopService->getSalesKpisBatch(array_map(
            fn($id) => ['shop' => $id, 'from' => $fromWin, 'to' => $toWin],
            array_keys($ids)
        ));
        $shopsOut = [];
        $sumCa = 0.0; $sumActuelle = 0.0; $sumObjectif = 0.0;
        // CA des seules boutiques dont la marge est RÉELLE : c'est la base de
        // la valorisation actuelle, donc la seule base sur laquelle la marge
        // moyenne de la chaîne puisse être recalculée sans mentir.
        $sumCaReal = 0.0; $shopsReal = 0;
        foreach ($ids as $id => $name) {
            $c        = $costsByShop[$id] ?? [];
            $caPnl    = $c['ca'] ?? null;
            $caKpi    = (float)($caWins["{$id}|{$fromWin}|{$toWin}"]['ca'] ?? 0);
            $caFromPnl = ($caPnl !== null && $caPnl > 0);

            // Marge nette = (CA − matière − main d'œuvre − frais) ÷ CA, sur les
            // cumuls de la fenêtre. Les trois postes doivent couvrir tous les
            // mois observés, sinon le cumul de coûts serait incomplet face au CA.
            $months  = (int)($c['months'] ?? 0);
            $full    = $months > 0
                && ($c['material_n'] ?? 0) === $months
                && ($c['labour_n']   ?? 0) === $months
                && ($c['overhead_n'] ?? 0) === $months;
            // Postes absents d'au moins un mois : ce sont eux qui expliquent une
            // marge invraisemblable. Les nommer transforme « le chiffre est
            // faux » en « il manque les frais généraux de Namur ».
            $missingCosts = [];
            foreach (['material' => 'coût matière', 'labour' => 'main d\'œuvre',
                      'overhead' => 'frais généraux'] as $k => $label) {
                if ($months > 0 && ($c[$k . '_n'] ?? 0) < $months) {
                    $missingCosts[] = $label;
                }
            }

            $netTotal = null;
            $marginFrom = null;
            if ($full) {
                $netTotal   = $c['ca'] - $c['material'] - $c['labour'] - $c['overhead'];
                $marginFrom = 'postes';
            } elseif (isset($c['net'])) {
                // Repli : résultat net cumulé, tel que le back-office le donne.
                // C'est précisément le chiffre dont on se méfie — on note d'où
                // il vient pour pouvoir le dire à l'écran.
                $netTotal   = $c['net'];
                $marginFrom = 'result';
            }
            $avgMargin = ($netTotal !== null && ($c['ca'] ?? 0) > 0)
                ? $netTotal / $c['ca'] * 100 : null;

            // CA ANNUEL : le cumul de la fenêtre ramené à douze mois. Sur le
            // P&L, on divise par les mois réellement observés — une boutique
            // ouverte depuis six mois ne doit pas voir son CA divisé par
            // dix-huit. Sur le repli KPIs, la fenêtre est entière par
            // construction.
            $ca12 = $caFromPnl
                ? ($months > 0 ? $caPnl / $months * $annualMonths : 0.0)
                : $caKpi / $windowMonths * $annualMonths;

            $valoObjectif = $ca12 * ($targetMargin / 100) * $multiple;
            $valoActuelle = ($avgMargin !== null) ? $ca12 * ($avgMargin / 100) * $multiple : null;

            $shopsOut[] = [
                'id'            => $id,
                'name'          => $name,
                'ca12m'         => $ca12,
                'avg_margin'    => $avgMargin,
                'valo_actuelle' => $valoActuelle,
                'valo_objectif' => $valoObjectif,
                'months_seen'   => $months,
                // Postes cumulés sur les mois observés : la marge affichée doit
                // pouvoir se refaire à la main à partir d'eux.
                'material'      => $c['material'] ?? null,
                'labour'        => $c['labour']   ?? null,
                'overhead'      => $c['overhead'] ?? null,
                // Chaque poste en part du CA. C'est là qu'un poste sous-évalué
                // se voit : des frais généraux à 4 % du CA ne couvrent pas un
                // loyer, des redevances et de l'énergie.
                'cost_mix'      => $this->costMix($c),
                // Le CA vient-il du P&L (même source que les coûts) ou, faute de
                // P&L, des KPIs de vente ?
                'ca_from_pnl'   => $caFromPnl,
                // D'où sort la marge, et quels postes manquent : de quoi
                // expliquer un chiffre invraisemblable au lieu de le subir.
                'margin_from'   => $marginFrom,
                'missing_costs' => $missingCosts,
                'margin_over'   => ($avgMargin !== null && $avgMargin > $maxMargin),
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
            // Borne de plausibilité + boutiques qui la dépassent : une marge
            // nette au-dessus veut dire qu'un poste de coût manque au P&L.
            'months_window'     => $windowMonths,
            'annual_months'     => $annualMonths,
            'max_margin_pct'    => $maxMargin,
            'margin_over'       => array_values(array_map(
                fn($s) => [
                    'name'    => $s['name'],
                    'pct'     => round((float)$s['avg_margin'], 1),
                    // Ce qui explique le dépassement : postes absents, marge lue
                    // dans `result` faute de postes, ou — quand tout est là —
                    // la structure de coûts elle-même, où le poste sous-évalué
                    // se repère à sa part du CA.
                    'missing'  => $s['missing_costs'],
                    'from'     => $s['margin_from'],
                    'cost_mix' => $s['cost_mix'],
                ],
                array_filter($shopsOut, fn($s) => !empty($s['margin_over']))
            )),
            'captured_month' => sprintf('%04d-%02d', $curY, $curM),
        ];
    }

    /**
     * Résultat net d'un mois : CA − coût matière − main d'œuvre − frais
     * généraux.
     *
     * Le champ `result` de l'API ne déduit pas toujours le coût matière ; s'y
     * fier donnait des marges nettes très au-dessus de ce qu'un point de vente
     * dégage, et une valorisation gonflée d'autant. On ne l'utilise donc qu'en
     * REPLI, quand les postes ne sont pas tous renseignés — sans les trois
     * postes, aucun recalcul n'est possible.
     */
    private function netResult(?float $ca, ?float $material, ?float $labour, ?float $overhead, ?float $apiResult): ?float
    {
        if ($ca !== null && $material !== null && $labour !== null && $overhead !== null) {
            // Les postes de coût peuvent arriver signés (négatifs) selon le
            // back-office : on raisonne en valeur absolue, un coût se retranche.
            return $ca - abs($material) - abs($labour) - abs($overhead);
        }
        return $apiResult;
    }

    /**
     * Part de chaque poste dans le CA, sur les cumuls de la fenêtre.
     *
     * Quand les trois postes sont fournis et que la marge dépasse quand même la
     * borne, ce n'est pas qu'une ligne manque : c'est qu'une ligne est
     * sous-évaluée. Les frais généraux couvrent le loyer, les redevances,
     * l'énergie, les amortissements — à 4 % du CA, ils ne les couvrent
     * visiblement pas. Cette répartition rend le poste fautif repérable.
     *
     * @return array<string, float>|null part en % du CA, par poste
     */
    private function costMix(array $c): ?array
    {
        $ca = $c['ca'] ?? 0.0;
        if ($ca <= 0) {
            return null;
        }
        $mix = [];
        foreach (['material', 'labour', 'overhead'] as $k) {
            if (isset($c[$k])) {
                $mix[$k] = round($c[$k] / $ca * 100, 1);
            }
        }
        return $mix !== [] ? $mix : null;
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
