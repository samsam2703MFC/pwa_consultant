<?php
namespace App\Consultant\app\Services\Trends;

use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Target\ShopMetricTargetService;

/**
 * Section Tendances — chiffre d'affaires CONSOLIDÉ du réseau sur les
 * 12 derniers mois : N, N-1 et objectifs, + top 3 des meilleurs mois.
 *
 *   - N / N-1 : somme des CA de toutes les boutiques actives, mois par mois
 *     (fenêtres N-1 tronquées comme N pour le mois courant partiel — la
 *     comparaison reste honnête).
 *   - Objectif : somme des objectifs CA ENCODÉS par boutique — overrides
 *     consultant/admin uniquement, jamais les valeurs franchiseur par défaut
 *     (mêmes règles que l'écran /targets et que les rapports).
 *
 * Tout est récupéré en parallèle (curl_multi) : la page charge les données
 * à la demande via /trends/data.
 */
class TrendsService
{
    private const MONTHS = 12;

    public function __construct(
        private ShopService $shopService,
        private ShopMetricTargetService $targetService,
        private ?ParamService $params = null
    ) {}

    public function build(): array
    {
        // Budget de temps (mac_consultant_param). Le CA consolidé est le cœur de
        // l'écran ; les objectifs sont un complément. Passé le budget, on rend
        // les CA sans les objectifs plutôt que de laisser la passerelle couper
        // la réponse et afficher une page vide.
        $t0     = microtime(true);
        $budget = max(1, $this->params?->getInt('trends_budget_seconds', 30) ?? 30);

        // Une seule entrée par boutique : additionner deux fois la même ligne
        // gonflerait le CA du réseau d'autant. getAllShops() dédoublonne déjà,
        // mais ce total est le chiffre de référence du réseau — il ne doit
        // dépendre d'aucune hypothèse sur ce que renvoie l'API.
        $ids   = [];
        $names = [];
        foreach ($this->shopService->getAllShops() as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0 && !isset($names[$id])) {
                $ids[]       = $id;
                $names[$id]  = trim((string)($s['representative_name'] ?? $s['name'] ?? '')) ?: ('#' . $id);
            }
        }

        $today    = new \DateTimeImmutable('today');
        $curStart = $today->modify('first day of this month');

        // Le mois en cours s'arrête au dernier jour COMPLET. La journée
        // d'aujourd'hui n'est qu'à moitié écoulée : la compter face à des
        // journées pleines de l'an dernier sous-estime systématiquement le mois
        // en cours. Réglable (mac_consultant_param) pour les réseaux dont
        // l'API remonte le jour courant en temps réel.
        $countToday = ($this->params?->getInt('trends_count_today', 0) ?? 0) === 1;
        $lastFull   = $countToday ? $today : $today->modify('-1 day');

        // 12 mois glissants, du plus ancien au mois courant.
        $months = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $start   = $curStart->modify("-{$i} months");
            $end     = $start->modify('last day of this month');
            $partial = $end > $today;
            if ($partial) {
                $end = $lastFull;
            }
            // Le 1er du mois (sans le jour courant), le mois en cours n'a encore
            // aucune journée complète : rien à mesurer, rien à comparer.
            $empty = $partial && $end < $start;
            $days  = $empty ? 0 : (int)$start->diff($end)->days + 1;

