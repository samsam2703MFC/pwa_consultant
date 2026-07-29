<?php
namespace App\Consultant\app\Services\Trends;

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
        private ShopMetricTargetService $targetService
    ) {}

    public function build(): array
    {
        $ids = [];
        foreach ($this->shopService->getAllShops() as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $today    = new \DateTimeImmutable('today');
        $curStart = $today->modify('first day of this month');

        // 12 mois glissants, du plus ancien au mois courant.
        $months = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $start   = $curStart->modify("-{$i} months");
            $end     = $start->modify('last day of this month');
            $partial = $end > $today;
            if ($partial) {
                $end = $today;
            }
            $pStart = $start->modify('-1 year');
            $pEnd   = $partial
                ? $pStart->modify('+' . $start->diff($end)->days . ' days')
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
            ];
        }

        // Ventes N et N-1 — toutes fenêtres en parallèle.
        $windows = [];
        foreach ($months as $m) {
            foreach ($ids as $id) {
                $windows[] = ['shop' => $id, 'from' => $m['from'],   'to' => $m['to']];
                $windows[] = ['shop' => $id, 'from' => $m['p_from'], 'to' => $m['p_to']];
            }
        }
        $kpis = $this->shopService->getSalesKpisBatch($windows);

        // Objectifs CA — tous les couples (boutique, mois) en parallèle.
        $treqs = [];
        foreach ($months as $m) {
            foreach ($ids as $id) {
                $treqs[] = ['shop' => $id, 'year' => $m['year'], 'month' => $m['month']];
            }
        }
        $targets = $this->targetService->getTargetsMany($treqs);

        $rows = [];
        foreach ($months as $m) {
            $caN = 0.0;
            $caN1 = 0.0;
            $obj = null;
            foreach ($ids as $id) {
                $kn  = $kpis["{$id}|{$m['from']}|{$m['to']}"] ?? null;
                $kn1 = $kpis["{$id}|{$m['p_from']}|{$m['p_to']}"] ?? null;
                $caN  += (float)($kn['ca'] ?? 0);
                $caN1 += (float)($kn1['ca'] ?? 0);
                $t = $targets["{$id}|{$m['year']}|{$m['month']}"] ?? [];
                $o = $this->caObjective(is_array($t) ? $t : []);
                if ($o !== null) {
                    $obj = ($obj ?? 0.0) + $o;
                }
            }
            $rows[] = [
                'ym'       => $m['ym'],
                'partial'  => $m['partial'],
                'ca_n'     => round($caN, 2),
                'ca_n1'    => round($caN1, 2),
                'objectif' => $obj !== null ? round($obj, 2) : null,
                'evo_pct'  => $caN1 > 0 ? round(($caN - $caN1) / $caN1 * 100, 1) : null,
                'obj_pct'  => ($obj !== null && $obj > 0.0) ? round($caN / $obj * 100, 1) : null,
            ];
        }

        // Top 3 des meilleurs mois (sur la fenêtre de 12 mois).
        $sorted = array_values(array_filter($rows, fn($r) => $r['ca_n'] > 0));
        usort($sorted, fn($a, $b) => $b['ca_n'] <=> $a['ca_n']);
        $top3 = array_map(
            fn($r) => ['ym' => $r['ym'], 'ca' => $r['ca_n'], 'evo_pct' => $r['evo_pct'], 'partial' => $r['partial']],
            array_slice($sorted, 0, 3)
        );

        return [
            'months'      => $rows,
            'top3'        => $top3,
            'shops_count' => count($ids),
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
