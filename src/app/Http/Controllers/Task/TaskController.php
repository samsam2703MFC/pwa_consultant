<?php
namespace App\Consultant\app\Http\Controllers\Task;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Task\TodoTaskRepository;
use App\Consultant\app\Services\Task\TaskService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TaskController extends Controller
{
    public function __construct(
        private TaskService $taskService,
        private TodoTaskRepository $todoTasks,
    ) {}

    public function tasks(): void
    {
        $data = $this->safeFetch(
            [$this->taskService, 'getConsultantTasks'],
            $this->errors,
            [],
            ['position' => null, 'tasks' => []]
        );

        // Zawartość poglądowa gdy backend nie zwrócił danych (DEV_NO_AUTH / brak API).
        if (empty($data['tasks'])) {
            $data = $this->demoTasks();
        }

        $this->view('task/index', [
            'position'   => $data['position'] ?? null,
            'tasks'      => $data['tasks'] ?? [],
            'active_nav' => 'tasks',
        ]);
    }

    /**
     * Zawartość poglądowa odwzorowująca makietę „Tâches".
     */
    private function demoTasks(): array
    {
        return [
            'position' => ['position_name' => 'Poste consultant', 'level_name' => 'Quotidien'],
            'tasks' => [
                ['id' => 1, 'name' => 'Contrôle vitrine du matin', 'section_name' => 'Ouverture', 'subcategory_name' => 'Châtelain', 'description' => 'Vérifier la présentation et la propreté de la vitrine.', 'execution_time' => '09:30', 'is_done' => true, 'is_mandatory' => true, 'requires_photo' => true, 'priority' => 1, 'completion_id' => 101],
                ['id' => 2, 'name' => 'Brief équipe', 'section_name' => 'Management', 'subcategory_name' => 'Sablon', 'description' => "Réunion courte avec l'équipe du matin.", 'execution_time' => '11:00', 'is_done' => false, 'is_mandatory' => true, 'requires_photo' => false, 'priority' => 2, 'completion_id' => null],
                ['id' => 3, 'name' => 'Inventaire matières premières', 'section_name' => 'Stock', 'subcategory_name' => 'Flagey', 'description' => 'Compter farine, beurre et levure avant la commande.', 'execution_time' => '14:00', 'is_done' => false, 'is_mandatory' => true, 'requires_photo' => false, 'priority' => 1, 'completion_id' => null],
            ],
        ];
    }

    public function taskOverview(int $id): void
    {
        $data = $this->safeFetch(
            [$this->taskService, 'getConsultantTasks'],
            $this->errors,
            [],
            ['position' => null, 'tasks' => []]
        );

        $task = $this->taskService->filterById($id, $data['tasks'] ?? []);
        if (!$task) {
            $this->errors['task_not_found'] = 'Nie znaleziono zadania.';
        }

        $this->view('task/task_overview', [
            'task' => $task,
            'selected_date' => $data['date'] ?? date('Y-m-d'),
            // Tâches prédéfinies (table todo_task) : cochées dans le formulaire,
            // elles sont ajoutées à la note — plus de saisie libre obligatoire.
            'shop_tasks' => $this->todoTasks->getTasks(),
            'active_nav' => 'tasks',
        ]);
    }

    public function taskCompletionOverview(int $id): void
    {
        $completion = $this->safeFetch(
            [$this->taskService, 'getCompletion'],
            $this->errors,
            [$id],
            []
        );

        $presignedUrl = null;
        if (!empty($completion['attachment_id'])) {
            $presignedUrl = $this->safeFetch(
                [$this->taskService, 'getAttachmentUrl'],
                $this->errors,
                [(int)$completion['attachment_id']],
                []
            );
        }

        $this->view('task/task_overview_done', [
            'task_completion' => $completion,
            'presigned_url' => $presignedUrl,
            'active_nav' => 'tasks',
        ]);
    }

    public function markAsDoneTask(int $id): void
    {
        $data = $this->safeFetch(
            [$this->taskService, 'getConsultantTasks'],
            $this->errors,
            [],
            ['position' => null, 'tasks' => []]
        );

        $task = $this->taskService->filterById($id, $data['tasks'] ?? []);
        if (!$task) {
            $this->errors['task_not_found'] = 'Nie znaleziono zadania.';
            $this->view('task/task_overview', ['task' => null, 'active_nav' => 'tasks']);
            return;
        }

        $_POST['task_id'] = $_POST['task_id'] ?? $id;
        $_POST['status'] = $_POST['status'] ?? 'DONE';
        $_POST['scheduled_for_date'] = $_POST['scheduled_for_date'] ?? date('Y-m-d');
        $_POST['scheduled_time'] = $_POST['scheduled_time'] ?? ($task['execution_time'] ?? '00:00:00');

        $requiresPhoto = !empty($task['requires_photo']);
        if ($requiresPhoto && (empty($_FILES['photo']) || (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK))) {
            $this->errors['photo_upload'] = 'Zdjęcie jest wymagane.';
            $this->view('task/task_overview', [
                'task' => $task,
                'selected_date' => $_POST['scheduled_for_date'],
                'shop_tasks' => $this->todoTasks->getTasks(),
                'active_nav' => 'tasks',
            ]);
            return;
        }

        if (!empty($_FILES['photo']) && (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)) {
            $size = (int)($_FILES['photo']['size'] ?? 0);
            if ($size <= 0 || $size > 10 * 1024 * 1024) {
                $this->errors['photo_upload'] = 'Zdjęcie musi mieć maksymalnie 10MB.';
                $this->view('task/task_overview', [
                    'task' => $task,
                    'selected_date' => $_POST['scheduled_for_date'],
                    'shop_tasks' => $this->todoTasks->getTasks(),
                    'active_nav' => 'tasks',
                ]);
                return;
            }
        }

        $result = $this->taskService->markAsDone($id, $_POST, $_FILES ?? []);
        if (!empty($result['success'])) {
            redirect('/tasks');
        }

        $this->errors['task_complete_failed'] = $result['description'] ?? 'Nie udało się oznaczyć zadania jako zrealizowane.';
        $this->view('task/task_overview', [
            'task' => $task,
            'selected_date' => $_POST['scheduled_for_date'],
            'shop_tasks' => $this->todoTasks->getTasks(),
            'active_nav' => 'tasks',
        ]);
    }

    public function index(): void
    {
        $filters = [
            'q'        => trim((string)($_GET['q'] ?? '')),
            'area_key' => trim((string)($_GET['area_key'] ?? '')),
            'status'   => trim((string)($_GET['status'] ?? '')),
        ];

        $data = $this->safeFetch(
            [$this->taskService, 'getHelpdeskTasks'],
            $this->errors,
            [$filters],
            ['areas' => [], 'cases' => []]
        );

        $this->view('helpdesk/index', [
            'areas'      => $data['areas'] ?? [],
            'cases'      => $data['cases'] ?? [],
            'filters'    => $filters,
            'statuses'   => $this->statuses(),
            'active_nav' => 'helpdesk',
        ]);
    }

    public function details(int $id): JsonResponse
    {
        $data = $this->safeFetch([$this->taskService, 'getCaseDetails'], $this->errors, [$id], []);
        if (empty($data)) {
            return $this->json(['ok' => false, 'message' => 'Nie znaleziono zgłoszenia.'], 404);
        }
        return $this->json(['ok' => true, 'data' => $data]);
    }

    public function eligibleConsultants(int $id): JsonResponse
    {
        $data = $this->safeFetch([$this->taskService, 'getEligibleConsultants'], $this->errors, [$id], []);
        return $this->json(['ok' => true, 'data' => $data]);
    }

    public function meetings(int $id): JsonResponse
    {
        $data = $this->safeFetch([$this->taskService, 'getMeetings'], $this->errors, [$id], []);
        return $this->json(['ok' => true, 'data' => $data]);
    }

    public function attachmentUrl(int $id): JsonResponse
    {
        $data = $this->safeFetch([$this->taskService, 'getAttachmentUrl'], $this->errors, [$id], []);
        if (empty($data['url'] ?? null)) {
            return $this->json(['ok' => false, 'message' => 'Nie znaleziono załącznika.'], 404);
        }
        return $this->json(['ok' => true, 'data' => $data]);
    }

    public function updateStatus(int $id): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body = $this->getJson($request);
        $result = $this->taskService->updateCaseStatus($id, (string)($body['status'] ?? ''));
        return $this->resultResponse($result, 'Status zaktualizowany.');
    }

    public function assignConsultant(int $id): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body = $this->getJson($request);
        $consultantId = null;
        if (array_key_exists('consultant_id', $body) && $body['consultant_id'] !== '' && $body['consultant_id'] !== null) {
            $consultantId = (int)$body['consultant_id'];
        }
        $result = $this->taskService->assignConsultant($id, $consultantId);
        return $this->resultResponse($result, 'Konsultant zaktualizowany.');
    }

    public function assignMeeting(int $id): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body = $this->getJson($request);
        $meetingId = null;
        if (array_key_exists('id_meeting', $body) && $body['id_meeting'] !== '' && $body['id_meeting'] !== null) {
            $meetingId = (int)$body['id_meeting'];
        }
        $result = $this->taskService->assignMeeting($id, $meetingId);
        return $this->resultResponse($result, 'Spotkanie zaktualizowane.');
    }

    public function saveReply(int $id): JsonResponse
    {
        $request = Request::createFromGlobals();
        $body = $this->getJson($request);
        $reply = array_key_exists('admin_reply', $body) ? (string)($body['admin_reply'] ?? '') : '';
        $reply = trim($reply) === '' ? null : trim($reply);
        $result = $this->taskService->saveReply($id, $reply);
        return $this->resultResponse($result, 'Odpowiedź zapisana.');
    }

    private function resultResponse(array $result, string $successMessage): JsonResponse
    {
        $ok = (bool)($result['success'] ?? false);
        $status = (int)($result['code'] ?? ($ok ? 200 : 422));
        return $this->json([
            'ok' => $ok,
            'message' => $ok ? ($result['message'] ?? $successMessage) : ($result['description'] ?? 'Operacja nie powiodła się.'),
        ], $status);
    }

    private function statuses(): array
    {
        return [
            'NEW'         => 'Nowe',
            'IN_PROGRESS' => 'W trakcie',
            'RESOLVED'    => 'Rozwiązane',
            'CLOSED'      => 'Zamknięte',
            'CANCELLED'   => 'Anulowane',
        ];
    }
}
