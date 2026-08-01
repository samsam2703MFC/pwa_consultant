<?php
namespace App\Consultant\app\Http\Controllers\Dashboard;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Checklist\NetworkShortfallService;
use App\Consultant\app\Services\Dashboard\DashboardService;
use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\core\Support\Route;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private NetworkShortfallService $shortfall,
        private ParamService $params
    ) {}

    #[Route('GET', '/dashboard')]
    public function index(): void
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // Données réelles agrégées depuis les endpoints existants
        // (ranking + tâches). API indisponible / vide → [] et état vide.
        $dashboard = $this->safeFetch(
            [$this->dashboardService, 'getDashboard'],
            $this->errors,
            [$date],
            []
        );

        // Uniquement des données réelles de l'API. Sections vides → le
        // template affiche l'état vide (aucun contenu de démonstration).
        $kpis       = $dashboard['kpis']        ?? [];
        $todayTasks = $dashboard['today_tasks'] ?? [];
        $alerts     = $dashboard['alerts']      ?? [];
        $shops      = $dashboard['shops']       ?? [];

        // Magasins pour le tableau « état au moment T » (temps réel, côté JS).
        $liveShops = $this->safeFetch(
            [$this->dashboardService, 'getLiveShops'],
            $this->errors,
            [],
            []
        );

        // Ce qui n'a PAS été fait depuis le début du mois. Placé en tête de
        // l'accueil : c'est le travail du consultant, alors que la file de
        // validation affiche « 0 » les jours où rien n'a été fait.
        $shortfall = $this->params->getInt('dashboard_shortfall_enabled', 1) === 1
            ? $this->safeFetch([$this->shortfall, 'monthToDate'], $this->errors, [$date], [])
            : [];

        $this->view('dashboard/dashboard', [
            'date'        => $date,
            'shortfall'   => $shortfall,
            'active_nav'  => 'dashboard',
            'kpis'        => $kpis,
            'today_tasks' => $todayTasks,
            'alerts'      => $alerts,
            'shops'       => $shops,
            'live_shops'  => $liveShops,
            'is_demo'     => false,
        ]);
    }

    /**
     * Page de chargement post-login : petite animation aux couleurs du
     * design system + préchargement PARALLÈLE de tous les endpoints
     * (magasins, P&L par magasin, tâches, ranking checklists) via le proxy.
     * Chaque appel remplit le cache GET serveur de l'ApiClient → les écrans
     * suivants se rendent instantanément, sans spinner.
     *
     * NB : l'appel getLiveShops() ci-dessous préchauffe déjà lui-même
     * /consultant/shops et fournit les ids pour l'éventail d'appels JS.
     */
    public function loading(): void
    {
        $shops = $this->safeFetch(
            [$this->dashboardService, 'getLiveShops'],
            $this->errors,
            [],
            []
        );

        $this->view('dashboard/loading', [
            'warm_shop_ids' => array_map(fn($s) => (int)$s['id'], $shops),
            'today'         => date('Y-m-d'),
            'active_nav'    => 'dashboard',
        ]);
    }

}
