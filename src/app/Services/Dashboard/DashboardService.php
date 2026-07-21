<?php
namespace App\Consultant\app\Services\Dashboard;

use App\Consultant\app\Services\Checklist\ChecklistService;
use App\Consultant\app\Services\Shop\ShopService;
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
        private ShopService $shopService,
    ) {}

    /**
     * Magasins actifs pour le tableau « état au moment T » de l'accueil —
     * chargés dynamiquement (mêmes source et filtre actif que le module
     * Boutiques), rien de codé en dur. Les chiffres temps réel sont ensuite
     * récupérés côté navigateur, magasin par magasin, via le proxy.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function getLiveShops(): array
    {
        $out = [];
        foreach ($this->shopService->getAllShops() as $shop) {
            $id = (int)($shop['id'] ?? 0);
            $name = trim((string)($shop['representative_name'] ?? $shop['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }
        return $out;
    }

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
        $kpis   = $this->buildKpis($net, $tasks, $alerts['total']);

        // „CA du jour" — agregacja P&L wszystkich sklepów (turnover.value).
        $ca = $this->computeCaToday();
        if ($ca['value'] !== null) {
            $kpis['ca_today']    = $ca['value'];
            $kpis['ca_delta']    = $ca['delta'];
            $kpis['ca_delta_up'] = $ca['up'];
        }

        return [
            'kpis'        => $kpis,
            'today_tasks' => $this->buildTasks($tasks),
            'alerts'      => $alerts['items'],
            'shops'       => $this->buildShops($shops),
        ];
    }

    /**
     * Sumuje dzienny obrót (turnover.value) po wszystkich sklepach konsultanta
     * i wyznacza łączną zmianę procentową odtwarzając poprzedni okres z delty
     * każdego sklepu: prev = value / (1 + delta/100).
     * Endpoint: /consultant/shops/{id}/pnl?period=day (po jednym na sklep).
     */
    private function computeCaToday(): array
    {
        $shops    = $this->shopService->getAllShops();
        $totalCA  = 0.0;
        $totalPrev = 0.0;
        $hasValue = false;
        $hasDelta = false;

        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId === 0) {
                continue;
            }

            $pnl = $this->shopService->getPnl($shopId, 'day');
            $val = $pnl['turnover']['value'] ?? null;
            if ($val === null) {
                continue;
            }

            $hasValue = true;
            $totalCA += (float)$val;

            $delta = $pnl['turnover']['delta'] ?? null;
            if ($delta !== null && (1 + $delta / 100) != 0.0) {
                $totalPrev += (float)$val / (1 + $delta / 100);
                $hasDelta = true;
            } else {
                $totalPrev += (float)$val;
            }
        }

        if (!$hasValue) {
            return ['value' => null, 'delta' => null, 'up' => true];
        }

        $value = number_format($totalCA, 0, ',', ' ') . ' €';
        $delta = null;
        $up    = true;
        if ($hasDelta && $totalPrev > 0) {
            $pct   = ($totalCA - $totalPrev) / $totalPrev * 100;
            $delta = sprintf('%+d%%', (int)round($pct));
            $up    = $pct >= 0;
        }

        return ['value' => $value, 'delta' => $delta, 'up' => $up];
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
