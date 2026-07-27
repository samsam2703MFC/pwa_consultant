<?php
namespace App\Consultant\app\Services\Report;

use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Claim\ClaimService;
use App\Consultant\app\Services\Target\ShopMetricTargetService;
use App\Consultant\app\Services\Task\TaskService;
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
        private TaskService $taskService
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
        $scopeShops = $allShops;
        $scopeLabel = 'Toutes les boutiques';
        $scopeMode  = 'all';
        $scopeId    = null;
        if ($scope !== 'all' && ctype_digit($scope)) {
            $sid = (int)$scope;
            $one = array_values(array_filter($allShops, fn($s) => (int)($s['id'] ?? 0) === $sid));
            if ($one !== []) {
                $scopeShops = $one;
                $scopeMode  = 'shop';
                $scopeId    = $sid;
                $scopeLabel = (string)($one[0]['representative_name'] ?? $one[0]['name'] ?? ('#' . $sid));
            }
        }

        $fromT = strtotime($period['from'] . ' 00:00:00');
        $toT   = strtotime($period['to'] . ' 23:59:59');

        $shopSections = [];
        foreach ($scopeShops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }
            $shopName = (string)($shop['representative_name'] ?? $shop['name'] ?? ('#' . $shopId));

            $shopSections[] = [
                'id'                 => $shopId,
                'name'               => $shopName,
                'kpis'               => $this->shopService->getSalesKpis($shopId, $period['from'], $period['to']),
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
            'demandes'      => $this->demandesForPeriod($fromT, $toT),
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

    /** Demandes (Helpdesk) créées sur la période — niveau consultant. */
    private function demandesForPeriod(int $fromT, int $toT): array
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
            $out[] = $case;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $out;
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

    private function monthName(int $m): string
    {
        $names = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return $names[$m] ?? (string)$m;
    }
}
