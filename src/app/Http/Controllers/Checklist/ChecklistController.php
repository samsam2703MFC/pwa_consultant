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
}
