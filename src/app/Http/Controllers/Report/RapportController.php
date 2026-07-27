<?php
namespace App\Consultant\app\Http\Controllers\Report;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Report\ReportService;
use App\Consultant\app\Services\Shop\ShopService;

class RapportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private ShopService $shopService
    ) {}

    /**
     * GET /reports
     * Écran de choix : type (hebdo/mensuel) + périmètre (une boutique / toutes),
     * puis génération du rapport imprimable.
     */
    public function index(): void
    {
        $this->view('report/index', [
            'shops'      => $this->shopService->getAllShops(),
            'active_nav' => 'reports',
        ]);
    }

    /**
     * GET /reports/view?type=week|month&scope=all|{shopId}
     * Rapport imprimable (optimisé impression / « Enregistrer en PDF »).
     */
    public function view(): void
    {
        $type  = ($_GET['type'] ?? 'week') === 'month' ? 'month' : 'week';
        $scope = (string)($_GET['scope'] ?? 'all');

        $report = $this->safeFetch(
            [$this->reportService, 'generate'],
            $this->errors,
            [$type, $scope],
            []
        );

        $this->view('report/view', [
            'report'     => $report,
            'active_nav' => 'reports',
        ]);
    }
}
