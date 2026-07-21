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
     * Seuils du code couleur des targets franchisé, en % de l'objectif
     * (configurables) : vert ≥ GREEN · orange ≥ ORANGE · rouge en dessous.
     */
    private const STATUS_GREEN  = 95.0;
    private const STATUS_ORANGE = 80.0;

    /**
     * Les 3 SEULES targets affichées aux franchisés, mappées sur les
     * métriques encodées par le franchiseur (clé/libellé, fragments en
     * minuscules — tolérant aux variantes de nommage backend).
     */
    private const FRANCHISE_KPIS = [
        'b2b'     => ['b2b'],
        'clients' => ['client', 'ticket', 'trafic', 'traffic', 'visit'],
        'basket'  => ['basket', 'panier', 'koszyk'],
    ];

    /**
     * GET /targets
     * Vue franchisé : les 3 targets (B2B, clients magasin vs N-1, panier
     * moyen) en lignes « verdict d'abord », graphique barres N vs N-1 au
     * tap. L'encodage détaillé reste sur /targets/{shopId}.
     */
    public function overview(): void
    {
        $shops = $this->shopRepository->getAllShops();
        $this->view('target/overview', [
            'shops'         => $shops,
            'active_nav'    => 'targets',
            'current_year'  => (int)date('Y'),
            'current_month' => (int)date('n'),
            'thresholds'    => ['green' => self::STATUS_GREEN, 'orange' => self::STATUS_ORANGE],
        ]);
    }

    /**
     * GET /targets/{shopId}/franchise-data?year=&month=
     * Objectifs (et valeur encodée éventuelle) des 3 KPI franchisé pour un
     * mois : métriques + targets lus depuis l'API franchiseur, mappés par
     * fragments de clé/libellé. Objectif = meilleur seuil encodé
     * (max des thresholds, min si lower_is_better).
     */
    public function franchiseData(int $shopId): JsonResponse
    {
        $year  = (int)($_GET['year']  ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));

        $metrics = $this->targetService->getMetricDefinitions();
        $targets = $this->targetService->getTargets($shopId, $year, $month);

        $out = [];
        foreach (self::FRANCHISE_KPIS as $kpi => $frags) {
            $out[$kpi] = null;
            foreach ($metrics as $key => $m) {
                $hay = mb_strtolower((string)$key . ' ' . (string)($m['label'] ?? ''));
                foreach ($frags as $f) {
                    if (mb_strpos($hay, $f) === false) {
                        continue;
                    }
                    $t = $targets[$key]['consultant'] ?? $targets[$key]['admin'] ?? $targets[$key] ?? null;
                    $out[$kpi] = [
                        'metric_key' => (string)$key,
                        'label'      => (string)($m['label'] ?? $key),
                        'unit'       => (string)($m['unit'] ?? ''),
                        'lower'      => !empty($m['lower_is_better']),
                        'objective'  => $this->objectiveOf(is_array($t) ? $t : [], !empty($m['lower_is_better'])),
                        'value'      => $this->encodedValueOf(is_array($t) ? $t : []),
                    ];
                    break 2;
                }
            }
        }

        return $this->json(['ok' => true, 'data' => ['year' => $year, 'month' => $month, 'kpis' => $out]]);
    }

    /** Meilleur seuil encodé = l'objectif (schéma threshold_1..3 tolérant). */
    private function objectiveOf(array $t, bool $lower): ?float
    {
        $vals = [];
        foreach ($t as $k => $v) {
            if (is_numeric($v) && preg_match('/threshold|seuil|t\d|target|objective|goal/i', (string)$k)) {
                $vals[] = (float)$v;
            }
        }
        if ($vals === []) {
            return null;
        }
        return $lower ? min($vals) : max($vals);
    }

    /** Valeur réalisée éventuellement encodée (ex. B2B, hors POS). */
    private function encodedValueOf(array $t): ?float
    {
        foreach (['value', 'actual', 'current', 'result', 'realised', 'realized'] as $k) {
            if (isset($t[$k]) && is_numeric($t[$k])) {
                return (float)$t[$k];
            }
        }
        return null;
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

