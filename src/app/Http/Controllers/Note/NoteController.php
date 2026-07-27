<?php
namespace App\Consultant\app\Http\Controllers\Note;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\core\Support\GlobalRegistry;

class NoteController extends Controller
{
    public function __construct(
        private NoteService $noteService,
        private ShopService $shopService,
        private ConsultantUserRepository $consultantUsers
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

        // ── DIAGNOSTIC TEMPORAIRE (?dbg=1) ──────────────────────────────────
        // Expose la forme RÉELLE renvoyée par l'API pour les notes récentes :
        // valeur de la miniature (thumb) + structure des commentaires et de
        // leurs pièces jointes. Ne concerne que les propres notes de l'usager
        // connecté. À RETIRER une fois le format des pièces jointes confirmé.
        if (($_GET['dbg'] ?? '') === '1') {
            $ids = [];
            foreach ($overview['recent'] as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $payload = [
                'recent' => array_map(fn($r) => [
                    'id'      => $r['id'] ?? null,
                    'shop'    => $r['shop_name'] ?? null,
                    'thumb'   => $r['thumb'] ?? null,
                    'content' => mb_substr((string)($r['content'] ?? ''), 0, 40),
                ], $overview['recent']),
                'structures' => $ids !== [] ? $this->noteService->debugNoteStructures($ids) : [],
            ];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        // ── FIN DIAGNOSTIC TEMPORAIRE ───────────────────────────────────────

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

        $user = GlobalRegistry::get('user');

        $this->view('note/detail', [
            'note'       => $note,
            'comments'   => $note['comments'] ?? [],
            'shop_id'    => $note['shop_id'] ?? null,
            'me_name'    => (string)($user['display_name'] ?? ''),
            'me_role'    => $this->currentUserRole(),
            'dbg'        => ($_GET['dbg'] ?? '') === '1', // DIAG TEMP
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
        $dbg = ($_GET['dbg'] ?? '') === '1'; // DIAG TEMP

        if (empty(trim($_POST['content'] ?? '')) && !$this->hasUploadedPhoto()) {
            redirect("/notes/{$noteId}");
        }

        $files = [];
        if (!empty($_FILES['photos'])) {
            $files['photos'] = $_FILES['photos'];
        }

        $result = $this->noteService->addComment($noteId, $_POST, $files);

        // ── DIAGNOSTIC TEMPORAIRE (?dbg=1) : tout le trajet d'un upload photo ──
        // Ce que le navigateur a envoyé + la réponse de l'API + l'état des
        // commentaires après coup. Révèle si l'upload arrive et sous quelle
        // forme l'API renvoie les photos. À RETIRER ensuite.
        if ($dbg) {
            $note = $this->noteService->getNote($noteId);
            $comments = array_map(
                fn($c) => is_array($c) ? [
                    'id'        => $c['id'] ?? null,
                    'content'   => $c['content'] ?? null,
                    'photos'    => $c['photos'] ?? null,
                    'photo_ids' => $c['photo_ids'] ?? null,
                ] : $c,
                $note['comments'] ?? []
            );
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'files_received'      => $this->debugFilesSummary($_FILES['photos'] ?? null),
                'upload_result'       => $result,
                'note_comments_after' => $comments,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        // ── FIN DIAGNOSTIC TEMPORAIRE ─────────────────────────────────────────

        redirect("/notes/{$noteId}");
    }

    /** DIAG TEMP — résumé des fichiers reçus dans $_FILES['photos']. */
    private function debugFilesSummary($photos): array
    {
        if (!is_array($photos)) {
            return ['present' => false];
        }
        $names  = (array)($photos['name'] ?? []);
        $types  = (array)($photos['type'] ?? []);
        $errors = (array)($photos['error'] ?? []);
        $sizes  = (array)($photos['size'] ?? []);
        $files  = [];
        foreach ($names as $i => $n) {
            $files[] = [
                'name'  => $n,
                'type'  => $types[$i] ?? null,
                'error' => $errors[$i] ?? null,
                'size'  => $sizes[$i] ?? null,
            ];
        }
        return ['present' => true, 'count' => count($files), 'files' => $files];
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
