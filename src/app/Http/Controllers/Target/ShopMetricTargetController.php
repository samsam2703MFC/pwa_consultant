<?php
namespace App\Consultant\app\Http\Controllers\Target;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopRepository;
use App\Consultant\app\Services\Target\ShopMetricTargetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ShopMetricTargetController extends Controller
{
    public function __construct(
        private ShopMetricTargetService $targetService,
        private ShopRepository $shopRepository
    ) {}

    /**
     * GET /targets
     * Lista sklepów — wybór sklepu.
     */
    public function overview(): void
    {
        $shops = $this->shopRepository->getAllShops();
        $this->view('target/overview', [
            'shops'         => $shops,
            'active_nav'    => 'targets',
            'current_year'  => (int)date('Y'),
            'current_month' => (int)date('n'),
        ]);
    }

    /**
     * GET /targets/{shopId}?year=&month=
     * Formularz edycji targetów.
     */
    public function edit(int $shopId): void
    {
        $year  = (int)($_GET['year']  ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));

        $shops   = $this->shopRepository->getAllShops();
        $shop    = null;
        foreach ($shops as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) {
                $shop = $s;
                break;
            }
        }

        $metrics = $this->targetService->getMetricDefinitions();
        $targets = $this->targetService->getTargets($shopId, $year, $month);

        $this->view('target/edit', [
            'shop'          => $shop,
            'year'          => $year,
            'month'         => $month,
            'metrics'       => $metrics,
            'targets'       => $targets,
            'months'        => $this->getMonthNames(),
            'active_nav'    => 'targets',
        ]);
    }

    /**
     * POST /targets/{shopId}/save
     */
    public function save(int $shopId): JsonResponse
    {
        $request  = Request::createFromGlobals();
        $body     = $this->getJson($request);
        $user     = \App\Consultant\core\Support\GlobalRegistry::get('user');
        $authorId = (int)($user['id'] ?? 0);

        $year    = (int)($body['year']  ?? 0);
        $month   = (int)($body['month'] ?? 0);
        $targets = $body['targets']     ?? [];

        $res = $this->targetService->saveTargets($shopId, $year, $month, $authorId, $targets);
        return $this->json($res);
    }

    /**
     * POST /targets/{shopId}/copy
     */
    public function copy(int $shopId): JsonResponse
    {
        $request  = Request::createFromGlobals();
        $body     = $this->getJson($request);
        $user     = \App\Consultant\core\Support\GlobalRegistry::get('user');
        $authorId = (int)($user['id'] ?? 0);

        $year  = (int)($body['year']  ?? 0);
        $month = (int)($body['month'] ?? 0);

        $res = $this->targetService->copyFromPreviousMonth($shopId, $year, $month, $authorId);
        return $this->json($res);
    }

    private function getMonthNames(): array
    {
        return [
            1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
            5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
            9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
        ];
    }
}

