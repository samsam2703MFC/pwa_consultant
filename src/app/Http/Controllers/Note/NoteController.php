<?php
namespace App\Consultant\app\Http\Controllers\Note;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\app\Services\Ai\TextCorrectionService;
use App\Consultant\core\Support\GlobalRegistry;

class NoteController extends Controller
{
    public function __construct(
        private NoteService $noteService,
        private ShopService $shopService,
        private ConsultantUserRepository $consultantUsers,
        private TextCorrectionService $correction,
    ) {}

    /**
     * GET /notes
     * Point d'entrée global depuis la navbar : vue d'ensemble des notes.
     */
    public function index(): void
    {
        $shops    = $this->shopService->getAllShops();
        $overview = $this->safeFetch(
            [$this->noteService, 'getNotesOverview'],
            $this->errors,
            [$shops],
            ['recent' => [], 'by_shop' => []]
        );

        $this->view('note/index', [
            'shops'      => $shops,
            'recent'     => $overview['recent'],
            'by_shop'    => $overview['by_shop'],
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

        $this->view('note/list', [
            'notes'                => $notes,
            'note_types'           => $noteTypes,
            'employees_with_notes' => $this->noteService->getEmployeesWithNotes($shopId),
            'shop_id'              => $shopId,
            'active_nav'           => 'notes',
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
     * Formulaire de nouvelle note — UNIFIÉ.
     *
     * La cible (boutique + personne éventuelle) vient soit de l'URL
     * (/shops/{id}/notes/new, /shops/{id}/employees/{eid}/notes/new), soit,
     * depuis le formulaire neutre /notes/new, des champs shop_id/employee_id du
     * POST. L'URL a priorité ; sinon on lit le corps. Le formulaire affiche
     * toujours les sélecteurs Boutique (obligatoire) et Personne (optionnel),
     * de sorte que la cible n'est jamais implicite.
     *
     * Routes : GET|POST /notes/new · /shops/{shopId}/notes/new
     *          · /shops/{shopId}/employees/{employeeId}/notes/new
     */
    public function create(?int $shopId = null, ?int $employeeId = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Cible : priorité à l'URL, repli sur le corps du formulaire neutre.
            if ($shopId === null) {
                $s = (int)($_POST['shop_id'] ?? 0);
                $shopId = $s > 0 ? $s : null;
            }
            if ($employeeId === null) {
                $e = (int)($_POST['employee_id'] ?? 0);
                $employeeId = $e > 0 ? $e : null;
            }

            // Messages d'erreur traduits (fini le polonais en dur).
            $t = loadTranslations('page', GlobalRegistry::get('lang_code') ?: resolveAppLanguage(), 'note');

            if ($shopId === null) {
                $this->errors['shop'] = $t['shop_required'] ?? 'Choisissez une boutique.';
            }
            if (empty(trim($_POST['content'] ?? ''))) {
                $this->errors['content'] = $t['content_required'] ?? 'Le contenu de la note est obligatoire.';
            }

            if (empty($this->errors)) {
                // La note est créée en JSON (l'endpoint note n'accepte pas le
                // multipart). Les photos éventuelles sont attachées ENSUITE comme
                // commentaire — le seul endpoint qui gère les images —, ce qui
                // évite de casser la création de note quand une photo est jointe.
                $result = $employeeId !== null
                    ? $this->noteService->createEmployeeNote($shopId, $employeeId, $_POST)
                    : $this->noteService->createNote($shopId, $_POST);

                if ($result['success'] ?? false) {
                    $newId = (int)($result['inserted_id'] ?? 0);
                    if ($newId > 0 && $this->hasUploadedPhoto()) {
                        $this->noteService->addComment($newId, ['content' => '📷'], ['photos' => $_FILES['photos']]);
                    }
                    if ($employeeId !== null) {
                        redirect("/shops/{$shopId}/employees/{$employeeId}/notes");
                    }
                    redirect("/shops/{$shopId}/notes");
                }
                $this->errors['save'] = $result['description'] ?? ($t['save_error'] ?? "Erreur lors de l'enregistrement de la note.");
            }
        }

        // Rendu (GET, ou POST en erreur) : listes pour les sélecteurs.
        $employees = $shopId !== null ? $this->noteService->getEmployeesForShop($shopId) : [];

        $this->view('note/create', [
            'shops'       => $this->shopService->getAllShops(),
            'shop_id'     => $shopId,
            'employee_id' => $employeeId,
            'employee'    => ($shopId !== null && $employeeId !== null) ? $this->findEmployee($employees, $employeeId) : null,
            'employees'   => $employees,
            'note_types'  => $this->noteService->getNoteTypes(),
            'active_nav'  => 'notes',
            // Sans clé API, le bouton « Corriger » n'a rien à proposer : il
            // n'est pas affiché plutôt que d'échouer sous le doigt.
            'ai_correct'  => $this->correction->available(),
        ]);
    }

    /**
     * POST /notes/ai-correct — relecture du contenu saisi.
     *
     * Ne touche à AUCUNE note enregistrée : le texte corrigé revient au
     * navigateur, où le consultant le relit et peut annuler. La correction
     * est une proposition, pas une écriture.
     */
    public function aiCorrect(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        // Même garde que les autres appels internes : depuis la page, pas
        // depuis un lien collé dans une barre d'adresse.
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
        ) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $raw  = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : $_POST;

        return $this->json($this->correction->correct((string)($body['text'] ?? '')));
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

        $user = GlobalRegistry::get('user');

        $this->view('note/detail', [
            'note'       => $note,
            'comments'   => $note['comments'] ?? [],
            'shop_id'    => $note['shop_id'] ?? null,
            'me_name'    => (string)($user['display_name'] ?? ''),
            'me_role'    => $this->currentUserRole(),
            'active_nav' => 'notes',
        ]);
    }

    /**
     * Libellé du poste du consultant connecté (ex. « Consultant Stratégie »),
     * lu dans les tables de référence locales via le membership du JWT. Repli
     * sur le scope_type du jeton, puis sur « Consultant ». Sert à signer les
     * notes et commentaires de l'utilisateur (« Sam V. — Consultant Stratégie »).
     */
    private function currentUserRole(): string
    {
        $t       = loadTranslations('page', GlobalRegistry::get('lang_code') ?: resolveAppLanguage(), 'note');
        $default = $t['consultant'] ?? 'Consultant';
        $user    = GlobalRegistry::get('user');

        $membershipId = (int)($user['membership_id'] ?? 0);
        if ($membershipId > 0) {
            $position = $this->consultantUsers->getConsultantData($membershipId)['position'] ?? [];
            foreach (['name', 'title', 'label', 'position_name'] as $key) {
                foreach ($position as $col => $val) {
                    if (strcasecmp((string)$col, $key) === 0 && is_string($val) && trim($val) !== '') {
                        return trim($val);
                    }
                }
            }
        }

        $scope = trim((string)($user['scope_type'] ?? ''));
        return $scope !== '' ? $scope : $default;
    }

    /**
     * POST /notes/{id}/comments
     * Dodaje komentarz (z opcjonalnymi zdjeciami).
     */
    public function addComment(int $noteId): void
    {
        if (empty(trim($_POST['content'] ?? '')) && !$this->hasUploadedPhoto()) {
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

    /**
     * GET /notes/attachments/{id}/preview
     * Redirige vers l'URL présignée de la pièce jointe (les photos de note/
     * commentaire ne sont référencées que par leur id). Calqué sur les
     * réclamations. Sert de src aux <img> et vignettes.
     */
    public function previewAttachment(int $attachmentId): void
    {
        $url = $this->noteService->getAttachmentPreviewUrl($attachmentId);
        if (!$url) {
            $this->view('errors/404', ['active_nav' => 'notes']);
            return;
        }
        header('Location: ' . $url);
        exit;
    }

    /** Vrai si au moins un fichier a été réellement téléversé dans $_FILES['photos']. */
    private function hasUploadedPhoto(): bool
    {
        $p = $_FILES['photos'] ?? null;
        if (!is_array($p) || !isset($p['error'])) {
            return false;
        }
        foreach ((array)$p['error'] as $err) {
            if ((int)$err === UPLOAD_ERR_OK) {
                return true;
            }
        }
        return false;
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
