<?php
namespace App\Consultant\app\Services\Report;

use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Claim\ClaimService;
use App\Consultant\app\Services\Target\ShopMetricTargetService;
use App\Consultant\app\Services\Task\TaskService;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\core\Support\GlobalRegistry;

/**
 * Agrégation des données pour les RAPPORTS hebdomadaire / mensuel.
 *
 * Un rapport couvre une période PASSÉE (semaine précédente ou mois précédent)
 * et un périmètre (une boutique OU toutes les boutiques du consultant). Il
 * réunit, à partir des services existants :
 *   - Performance (Hexm)         : CA, tickets, panier moyen, produits/ticket
 *   - Targets                    : les 6 leviers et leurs seuils du mois
 *                                  (image figée du mois précédent pour le mensuel)
 *   - Notes & photos             : notes de la période, avec miniatures
 *   - Réclamations fournisseurs  : groupées par fournisseur
 *   - Demandes (Helpdesk)        : ce qui a été demandé sur la période
 *   - Tâches réalisées           : réalisations de la période
 *
 * Tout est calculé côté serveur (aucune dépendance au JS des écrans).
 */
class ReportService
{
    /** Garde-fou : nombre max de complétions de tâches inspectées par rapport. */
    private const MAX_COMPLETIONS = 120;

    public function __construct(
        private ShopService $shopService,
        private NoteService $noteService,
        private ClaimService $claimService,
        private ShopMetricTargetService $targetService,
        private TaskService $taskService,
        private ConsultantUserRepository $consultantUsers
    ) {}

    /**
     * @param string $type  'week' | 'month'
     * @param string $scope 'all' | id de boutique (numérique)
     */
    public function generate(string $type, string $scope): array
    {
        $type   = $type === 'month' ? 'month' : 'week';
        $period = $this->computePeriod($type);
        $tgt    = $this->targetsMonth($type, $period);

        $allShops = $this->shopService->getAllShops();

        // Périmètre : une boutique précise ou toutes.
        $scopeShops     = $allShops;
        $scopeLabel     = 'Toutes les boutiques';
        $scopeMode      = 'all';
        $scopeId        = null;
        $scopeShopNames = [];
        if ($scope !== 'all' && ctype_digit($scope)) {
            $sid = (int)$scope;
            $one = array_values(array_filter($allShops, fn($s) => (int)($s['id'] ?? 0) === $sid));
            if ($one !== []) {
                $scopeShops = $one;
                $scopeMode  = 'shop';
                $scopeId    = $sid;
                $scopeLabel = (string)($one[0]['representative_name'] ?? $one[0]['name'] ?? ('#' . $sid));
                foreach ([$one[0]['representative_name'] ?? null, $one[0]['name'] ?? null] as $nm) {
                    $nm = mb_strtolower(trim((string)$nm));
                    if ($nm !== '') {
                        $scopeShopNames[] = $nm;
                    }
                }
            }
        }

        // Filtre boutique pour les demandes : appliqué uniquement quand une
        // boutique précise est choisie (sinon toutes les demandes).
        $shopFilter = $scopeMode === 'shop'
            ? ['id' => $scopeId, 'names' => $scopeShopNames]
            : null;

        $fromT = strtotime($period['from'] . ' 00:00:00');
        $toT   = strtotime($period['to'] . ' 23:59:59');

        // Tags OFFICIELS des leviers (of_tag) — mêmes couleurs/identité que l'écran HEXm.
        $ofTags = $this->consultantUsers->getOfficialTags();

        $shopSections = [];
        foreach ($scopeShops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }
            $shopName = (string)($shop['representative_name'] ?? $shop['name'] ?? ('#' . $shopId));

            $kpis = $this->shopService->getSalesKpis($shopId, $period['from'], $period['to']);

            $shopSections[] = [
                'id'                 => $shopId,
                'name'               => $shopName,
                'kpis'               => $kpis,
                'hexm'               => $this->hexmForShop($shopId, $period, $kpis, $ofTags),
                'targets'            => $this->targetService->getTargets($shopId, $tgt['year'], $tgt['month']),
                'notes'              => $this->noteService->getNotesForPeriod($shopId, $period['from'], $period['to']),
                'claims_by_supplier' => $this->claimsBySupplier($shopId, $fromT, $toT),
            ];
        }

        $user = GlobalRegistry::get('user') ?? [];

