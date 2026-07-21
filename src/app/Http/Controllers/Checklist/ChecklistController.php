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
