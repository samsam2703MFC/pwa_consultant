<?php
namespace App\Consultant\app\Services\Dashboard;

use App\Consultant\app\Services\Checklist\ChecklistService;
use App\Consultant\app\Services\Task\TaskService;

/**
 * Agreguje pulpit konsultanta z ISTNIEJĄCYCH endpointów API (bez potrzeby
 * dedykowanego /consultant/dashboard):
 *
 *   - /consultant/network/tasks/ranking?date=…  → KPI checklist + alerty + sklepy
 *   - /consultant/tasks                          → zadania konsultanta na dziś
 *
 * Zwraca kontrakt oczekiwany przez dashboard.twig:
 *   ['kpis','today_tasks','alerts','shops']
 *
 * Gdy oba źródła są puste (API nieosiągalne / brak danych) zwraca [],
 * a kontroler pozostaje przy danych demonstracyjnych.
 *
 * Uwaga: „CA du jour" (przychód) nie ma źródła w tych endpointach — dane
 * P&L pobierane są per-sklep osobnym zapytaniem. Dlatego ca_today = null
 * (szablon pokaże „—" w trybie realnym).
 */
class DashboardService
{
    /** Maks. liczba pozycji pokazywanych w sekcjach listowych. */
    private const MAX_TASKS  = 6;
    private const MAX_ALERTS = 4;

    public function __construct(
        private ChecklistService $checklistService,
        private TaskService $taskService,
    ) {}

    public function getDashboard(string $date): array
    {
        $ranking = $this->checklistService->getNetworkTasksRanking($date);
        $net     = $ranking['network'] ?? [];
        $shops   = $ranking['shops'] ?? [];

        $tasksData = $this->taskService->getConsultantTasks();
        $tasks     = $tasksData['tasks'] ?? [];

        // Brak jakichkolwiek danych → tryb demonstracyjny (fallback w kontrolerze).
        if (empty($net) && empty($shops) && empty($tasks)) {
            return [];
        }

        $alerts = $this->buildAlerts($shops);

        return [
            'kpis'        => $this->buildKpis($net, $tasks, $alerts['total']),
            'today_tasks' => $this->buildTasks($tasks),
            'alerts'      => $alerts['items'],
            'shops'       => $this->buildShops($shops),
        ];
    }

    private function buildKpis(array $net, array $tasks, int $alertsTotal): array
    {
        $done  = 0;
        foreach ($tasks as $t) {
            if (!empty($t['is_done'])) {
                $done++;
            }
        }
        $total = count($tasks);

        $rate         = $net['completion_rate'] ?? null;
        $checklistDone = $net['tasks_done']  ?? null;
        $checklistAll  = $net['tasks_total'] ?? null;

        return [
            // Brak endpointu przychodu — pozostaje puste w trybie realnym.
            'ca_today'        => null,
            'ca_delta'        => null,
            'ca_delta_up'     => true,
            'checklist_pct'   => $rate !== null ? round($rate) . '%' : null,
            'checklist_ratio' => ($checklistAll !== null) ? ($checklistDone ?? 0) . '/' . $checklistAll : null,
            'tasks_done'      => $done,
            'tasks_total'     => $total,
            'alerts_count'    => $alertsTotal,
        ];
    }

    private function buildTasks(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $out[] = [
                'title' => $t['name'] ?? '',
                'shop'  => $t['section_name'] ?? $t['category_name'] ?? '',
                'time'  => $t['execution_time'] ?? '',
                'done'  => (bool)($t['is_done'] ?? false),
            ];
            if (count($out) >= self::MAX_TASKS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Wyprowadza alerty z rankingu sklepów: brak obowiązkowych zadań,
     * niska realizacja checklisty, zadania nieudane. Jeden (najpoważniejszy)
     * alert na sklep. Zwraca również łączną liczbę wykrytych problemów.
     */
    private function buildAlerts(array $shops): array
    {
        $items = [];
        $total = 0;

        foreach ($shops as $s) {
            $name    = $s['shop_name'] ?? '';
            $city    = $s['shop_city'] ?? '';
            $rate    = $s['completion_rate'] ?? null;
            $missed  = (int)($s['mandatory_missed'] ?? 0);
            $failed  = (int)($s['tasks_failed'] ?? 0);
            $closed  = !empty($s['day_closed']);

            $alert = null;
            if ($missed > 0) {
                $alert = [
                    'type'     => 'warning',
                    'title'    => 'Tâches obligatoires manquées — ' . $name,
                    'subtitle' => $missed . ' obligatoire' . ($missed > 1 ? 's' : '') . ($city ? ' · ' . $city : ''),
                    'href'     => '/checklists',
                ];
            } elseif ($rate !== null && $rate < 60 && !$closed) {
                $alert = [
                    'type'     => 'warning',
                    'title'    => 'Checklist ' . $name . ' en retard',
                    'subtitle' => round($rate) . '% complété' . ($city ? ' · ' . $city : ''),
                    'href'     => '/checklists',
                ];
            } elseif ($failed > 0) {
                $alert = [
                    'type'     => 'margin',
                    'title'    => 'Tâches échouées — ' . $name,
                    'subtitle' => $failed . ' échec' . ($failed > 1 ? 's' : '') . ($city ? ' · ' . $city : ''),
                    'href'     => '/checklists',
                ];
            }

            if ($alert !== null) {
                $total++;
                if (count($items) < self::MAX_ALERTS) {
                    $items[] = $alert;
                }
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    private function buildShops(array $shops): array
    {
        $out = [];
        foreach ($shops as $s) {
            $out[] = [
                'name' => $s['shop_name'] ?? '',
                'city' => $s['shop_city'] ?? '',
                'pct'  => (int)round($s['completion_rate'] ?? 0),
            ];
        }
        // Najsłabsze sklepy na górze — najbardziej wymagające uwagi.
        usort($out, fn($a, $b) => $a['pct'] <=> $b['pct']);
        return $out;
    }
}