            $pStart = $start->modify('-1 year');
            // La fenêtre N-1 couvre EXACTEMENT le même nombre de jours que N.
            $pEnd = $partial
                ? $pStart->modify('+' . max(0, $days - 1) . ' days')
                : $pStart->modify('last day of this month');
            $months[] = [
                'ym'      => $start->format('Y-m'),
                'year'    => (int)$start->format('Y'),
                'month'   => (int)$start->format('n'),
                'from'    => $start->format('Y-m-d'),
                'to'      => $end->format('Y-m-d'),
                'p_from'  => $pStart->format('Y-m-d'),
                'p_to'    => $pEnd->format('Y-m-d'),
                'partial' => $partial,
                'empty'   => $empty,
                'days'    => $days,
            ];
        }

        // P1 — série mensuelle de TOUTES les boutiques : la fenêtre N et son
        // équivalent N-1 demandées EN PARALLÈLE (un aller-retour au lieu de
        // deux). Remplace ~300 appels.
        $rN  = [(string)$months[0]['ym'], (string)$months[count($months) - 1]['ym']];
        $rN1 = [
            date('Y-m', (int)strtotime($months[0]['from'] . ' -1 year')),
            date('Y-m', (int)strtotime($months[count($months) - 1]['from'] . ' -1 year')),
        ];
        $series  = $this->shopService->getMonthlySalesRanges([$rN, $rN1]);
        $serie   = $series[$rN[0] . '|' . $rN[1]] ?? null;
        $serieN1 = $series[$rN1[0] . '|' . $rN1[1]] ?? null;

        // Fenêtres à interroger précisément :
        //  - endpoint absent → TOUS les mois (repli) ;
        //  - endpoint présent → seulement les mois PARTIELS : la série
        //    mensuelle donnerait un N tronqué face à un N-1 complet, ce qui
        //    faussserait l'évolution. On tronque les deux de la même façon.
        $windows = [];
        foreach ($months as $m) {
            if ($m['empty'] || ($serie !== null && !$m['partial'])) {
                continue;
            }
            foreach ($ids as $id) {
                $windows[] = ['shop' => $id, 'from' => $m['from'],   'to' => $m['to']];
                $windows[] = ['shop' => $id, 'from' => $m['p_from'], 'to' => $m['p_to']];
            }
        }
        $kpis = $windows !== [] ? $this->shopService->getSalesKpisBatch($windows) : [];

        // Objectifs CA — tous les couples (boutique, mois) en parallèle.
        $targets     = [];
        $objSkipped  = false;
        if ($ids !== [] && (microtime(true) - $t0) < $budget) {
            $treqs = [];
            foreach ($months as $m) {
                foreach ($ids as $id) {
                    $treqs[] = ['shop' => $id, 'year' => $m['year'], 'month' => $m['month']];
                }
            }
            // Échéance : au-delà, le lot s'arrête et rend des objectifs
            // partiels — mieux que de laisser la passerelle couper la page.
            $targets = $this->targetService->getTargetsMany($treqs, $t0 + $budget);
            // Échéance franchie pendant le lot → objectifs possiblement
            // partiels. Un lot terminé DANS le budget est complet, même s'il
            // ne renvoie rien : là, « — » veut bien dire « rien d'encodé ».
            $objSkipped = (microtime(true) - $t0) >= $budget;
        } elseif ($ids !== []) {
            $objSkipped = true;
        }

        $rows     = [];
        $mtdCheck = null;
        $perShop  = [];
        foreach ($months as $m) {
            $caN = 0.0;
            $caN1 = 0.0;
            $obj = null;
            $ymN1 = date('Y-m', (int)strtotime($m['from'] . ' -1 year'));
            // Mois complet + série disponible → lecture directe ; mois partiel
            // → fenêtres tronquées (comparaison honnête).
            $useSerie = $serie !== null && !$m['partial'];
            foreach ($ids as $id) {
                if ($useSerie) {
                    // P1 : lecture directe de la série mensuelle.
                    $one   = (float)($serie[$id][$m['ym']]['ca'] ?? 0);
                    $caN  += $one;
                    $caN1 += (float)(($serieN1[$id][$ymN1]['ca'] ?? $serie[$id][$ymN1]['ca'] ?? 0));
                } else {
                    $kn  = $kpis["{$id}|{$m['from']}|{$m['to']}"] ?? null;
                    $kn1 = $kpis["{$id}|{$m['p_from']}|{$m['p_to']}"] ?? null;
                    $one  = (float)($kn['ca'] ?? 0);
                    $caN += $one;
                    $caN1 += (float)($kn1['ca'] ?? 0);
                }
                // Détail du mois en cours, boutique par boutique : un total de
                // réseau qu'on ne peut pas décomposer ne se vérifie pas.
                if ($m['partial'] && !$m['empty']) {
                    $perShop[] = ['id' => $id, 'name' => $names[$id] ?? ('#' . $id), 'ca' => round($one, 2)];
                }
                $t = $targets["{$id}|{$m['year']}|{$m['month']}"] ?? [];
                $o = $this->caObjective(is_array($t) ? $t : []);
                if ($o !== null) {
                    $obj = ($obj ?? 0.0) + $o;
                }
            }
            // Contrôle de cohérence du mois en cours : sa valeur vient des
            // fenêtres tronquées (P3/local) alors que les onze autres viennent
            // de la série mensuelle (P1). Deux endpoints, donc deux définitions
            // possibles du « CA » — un écart passerait pour une envolée du mois
            // en cours. On le mesure et on le publie plutôt que de le taire.
            if ($m['partial'] && !$m['empty'] && $serie !== null) {
                $fromSerie = 0.0;
                foreach ($ids as $id) {
                    $fromSerie += (float)($serie[$id][$m['ym']]['ca'] ?? 0);
                }
                if ($fromSerie > 0 && $caN > 0) {
                    $mtdCheck = [
                        'ym'      => $m['ym'],
                        'window'  => round($caN, 2),
                        'serie'   => round($fromSerie, 2),
                        'gap_pct' => round(($caN - $fromSerie) / $fromSerie * 100, 1),
                    ];
                }
            }

            // 0 = aucune donnée (ni API ni base locale) → null, affiché « — »
            // plutôt qu'un faux « 0 € ».
            $rows[] = [
                'ym'       => $m['ym'],
                'partial'  => $m['partial'],
                'days'     => $m['days'],
                'to'       => $m['to'],
                'ca_n'     => $caN > 0 ? round($caN, 2) : null,
                'ca_n1'    => $caN1 > 0 ? round($caN1, 2) : null,
                'objectif' => $obj !== null ? round($obj, 2) : null,
                'evo_pct'  => ($caN > 0 && $caN1 > 0) ? round(($caN - $caN1) / $caN1 * 100, 1) : null,
                'obj_pct'  => ($caN > 0 && $obj !== null && $obj > 0.0) ? round($caN / $obj * 100, 1) : null,
            ];
        }

        // Top 3 des meilleurs mois — mois TERMINÉS uniquement. Le mois en cours
        // ne compte qu'une partie de ses journées : le classer face à des mois
        // entiers n'est pas une comparaison, et le voir arriver premier avec un
        // cumul incomplet est le meilleur moyen de faire douter du chiffre.
        $sorted = array_values(array_filter($rows, fn($r) => !$r['partial'] && ($r['ca_n'] ?? 0) > 0));
        usort($sorted, fn($a, $b) => $b['ca_n'] <=> $a['ca_n']);
        $top3 = array_map(
            fn($r) => ['ym' => $r['ym'], 'ca' => $r['ca_n'], 'evo_pct' => $r['evo_pct'], 'partial' => false],
            array_slice($sorted, 0, 3)
        );

        // Le mois en cours a sa propre ligne : cumul à date et comparaison à
        // périmètre égal (mêmes jours l'an dernier).
        $current = null;
        foreach ($rows as $r) {
            if ($r['partial']) {
                $current = $r;
            }
        }

        // Détail du mois en cours, de la plus grosse contribution à la plus
        // petite : c'est là qu'une boutique en double ou une valeur aberrante
        // se voit tout de suite.
        usort($perShop, fn($a, $b) => $b['ca'] <=> $a['ca']);

        return [
            'months'        => $rows,
            'top3'          => $top3,
            'current'       => $current,
            'current_shops' => $perShop,
            'shops_count'   => count($ids),
            // Objectifs abandonnés faute de temps : signalé pour que les « — »
            // de la colonne Objectif ne passent pas pour « aucun objectif encodé ».
            'obj_skipped' => $objSkipped,
            'mtd_check'   => $mtdCheck,
        ];
    }

    /**
     * Objectif CA d'une réponse /targets : première métrique dont la clé ou
     * le libellé évoque le chiffre d'affaires, meilleur seuil ENCODÉ
     * (consultant ?? admin — jamais les valeurs franchiseur par défaut).
     */
    private function caObjective(array $targets): ?float
    {
        foreach ($targets as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $hay = mb_strtolower((string)$key . ' ' . (string)($entry['label'] ?? ''));
            foreach (['revenue', 'turnover', 'chiffre', 'sales', 'obrot', 'sprzeda'] as $f) {
                if (mb_strpos($hay, $f) !== false) {
                    return $this->objectiveOfEntry($entry);
                }
            }
        }
        return null;
    }

    private function objectiveOfEntry(array $entry): ?float
    {
        if (isset($entry['consultant']) && is_array($entry['consultant'])) {
            $src = $entry['consultant'];
        } elseif (isset($entry['admin']) && is_array($entry['admin'])) {
            $src = $entry['admin'];
        } else {
            return null; // rien d'encodé → pas d'objectif (jamais le défaut franchiseur)
        }
        $vals = [];
        foreach ($src as $k => $v) {
            if (is_numeric($v) && preg_match('/threshold|seuil|t\d|target|objective|goal/i', (string)$k)) {
                $vals[] = (float)$v;
            }
        }
        if ($vals === []) {
            return null;
        }
        return !empty($entry['lower_is_better']) ? min($vals) : max($vals);
    }
}
