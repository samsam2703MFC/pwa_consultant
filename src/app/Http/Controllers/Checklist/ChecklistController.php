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
            'data'             => $data,
            'checklist_groups' => $this->groupTasksByChecklist($data['tasks'] ?? []),
            'date'             => $date,
            'shop_id'          => $shopId,
            'id_shop'          => $shopId,
            'today'            => date('Y-m-d'),
            'active_nav'       => 'checklists',
        ]);
    }

    /**
     * Regroupe les tâches du jour par checklist (champ checklist_name) pour
     * l'affichage en accordéon : chaque checklist se déplie sur ses tâches.
     * Tri : checklists par nom (les tâches sans checklist en dernier),
     * obligatoires en tête au sein d'un groupe. Chaque groupe porte ses
     * compteurs (faites / total, obligatoires en attente).
     *
     * @return array<int, array{name:string, tasks:array, total:int, done:int, mandatory_pending:int}>
     */
    private function groupTasksByChecklist(array $tasks): array
    {
        $groups = [];
        foreach ($tasks as $t) {
            $key = trim((string)($t['checklist_name'] ?? ''));
            $groups[$key]['name'] = $key;
            $groups[$key]['tasks'][] = $t;
        }

        uksort($groups, function (string $a, string $b): int {
            if ($a === '') return 1;   // « Sans checklist » toujours en dernier
            if ($b === '') return -1;
            return strnatcasecmp($a, $b);
        });

        foreach ($groups as &$g) {
            usort($g['tasks'], fn($x, $y) => empty($x['is_mandatory']) <=> empty($y['is_mandatory']));
            $g['total'] = count($g['tasks']);
            $g['done'] = count(array_filter($g['tasks'], fn($t) => ($t['status'] ?? '') === 'DONE'));
            $g['mandatory_pending'] = count(array_filter(
                $g['tasks'],
                fn($t) => !empty($t['is_mandatory']) && ($t['status'] ?? 'PENDING') !== 'DONE'
            ));
        }
        unset($g);

        return array_values($groups);
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
