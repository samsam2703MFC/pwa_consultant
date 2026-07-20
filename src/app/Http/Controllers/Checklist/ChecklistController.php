<?php
namespace App\Consultant\app\Http\Controllers\Checklist;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Checklist\ChecklistService;

class ChecklistController extends Controller
{
    public function __construct(private ChecklistService $checklistService) {}

    public function index(): void
    {
        $date = $this->resolveDate();
        $data = $this->safeFetch(
            [$this->checklistService, 'getNetworkTasksRanking'],
            $this->errors,
            [$date],
            ['network' => [], 'shops' => []]
        );

        // Zawartość poglądowa gdy backend nie zwrócił danych (DEV_NO_AUTH / brak API).
        if (empty($data['network']) && empty($data['shops'])) {
            $data = $this->demoRanking();
        }

        $this->view('checklist/index', [
            'data'          => $data,
            'selected_date' => $date,
            'date'          => $date,
            'today'         => date('Y-m-d'),
            'active_nav'    => 'checklists',
        ]);
    }

    public function shopTasks(int $shopId): void
    {
        $date = $this->resolveDate();
        $data = $this->safeFetch(
            [$this->checklistService, 'getShopTaskDetails'],
            $this->errors,
            [$shopId, $date],
            ['summary' => [], 'tasks' => [], 'trend' => []]
        );

        $this->view('checklist/shop_tasks', [
            'data'       => $data,
            'date'       => $date,
            'shop_id'    => $shopId,
            'id_shop'    => $shopId,
            'today'      => date('Y-m-d'),
            'active_nav' => 'checklists',
        ]);
    }

    public function submitReview(): void
    {
        header('Content-Type: application/json');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw  = file_get_contents('php://input');
            $data = json_decode($raw, true) ?? [];
        } else {
            $data = $_POST;
        }

        $shopId = isset($data['shop_id']) ? (int)$data['shop_id'] : 0;

        if (!$shopId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'shop_id is required']);
            exit;
        }

        unset($data['shop_id']);

        $result = $this->checklistService->submitTaskReview($shopId, $data);

        if ($result['success'] ?? false) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $result['description'] ?? $result['error'] ?? 'Błąd zapisu oceny']);
        }
        exit;
    }

    private function resolveDate(): string
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            return date('Y-m-d');
        }

        return $date;
    }

    /**
     * Zawartość poglądowa odwzorowująca makietę „Checklists".
     */
    private function demoRanking(): array
    {
        return [
            'network' => [
                'completion_rate' => 78,
                'tasks_done'      => 124,
                'tasks_total'     => 159,
                'tasks_skipped'   => 7,
                'tasks_failed'    => 10,
                'shops_closed'    => 1,
                'shops_total'     => 3,
            ],
            'shops' => [
                ['shop_id' => 1, 'shop_name' => 'Châtelain', 'shop_city' => 'Bruxelles', 'completion_rate' => 92, 'tasks_done' => 37, 'tasks_total' => 40, 'tasks_skipped' => 1, 'tasks_failed' => 2, 'mandatory_missed' => 0, 'day_closed' => true],
                ['shop_id' => 2, 'shop_name' => 'Flagey',    'shop_city' => 'Ixelles',   'completion_rate' => 80, 'tasks_done' => 32, 'tasks_total' => 42, 'tasks_skipped' => 2, 'tasks_failed' => 3, 'mandatory_missed' => 0, 'day_closed' => false],
                ['shop_id' => 3, 'shop_name' => 'Sablon',    'shop_city' => 'Bruxelles', 'completion_rate' => 64, 'tasks_done' => 25, 'tasks_total' => 39, 'tasks_skipped' => 6, 'tasks_failed' => 8, 'mandatory_missed' => 2, 'day_closed' => false],
            ],
        ];
    }
}
