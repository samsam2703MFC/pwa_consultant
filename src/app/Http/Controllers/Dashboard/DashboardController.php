<?php
namespace App\Consultant\app\Http\Controllers\Dashboard;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Dashboard\DashboardService;
use App\Consultant\core\Support\Route;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    #[Route('GET', '/dashboard')]
    public function index(): void
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // Realne dane agregowane z istniejących endpointów (ranking + zadania).
        // Gdy API jest nieosiągalne / brak danych → [] i używamy demo.
        $dashboard = $this->safeFetch(
            [$this->dashboardService, 'getDashboard'],
            $this->errors,
            [$date],
            []
        );

        // Tryb realny: wszystkie sekcje z API (puste sekcje szablon ukrywa).
        // Tryb demo: pełna zawartość poglądowa (makieta).
        if (!empty($dashboard)) {
            $kpis       = $dashboard['kpis'];
            $todayTasks = $dashboard['today_tasks'];
            $alerts     = $dashboard['alerts'];
            $shops      = $dashboard['shops'];
        } else {
            $demo       = $this->demoData();
            $kpis       = $demo['kpis'];
            $todayTasks = $demo['today_tasks'];
            $alerts     = $demo['alerts'];
            $shops      = $demo['shops'];
        }

        $this->view('dashboard/dashboard', [
            'date'        => $date,
            'active_nav'  => 'dashboard',
            'kpis'        => $kpis,
            'today_tasks' => $todayTasks,
            'alerts'      => $alerts,
            'shops'       => $shops,
            'is_demo'     => empty($dashboard),
        ]);
    }

    /**
     * Zawartość poglądowa odwzorowująca makietę „Panel consultant".
     */
    private function demoData(): array
    {
        return [
            'kpis' => [
                'ca_today'        => '4 820 €',
                'ca_delta'        => '+8%',
                'ca_delta_up'     => true,
                'checklist_pct'   => '78%',
                'checklist_ratio' => '124/159',
                'tasks_done'      => 2,
                'tasks_total'     => 5,
                'alerts_count'    => 3,
            ],
            'today_tasks' => [
                ['title' => 'Contrôle vitrine du matin', 'shop' => 'Châtelain', 'time' => '09:30', 'done' => true],
                ['title' => 'Brief équipe',              'shop' => 'Sablon',    'time' => '11:00', 'done' => false],
                ['title' => 'Inventaire matières',       'shop' => 'Flagey',    'time' => '14:00', 'done' => false],
            ],
            'alerts' => [
                ['type' => 'warning', 'title' => 'Checklist Sablon en retard', 'subtitle' => '64% à 11h — clôture à 18h',  'href' => '/checklists'],
                ['type' => 'claim',   'title' => 'Réclamation ouverte #2043',  'subtitle' => 'Châtelain — depuis 2 jours', 'href' => '/claims'],
                ['type' => 'margin',  'title' => 'Marge Flagey en baisse',     'subtitle' => '−3 pts sur la semaine',      'href' => '/shops'],
            ],
            'shops' => [
                ['name' => 'Châtelain', 'city' => 'Bruxelles', 'pct' => 92],
                ['name' => 'Flagey',    'city' => 'Ixelles',   'pct' => 80],
                ['name' => 'Sablon',    'city' => 'Bruxelles', 'pct' => 64],
            ],
        ];
    }
}
