<?php
namespace App\Consultant\app\Services\Dashboard;

use App\Consultant\app\Repositories\Dashboard\DashboardRepository;

/**
 * Normalizuje surową odpowiedź API pulpitu do kontraktu oczekiwanego
 * przez szablon dashboard.twig:
 *
 *   [
 *     'kpis'        => ['ca_today','ca_delta','ca_delta_up','checklist_pct',
 *                       'checklist_ratio','tasks_done','tasks_total','alerts_count'],
 *     'today_tasks' => [ ['title','shop','time','done'], ... ],
 *     'alerts'      => [ ['type','title','subtitle','href'], ... ],
 *     'shops'       => [ ['name','city','pct'], ... ],
 *   ]
 *
 * Zwraca [] gdy API nie dostarczyło danych — wtedy kontroler zostawia
 * dane demonstracyjne (tryb DEV_NO_AUTH / brak backendu).
 */
class DashboardService
{
    public function __construct(private DashboardRepository $dashboardRepository) {}

    public function getDashboard(string $date): array
    {
        $raw = $this->dashboardRepository->getSummary($date);
        if (empty($raw)) {
            return [];
        }

        return [
            'kpis'        => $this->normalizeKpis($raw['kpis'] ?? $raw),
            'today_tasks' => $this->normalizeTasks($raw['today_tasks'] ?? $raw['tasks'] ?? []),
            'alerts'      => $this->normalizeAlerts($raw['alerts'] ?? []),
            'shops'       => $this->normalizeShops($raw['shops'] ?? []),
        ];
    }

    private function normalizeKpis(array $k): array
    {
        return [
            'ca_today'        => $k['ca_today']        ?? $k['revenue_today'] ?? null,
            'ca_delta'        => $k['ca_delta']        ?? $k['revenue_delta'] ?? null,
            'ca_delta_up'     => (bool)($k['ca_delta_up'] ?? (($k['revenue_delta_value'] ?? 0) >= 0)),
            'checklist_pct'   => $k['checklist_pct']   ?? null,
            'checklist_ratio' => $k['checklist_ratio'] ?? null,
            'tasks_done'      => $k['tasks_done']      ?? null,
            'tasks_total'     => $k['tasks_total']     ?? null,
            'alerts_count'    => $k['alerts_count']    ?? null,
        ];
    }

    private function normalizeTasks(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $out[] = [
                'title' => $t['title'] ?? $t['name'] ?? '',
                'shop'  => $t['shop']  ?? $t['shop_name'] ?? $t['representative_name'] ?? '',
                'time'  => $t['time']  ?? $t['due_time'] ?? '',
                'done'  => (bool)($t['done'] ?? ($t['status'] ?? '') === 'done'),
            ];
        }
        return $out;
    }

    private function normalizeAlerts(array $alerts): array
    {
        $out = [];
        foreach ($alerts as $a) {
            $out[] = [
                'type'     => $a['type']     ?? 'warning',
                'title'    => $a['title']    ?? '',
                'subtitle' => $a['subtitle'] ?? $a['detail'] ?? '',
                'href'     => $a['href']     ?? $a['link'] ?? null,
            ];
        }
        return $out;
    }

    private function normalizeShops(array $shops): array
    {
        $out = [];
        foreach ($shops as $s) {
            $out[] = [
                'name' => $s['name'] ?? $s['representative_name'] ?? '',
                'city' => $s['city'] ?? '',
                'pct'  => (int)($s['pct'] ?? $s['score'] ?? $s['margin_pct'] ?? 0),
            ];
        }
        return $out;
    }
}
