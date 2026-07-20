<?php
namespace App\Consultant\app\Http\Controllers\Note;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Shop\ShopService;

class NoteController extends Controller
{
    public function __construct(
        private NoteService $noteService,
        private ShopService $shopService
    ) {}

    /**
     * GET /notes
     * Globalny punkt wejscia z navbara: wybor sklepu z notatkami.
     */
    public function index(): void
    {
        $shops = $this->shopService->getAllShops();

        $this->view('note/index', [
            'shops'      => $shops,
            'active_nav' => 'notes',
        ]);
    }

    /**
     * GET /shops/{shopId}/notes
     * Lista notatek dla wybranego sklepu.
     */
    public function listForShop(int $shopId): void
    {
        $notes     = $this->noteService->getNotesForShop($shopId);
        $noteTypes = $this->noteService->getNoteTypes();
        $employees = $this->noteService->getEmployeesForShop($shopId);

        $this->view('note/list', [
            'notes'      => $notes,
            'note_types' => $noteTypes,
            'employees'  => $employees,
            'shop_id'    => $shopId,
            'active_nav' => 'notes',
        ]);
    }

    /**
     * GET /shops/{shopId}/employees/{employeeId}/notes
     * Lista notatek dla pracownika sklepu.
     */
    public function listForEmployee(int $shopId, int $employeeId): void
    {
        $notes     = $this->noteService->getNotesForEmployee($shopId, $employeeId);
        $employees = $this->noteService->getEmployeesForShop($shopId);
        $employee  = $this->findEmployee($employees, $employeeId);

        $this->view('note/list', [
            'notes'       => $notes,
            'employees'   => $employees,
            'employee'    => $employee,
            'employee_id' => $employeeId,
            'shop_id'     => $shopId,
            'active_nav'  => 'notes',
        ]);
    }

    /**
     * GET  /shops/{shopId}/notes/new  - formularz nowej notatki sklepu
     * POST /shops/{shopId}/notes/new  - zapis
     */
    public function create(int $shopId): void
    {
        $noteTypes = $this->noteService->getNoteTypes();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty(trim($_POST['content'] ?? ''))) {
                $this->errors['content'] = 'Tresc notatki jest wymagana.';
            }

            if (empty($this->errors)) {
                $result = $this->noteService->createNote($shopId, $_POST);

                if ($result['success'] ?? false) {
                    redirect("/shops/{$shopId}/notes");
                }
                $this->errors['save'] = $result['description'] ?? 'Blad zapisu notatki.';
            }
        }

        $this->view('note/create', [
            'shop_id'    => $shopId,
            'note_types' => $noteTypes,
            'active_nav' => 'notes',
        ]);
    }

    /**
     * GET  /shops/{shopId}/employees/{employeeId}/notes/new
     * POST /shops/{shopId}/employees/{employeeId}/notes/new
     */
    public function createForEmployee(int $shopId, int $employeeId): void
    {
        $noteTypes = $this->noteService->getNoteTypes();
        $employees = $this->noteService->getEmployeesForShop($shopId);
        $employee  = $this->findEmployee($employees, $employeeId);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty(trim($_POST['content'] ?? ''))) {
                $this->errors['content'] = 'Tresc notatki jest wymagana.';
            }

            if (empty($this->errors)) {
                $result = $this->noteService->createEmployeeNote($shopId, $employeeId, $_POST);

                if ($result['success'] ?? false) {
                    redirect("/shops/{$shopId}/employees/{$employeeId}/notes");
                }
                $this->errors['save'] = $result['description'] ?? 'Blad zapisu notatki.';
            }
        }

        $this->view('note/create', [
            'shop_id'     => $shopId,
            'employee_id' => $employeeId,
            'employee'    => $employee,
            'note_types'  => $noteTypes,
            'active_nav'  => 'notes',
        ]);
    }

    /**
     * GET /notes/{id}
     * Szczegoly notatki z komentarzami.
     */
    public function detail(int $id): void
    {
        $note = $this->noteService->getNote($id);

        if (empty($note)) {
            $this->view('errors/404');
            return;
        }

        $this->view('note/detail', [
            'note'       => $note,
            'comments'   => $note['comments'] ?? [],
            'shop_id'    => $note['shop_id'] ?? null,
            'active_nav' => 'notes',
        ]);
    }

    /**
     * POST /notes/{id}/comments
     * Dodaje komentarz (z opcjonalnymi zdjeciami).
     */
    public function addComment(int $noteId): void
    {
        if (empty(trim($_POST['content'] ?? ''))) {
            redirect("/notes/{$noteId}");
        }

        $files = [];
        if (!empty($_FILES['photos'])) {
            $files['photos'] = $_FILES['photos'];
        }

        $this->noteService->addComment($noteId, $_POST, $files);
        redirect("/notes/{$noteId}");
    }

    /**
     * POST /notes/{id}/delete
     * Soft delete notatki.
     */
    public function deleteNote(int $id): void
    {
        $note       = $this->noteService->getNote($id);
        $shopId     = $note['shop_id'] ?? null;
        $employeeId = $note['employee_id'] ?? null;

        $this->noteService->deleteNote($id);

        if ($shopId && $employeeId) {
            redirect("/shops/{$shopId}/employees/{$employeeId}/notes");
        }
        if ($shopId) {
            redirect("/shops/{$shopId}/notes");
        }
        redirect('/notes');
    }

    /**
     * POST /comments/{id}/delete
     * Soft delete komentarza.
     */
    public function deleteComment(int $id): void
    {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $this->noteService->deleteComment($id);

        if ($noteId > 0) {
            redirect("/notes/{$noteId}");
        }
        redirect('/notes');
    }

    private function findEmployee(array $employees, int $employeeId): ?array
    {
        foreach ($employees as $employee) {
            if ((int)($employee['id'] ?? 0) === $employeeId) {
                return $employee;
            }
        }
        return null;
    }
}