        return [
            'type'          => $type,
            'type_label'    => $type === 'month' ? 'Mensuel' : 'Hebdomadaire',
            'period'        => $period,
            'targets_month' => $tgt,
            'scope'         => ['mode' => $scopeMode, 'shop_id' => $scopeId, 'label' => $scopeLabel],
            'consultant'    => (string)($user['display_name'] ?? ''),
            'generated_at'  => date('Y-m-d H:i'),
            'metric_defs'   => $this->targetService->getMetricDefinitions(),
            'shops'         => $shopSections,
            'demandes'      => $this->demandesForPeriod($fromT, $toT, $shopFilter),
            'tasks_done'    => $this->tasksDoneForPeriod($fromT, $toT),
        ];
    }

    /** Fenêtre de la période : semaine précédente (lun→dim) ou mois précédent. */
    private function computePeriod(string $type): array
    {
        if ($type === 'month') {
            $firstThis = new \DateTimeImmutable('first day of this month 00:00:00');
            $from = $firstThis->modify('-1 month');
            $to   = $firstThis->modify('-1 day');
            $label = $this->monthName((int)$from->format('n')) . ' ' . $from->format('Y');
        } else {
            $mondayThis = (new \DateTimeImmutable('today'))->modify('monday this week');
            $from = $mondayThis->modify('-7 days');
            $to   = $mondayThis->modify('-1 day');
            $label = 'du ' . $from->format('d/m/Y') . ' au ' . $to->format('d/m/Y');
        }
        return [
            'from'  => $from->format('Y-m-d'),
            'to'    => $to->format('Y-m-d'),
            'label' => $label,
        ];
    }

    /**
     * Mois des targets : le mois précédent pour le rapport mensuel (image
     * figée demandée) ; le mois de la période pour l'hebdo.
     */
    private function targetsMonth(string $type, array $period): array
    {
        $ref = new \DateTimeImmutable(($type === 'month' ? $period['from'] : $period['to']));
        return [
            'year'  => (int)$ref->format('Y'),
            'month' => (int)$ref->format('n'),
            'label' => $this->monthName((int)$ref->format('n')) . ' ' . $ref->format('Y'),
        ];
    }

    /** Réclamations de la période, groupées par fournisseur. */
    private function claimsBySupplier(int $shopId, int $fromT, int $toT): array
    {
        $out = [];
        foreach ($this->claimService->getClaimsForShop($shopId) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $t = !empty($c['reported_at']) ? strtotime((string)$c['reported_at']) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            $supplier = trim((string)($c['supplier_name'] ?? '')) ?: 'Fournisseur inconnu';
            $out[$supplier][] = $c;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Demandes (Helpdesk) créées sur la période. Quand une boutique précise est
     * choisie ($shopFilter non null), on ne garde que ses demandes ; sinon
     * toutes. Le cas n'expose que `shop_name` : filtrage par id si présent,
     * sinon par nom normalisé (égalité ou inclusion pour tolérer les formes
     * courtes « Corbais » vs « Atelier by Berlo - Corbais »).
     *
     * @param array{id:?int, names:string[]}|null $shopFilter
     */
    private function demandesForPeriod(int $fromT, int $toT, ?array $shopFilter = null): array
    {
        $hd = $this->taskService->getHelpdeskTasks([]);
        $cases = is_array($hd['cases'] ?? null) ? $hd['cases'] : [];

        $out = [];
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }
            $t = !empty($case['created_at']) ? strtotime((string)$case['created_at']) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            if ($shopFilter !== null && !$this->caseMatchesShop($case, $shopFilter)) {
                continue;
            }
            $out[] = $case;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $out;
    }

    /**
     * Une demande appartient-elle à la boutique filtrée ? Par id si le cas en
     * porte un, sinon par nom (normalisé, égalité ou inclusion). Sans aucune
     * info boutique exploitable → exclue (le rapport est limité à cette boutique).
     *
     * @param array{id:?int, names:string[]} $filter
     */
    private function caseMatchesShop(array $case, array $filter): bool
    {
        foreach (['shop_id', 'id_shop', 'id_boutique', 'store_id'] as $k) {
            if (isset($case[$k]) && (int)$case[$k] > 0) {
                return (int)$case[$k] === (int)($filter['id'] ?? 0);
            }
        }

        $cn = mb_strtolower(trim((string)($case['shop_name'] ?? '')));
        if ($cn === '') {
            return false;
        }
        foreach ($filter['names'] as $nm) {
            if ($nm !== '' && ($cn === $nm || mb_strpos($cn, $nm) !== false || mb_strpos($nm, $cn) !== false)) {
                return true;
            }
        }
        return false;
    }

    /** Tâches réalisées (complétions) sur la période — niveau consultant. */
    private function tasksDoneForPeriod(int $fromT, int $toT): array
    {
        $data  = $this->taskService->getConsultantTasks();
        $tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];

        $out     = [];
        $checked = 0;
        foreach ($tasks as $task) {
            if (!is_array($task) || empty($task['is_done'])) {
                continue;
            }
            $completionId = (int)($task['completion_id'] ?? 0);
            if ($completionId <= 0 || $checked >= self::MAX_COMPLETIONS) {
                continue;
            }
            $checked++;

            $completion = $this->taskService->getCompletion($completionId);
            $done = $completion['completed_at'] ?? $completion['scheduled_for_date'] ?? null;
            $t = $done ? strtotime((string)$done) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            $out[] = [
                'name'         => $task['name'] ?? ($completion['task_name'] ?? ''),
                'category'     => $task['section_name'] ?? $task['category_name'] ?? null,
                'completed_at' => $completion['completed_at'] ?? $completion['scheduled_for_date'] ?? null,
                'by'           => $completion['completed_by_display_name'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => strcmp((string)($b['completed_at'] ?? ''), (string)($a['completed_at'] ?? '')));
        return $out;
    }

    /**
     * Résultat des 6 leviers HEXm d'un magasin sur la période — mêmes leviers,
     * numéros et couleurs que l'écran HEXm (couleur officielle of_tag par
     * correspondance de nom). Chaque levier porte ses KPI CALCULABLES côté
     * serveur pour une période passée (les autres restent « à venir », comme à
     * l'écran) :
     *   Trafic → tickets/jour · Récurrence → panier moyen ·
     *   Food Cost → coût matière % + marge brute % · Overhead → évolution CA vs N-1.
     *   (Labour et Expérience client : pas de source par période côté serveur.)
     *
     * @param array $kpis résultat de ShopService::getSalesKpis (période)
     */
    private function hexmForShop(int $shopId, array $period, array $kpis, array $ofTags): array
    {
        $ca      = (float)($kpis['ca'] ?? 0);
        $tickets = (int)($kpis['tickets'] ?? 0);
        $basket  = $kpis['avg_basket'] ?? null;

        $days       = max(1, (int)round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1);
        $ticketsDay = $tickets > 0 ? $tickets / $days : null;

        $material = $this->shopService->getMaterialCost($shopId, $period['from'], $period['to']);
        $foodPct  = ($material !== null && $ca > 0) ? ($material / $ca) * 100 : null;
        $grossPct = $foodPct !== null ? 100 - $foodPct : null;

        // Évolution CA vs N-1 : même période, un an plus tôt.
        $fromN1 = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1   = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));
        $caN1   = (float)($this->shopService->getSalesKpis($shopId, $fromN1, $toN1)['ca'] ?? 0);
        $evo    = $caN1 > 0 ? (($ca - $caN1) / $caN1) * 100 : null;

        $levers = [
            ['num' => 4, 'key' => 'trafic',     'name' => 'Trafic',            'color' => '#1F4F6B', 'kpis' => [
                ['label' => 'Trafic (tickets/jour)', 'value' => $ticketsDay !== null ? $this->fmtInt($ticketsDay) : null],
            ]],
            ['num' => 3, 'key' => 'recurrence', 'name' => 'Récurrence',        'color' => '#8a4a24', 'kpis' => [
                ['label' => 'Panier moyen', 'value' => $basket !== null ? $this->fmtEur((float)$basket) : null],
            ]],
            ['num' => 2, 'key' => 'xp',         'name' => 'Expérience client', 'color' => '#2D7A3E', 'kpis' => []],
            ['num' => 5, 'key' => 'food',       'name' => 'Food Cost',         'color' => '#C9A227', 'kpis' => [
                ['label' => 'Coût matière (% CA)', 'value' => $foodPct !== null ? $this->fmtPct($foodPct) : null],
                ['label' => 'Marge brute (% CA)',  'value' => $grossPct !== null ? $this->fmtPct($grossPct) : null],
            ]],
            ['num' => 6, 'key' => 'labour',     'name' => 'Labour Cost',       'color' => '#8D1D2C', 'kpis' => []],
            ['num' => 7, 'key' => 'overhead',   'name' => 'Overhead Cost',     'color' => '#7a7168', 'kpis' => [
                ['label' => 'Évolution CA vs N-1', 'value' => $evo !== null ? $this->fmtEvo($evo) : null],
            ]],
        ];

        // Couleur/nom officiels of_tag (correspondance de nom, comme l'écran HEXm).
        foreach ($levers as &$lev) {
            foreach ($ofTags as $tag) {
                $tn = strtolower(trim((string)($tag['name'] ?? '')));
                if ($tn === '' || empty($tag['color'])) {
                    continue;
                }
                if (strpos($tn, $lev['key']) !== false || strpos($tn, strtolower($lev['name'])) !== false) {
                    $lev['color']    = (string)$tag['color'];
                    $lev['tag_name'] = (string)$tag['name'];
                    break;
                }
            }
        }
        unset($lev);

        return ['ca' => $ca, 'tickets' => $tickets, 'levers' => $levers];
    }

    private function fmtInt(float $v): string
    {
        return number_format(round($v), 0, ',', ' ');
    }

    private function fmtEur(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }

    private function fmtPct(float $v): string
    {
        return str_replace('.', ',', (string)round($v, 1)) . ' %';
    }

    private function fmtEvo(float $v): string
    {
        return ($v > 0 ? '+' : '') . str_replace('.', ',', (string)round($v, 1)) . ' %';
    }

    private function monthName(int $m): string
    {
        $names = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return $names[$m] ?? (string)$m;
    }
}
