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
 *   Valo actuelle     = CA annuel × marge nette du MOIS PRÉCÉDENT × multiple
 *
 * Les deux grandeurs ne se lisent pas sur la même durée, et c'est voulu :
 *   - le CA annuel est celui des 12 derniers mois CLÔTURÉS ;
 *   - la marge est celle du DERNIER MOIS CLOS — elle dit où en est la
 *     boutique aujourd'hui, pas où elle en était l'an dernier.
 *
 * Dans les deux cas, le mois en cours est exclu : son chiffre d'affaires est
 * partiel et ses charges ne sont pas toutes passées. La fenêtre s'arrête donc
 * au dernier jour du mois précédent.
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
    private const WINDOW_MONTHS = 12;   // mois CLÔTURÉS observés, par défaut
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

        // 2) Fenêtre d'observation : les N derniers mois CLÔTURÉS. Elle s'arrête
        //    au dernier jour du mois précédent — le mois en cours a un chiffre
        //    d'affaires partiel, et le compter comme un mois plein tirait la
        //    moyenne vers le bas.
        $lastClosed = $now->modify('-1 month');
        $since      = $lastClosed->modify('-' . ($windowMonths - 1) . ' months');
        $sinceY     = (int)$since->format('Y');
        $sinceM     = (int)$since->format('n');
        $fromWin    = $since->format('Y-m-d');
        $toWin      = $lastClosed->modify('last day of this month')->format('Y-m-d');

        // P2 — historique P&L MENSUEL réel : la marge nette devient exacte
        // immédiatement (plus besoin d'attendre l'accumulation des
        // snapshots). Repli sur les snapshots si l'endpoint est absent.
        // Jusqu'au mois PRÉCÉDENT : le mois en cours n'est pas clôturé, et ni
        // son chiffre d'affaires ni ses charges ne sont complets.
        $fromYm = sprintf('%04d-%02d', $sinceY, $sinceM);
        $toYm   = $lastClosed->format('Y-m');
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
        // Le P&L MENSUEL ne renvoie pas toujours le coût matière. Le P&L
        // QUOTIDIEN, lui, le porte — c'est de lui que vit la heatmap de
        // rentabilité, qui calcule la même marge et tombe juste. Pour les
        // boutiques dont les postes mensuels sont incomplets, on reconstitue
        // les mois à partir des jours plutôt que de lire un `result` amputé.
        // UNIQUEMENT le mois précédent : c'est le seul mois dont la marge a
        // besoin. Rapatrier dix-huit mois de données jour par jour pour toutes
        // les boutiques — ce qui arrive dès que le coût matière manque au
        // mensuel, c'est-à-dire toujours — représente des dizaines de milliers
        // de lignes et dépasse le délai de la passerelle.
        $prevStart = $now->modify('-1 month');
        $rows = $this->fillFromDailyPnl(
            $rows,
            array_keys($ids),
            $prevStart->format('Y-m-d'),
            $prevStart->modify('last day of this month')->format('Y-m-d')
        );

        // Deux lectures différentes des mêmes lignes :
        //   - le CA cumulé sur toute la fenêtre, qui donnera le CA annuel ;
        //   - le détail mois par mois, où sera pris le MOIS DE RÉFÉRENCE de la
        //     marge (le mois précédent).
        $caByShop    = [];   // shopId => ['ca' => …, 'months' => …]
        $monthByShop = [];   // shopId => 'YYYY-MM' => postes du mois
        foreach ($rows as $r) {
            $sid = (int)$r['id_shop'];
            $ca  = $r['ca'] !== null ? (float)$r['ca'] : null;
            if ($ca === null || $ca <= 0) {
                continue;
            }
            $caByShop[$sid]['ca']     = ($caByShop[$sid]['ca'] ?? 0.0) + $ca;
            $caByShop[$sid]['months'] = ($caByShop[$sid]['months'] ?? 0) + 1;

            $ym  = sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']);
            $ent = ['ca' => $ca, 'net' => null];
            foreach (['material', 'labour', 'overhead'] as $k) {
                $ent[$k] = ($r[$k] ?? null) !== null ? abs((float)$r[$k]) : null;
            }
            if ($ent['material'] !== null && $ent['labour'] !== null && $ent['overhead'] !== null) {
                $ent['net']  = $ca - $ent['material'] - $ent['labour'] - $ent['overhead'];
                $ent['from'] = 'postes';
            } elseif (($r['net_margin_pct'] ?? null) !== null) {
                // Repli : le résultat net tel que le back-office le donne.
                $ent['net']  = $ca * (float)$r['net_margin_pct'] / 100;
                $ent['from'] = 'result';
            }
            $monthByShop[$sid][$ym] = $ent;
        }
        // Mois de référence de la marge : le mois PRÉCÉDENT. Le mois en cours
        // n'est pas fini — sa marge se lirait sur des charges partielles.
        $prevYm = $now->modify('-1 month')->format('Y-m');

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
            $cw        = $caByShop[$id] ?? [];
            $caPnl     = $cw['ca'] ?? null;
            $caKpi     = (float)($caWins["{$id}|{$fromWin}|{$toWin}"]['ca'] ?? 0);
            $caFromPnl = ($caPnl !== null && $caPnl > 0);
            $months    = (int)($cw['months'] ?? 0);

            // CA ANNUEL : les 12 derniers mois CLÔTURÉS. On divise par les mois
            // réellement observés avant de ramener à douze — une boutique
            // ouverte depuis six mois ne doit pas voir son CA divisé par douze.
            // Quand les douze mois sont là, cela revient à leur somme. Sur le
            // repli KPIs, la fenêtre est entière par construction.
            $ca12 = $caFromPnl
                ? ($months > 0 ? $caPnl / $months * $annualMonths : 0.0)
                : $caKpi / $windowMonths * $annualMonths;

            // MARGE : celle du mois précédent, et de lui seul. À défaut, le
            // mois complet le plus récent — mais on dit toujours lequel, sinon
            // « 12 % » ne veut rien dire.
            $mByShop     = $monthByShop[$id] ?? [];
            $marginYm    = null;
            if (isset($mByShop[$prevYm]) && $mByShop[$prevYm]['net'] !== null) {
                $marginYm = $prevYm;
            } else {
                foreach (array_keys($mByShop) as $ym) {
                    if ($ym < $prevYm && $mByShop[$ym]['net'] !== null
                        && ($marginYm === null || $ym > $marginYm)) {
                        $marginYm = $ym;
                    }
                }
            }
            $mo         = $marginYm !== null ? $mByShop[$marginYm] : null;
            $avgMargin  = ($mo !== null && $mo['ca'] > 0) ? $mo['net'] / $mo['ca'] * 100 : null;
            $marginFrom = $mo['from'] ?? null;

            // Postes absents du mois de référence : ce sont eux qui expliquent
            // une marge invraisemblable. Les nommer transforme « le chiffre est
            // faux » en « il manque les frais généraux de Namur ».
            $missingCosts = [];
            if ($mo !== null) {
                foreach (['material' => 'coût matière', 'labour' => 'main d\'œuvre',
                          'overhead' => 'frais généraux'] as $k => $label) {
                    if ($mo[$k] === null) {
                        $missingCosts[] = $label;
                    }
                }
            }

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
                // Le mois d'où sort la marge : « 12 % » ne veut rien dire sans
                // lui, surtout quand le mois précédent n'était pas exploitable.
                'margin_month'  => $marginYm,
                'margin_is_prev' => $marginYm === $prevYm,
                // Postes du mois de référence : la marge affichée doit pouvoir
                // se refaire à la main à partir d'eux.
                'material'      => $mo['material'] ?? null,
                'labour'        => $mo['labour']   ?? null,
                'overhead'      => $mo['overhead'] ?? null,
                // Chaque poste en part du CA. C'est là qu'un poste sous-évalué
                // se voit : des frais généraux à 4 % du CA ne couvrent pas un
                // loyer, des redevances et de l'énergie.
                'cost_mix'      => $mo !== null ? $this->costMix($mo) : null,
                // Le CA vient-il du P&L (même source que les coûts) ou, faute de
                // P&L, des KPIs de vente ?
                'ca_from_pnl'   => $caFromPnl,
                // D'où sort la marge, et quels postes manquent : de quoi
                // expliquer un chiffre invraisemblable au lieu de le subir.
                'margin_from'   => $marginFrom,
                'missing_costs' => $missingCosts,
                'margin_over'   => ($avgMargin !== null && $avgMargin > $maxMargin),
                // Le détail mois par mois, tel que le P&L mensuel le renvoie :
                // CA, matière, main d'œuvre, frais généraux, et le net qui en
                // découle. C'est ce qui permet de vérifier le chiffre plutôt
                // que de le croire.
                'months'        => $this->monthlyDetail($mByShop),
                // Marge nette ANNUELLE : résultat net cumulé ÷ CA cumulé sur
                // les mois clôturés dont les trois postes sont connus.
                'annual_margin' => $this->annualMargin($mByShop),
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
            // Mois de référence de la marge : le mois précédent.
            'margin_month'      => $prevYm,
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
     * Complète les mois dont les postes de coût sont incomplets à partir du P&L
     * QUOTIDIEN — la source de la heatmap de rentabilité.
     *
     * Le P&L mensuel (P2) ne renvoie pas toujours le coût matière ; sans lui, la
     * marge retombe sur le champ `result`, qui ne le déduit pas non plus. Le
     * P&L quotidien (P0) le porte : on agrège ses journées en mois et on
     * remplace les lignes incomplètes.
     *
     * Un seul aller-retour, pour les seules boutiques concernées, et sur le
     * seul mois dont la marge a besoin — le mois précédent. Le faire sur toute
     * la fenêtre représenterait dix-huit mois de lignes quotidiennes par
     * boutique : la réponse ne reviendrait jamais.
     *
     * @param array  $rows lignes mensuelles déjà collectées
     * @param int[]  $ids  boutiques du périmètre
     * @param string $from premier jour du mois visé ('Y-m-d')
     * @param string $to   dernier jour du même mois
     */
    private function fillFromDailyPnl(array $rows, array $ids, string $from, string $to): array
    {
        // Seul le mois demandé compte : une boutique dont le mois de mars est
        // incomplet n'a rien à gagner à faire descendre les jours de juin.
        $ym         = substr($from, 0, 7);
        $incomplete = [];
        $seen       = [];
        foreach ($rows as $r) {
            if (sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']) !== $ym) {
                continue;
            }
            $sid        = (int)$r['id_shop'];
            $seen[$sid] = true;
            if (($r['material'] ?? null) === null || ($r['labour'] ?? null) === null
                || ($r['overhead'] ?? null) === null) {
                $incomplete[$sid] = true;
            }
        }
        // Une boutique dont ce mois manque n'a rien : elle a tout à gagner à
        // être servie par le quotidien.
        foreach ($ids as $sid) {
            if (empty($seen[(int)$sid])) {
                $incomplete[(int)$sid] = true;
            }
        }
        if ($incomplete === []) {
            return $rows;
        }

        $daily = $this->shopService->getDailyPnlMany(array_map(
            fn($sid) => ['shop' => $sid, 'from' => $from, 'to' => $to],
            array_keys($incomplete)
        ));
        if ($daily === []) {
            return $rows;
        }

        // Agrégation jour → mois. Un poste absent d'un seul jour rendrait le
        // cumul du mois incomplet : on ne remplace alors pas la ligne.
        $byMonth = [];   // sid => ym => postes
        foreach ($daily as $sid => $days) {
            foreach ($days as $date => $p) {
                if (($p['revenue'] ?? null) === null) {
                    continue;
                }
                $ym = substr((string)$date, 0, 7);
                $b  = &$byMonth[(int)$sid][$ym];
                $b['ca'] = ($b['ca'] ?? 0.0) + (float)$p['revenue'];
                foreach (['material', 'labour', 'overhead'] as $k) {
                    if (($p[$k] ?? null) === null) {
                        $b[$k . '_gap'] = true;
                    } else {
                        $b[$k] = ($b[$k] ?? 0.0) + abs((float)$p[$k]);
                    }
                }
                unset($b);
            }
        }

        $rebuilt = [];
        foreach ($byMonth as $sid => $months) {
            foreach ($months as $ym => $b) {
                $ca = $b['ca'] ?? 0.0;
                if ($ca <= 0 || !empty($b['material_gap']) || !empty($b['labour_gap'])
                    || !empty($b['overhead_gap'])
                    || !isset($b['material'], $b['labour'], $b['overhead'])) {
                    continue;
                }
                [$y, $m] = array_map('intval', explode('-', $ym));
                $net = $ca - $b['material'] - $b['labour'] - $b['overhead'];
                $rebuilt["{$sid}|{$ym}"] = [
                    'id_shop'        => (int)$sid,
                    'year'           => $y,
                    'month'          => $m,
                    'ca'             => $ca,
                    'material'       => $b['material'],
                    'labour'         => $b['labour'],
                    'overhead'       => $b['overhead'],
                    'net_margin_pct' => $net / $ca * 100,
                ];
            }
        }
        if ($rebuilt === []) {
            return $rows;
        }

        // Les lignes reconstituées remplacent les lignes incomplètes du même
        // mois ; les mois déjà complets ne sont pas touchés.
        $out = [];
        foreach ($rows as $r) {
            $key = (int)$r['id_shop'] . '|' . sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']);
            $whole = ($r['material'] ?? null) !== null && ($r['labour'] ?? null) !== null
                && ($r['overhead'] ?? null) !== null;
            if (!$whole && isset($rebuilt[$key])) {
                $out[] = $rebuilt[$key];
                unset($rebuilt[$key]);
                continue;
            }
            $out[] = $r;
            unset($rebuilt[$key]);
        }
        // Mois que le mensuel ne connaissait pas du tout.
        foreach ($rebuilt as $r) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Détail mois par mois, du plus ancien au plus récent — la matière du
     * modal de vérification : CA − matière − main d'œuvre − frais généraux.
     *
     * @param array $months map 'YYYY-MM' => postes
     */
    private function monthlyDetail(array $months): array
    {
        ksort($months);
        $out = [];
        foreach ($months as $ym => $m) {
            $out[] = [
                'ym'       => $ym,
                'ca'       => round($m['ca'], 2),
                'material' => $m['material'] !== null ? round($m['material'], 2) : null,
                'labour'   => $m['labour']   !== null ? round($m['labour'], 2)   : null,
                'overhead' => $m['overhead'] !== null ? round($m['overhead'], 2) : null,
                'net'      => $m['net'] !== null ? round($m['net'], 2) : null,
                'pct'      => ($m['net'] !== null && $m['ca'] > 0)
                    ? round($m['net'] / $m['ca'] * 100, 1) : null,
                // « postes » : recalculé ; « result » : lu tel quel faute des
                // trois postes. La distinction se voit dans le modal.
                'from'     => $m['from'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Marge nette ANNUELLE : résultat net cumulé ÷ CA cumulé, sur les seuls
     * mois dont les trois postes sont connus.
     *
     * Les mois incomplets sont écartés des DEUX termes : garder leur CA au
     * dénominateur pendant que leurs coûts manquent au numérateur gonflerait
     * la marge à proportion de ce qui manque.
     */
    private function annualMargin(array $months): ?array
    {
        $ca = $net = 0.0;
        $n  = 0;
        foreach ($months as $m) {
            if ($m['material'] === null || $m['labour'] === null || $m['overhead'] === null
                || $m['ca'] <= 0) {
                continue;
            }
            $ca  += $m['ca'];
            $net += $m['ca'] - $m['material'] - $m['labour'] - $m['overhead'];
            $n++;
        }
        if ($n === 0 || $ca <= 0) {
            return null;
        }
        return ['pct' => round($net / $ca * 100, 1), 'months' => $n, 'ca' => round($ca, 2)];
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
