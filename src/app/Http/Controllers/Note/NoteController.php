<?php
namespace App\Consultant\app\Http\Controllers\Note;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\core\Http\ApiClient;
use App\Consultant\core\Support\GlobalRegistry;

class NoteController extends Controller
{
    public function __construct(
        private NoteService $noteService,
        private ShopService $shopService,
        private ApiClient $apiClient
    ) {}

    /**
     * GET /notes/_diag — page de DIAGNOSTIC TEMPORAIRE.
     *
     * Affiche, pour l'utilisateur connecté, la réponse BRUTE de l'API notes
     * boutique par boutique (/consultant/shops/{id}/notes) + le résultat de
     * l'agrégat d'accueil (getNotesOverview). Permet de voir si le backend
     * renvoie les notes (→ bug d'affichage) ou pas (→ backend). À RETIRER
     * ensuite. Accès protégé par l'auth consultant (données de l'utilisateur).
     */
    public function diag(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        $user  = GlobalRegistry::get('user') ?? [];
        $shops = $this->shopService->getAllShops();

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $j   = fn($v) => htmlspecialchars((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($shops as $shop) {
            $id   = (int)($shop['id'] ?? 0);
            $name = $esc($shop['representative_name'] ?? $shop['name'] ?? ('#' . $id));

            $resp  = $this->apiClient->get("/consultant/shops/{$id}/notes");
            $ok    = !empty($resp['success']);
            $data  = is_array($resp['data'] ?? null) ? $resp['data'] : [];
            $count = count($data);
            $http  = $ok ? '200 OK' : ('FAIL — ' . $esc(json_encode($resp['error'] ?? null)));
            $first = $count > 0 ? $j($data[0]) : '(vide)';

            $rows .= "<tr><td>{$id}</td><td>{$name}</td><td>{$http}</td><td><b>{$count}</b></td><td><pre>{$first}</pre></td></tr>";
        }

        $overview   = $this->noteService->getNotesOverview($shops);
        $recentN    = count($overview['recent'] ?? []);
        $byShopN    = count($overview['by_shop'] ?? []);
        $recentDump = $j($overview['recent'] ?? []);
        $byShopDump = $j($overview['by_shop'] ?? []);
        $uid        = $j(['id' => $user['id'] ?? null, 'membership_id' => $user['membership_id'] ?? null]);

        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Notes — diagnostic</title><style>'
           . 'body{font:13px/1.4 system-ui,sans-serif;padding:14px;max-width:960px;margin:auto;color:#222}'
           . 'h2{color:#8D1D2C}table{border-collapse:collapse;width:100%;margin:8px 0}'
           . 'td,th{border:1px solid #ccc;padding:6px;vertical-align:top;text-align:left}'
           . 'pre{white-space:pre-wrap;word-break:break-word;margin:0;font-size:11px;max-height:180px;overflow:auto}'
           . '.note{color:#888;font-size:12px}</style></head><body>';
        echo '<h2>Notes — diagnostic</h2>';
        echo '<p><b>Utilisateur :</b> <code>' . $uid . '</code></p>';
        echo '<p><b>Boutiques actives (getAllShops) :</b> ' . count($shops) . '</p>';
        echo '<h3>GET /consultant/shops/{id}/notes — réponse brute par boutique</h3>';
        echo '<table><tr><th>id</th><th>boutique</th><th>HTTP</th><th>nb notes</th><th>1ʳᵉ note (brut)</th></tr>' . $rows . '</table>';
        echo '<h3>getNotesOverview() — ce que l\'accueil reçoit</h3>';
        echo '<p><b>recent :</b> ' . $recentN . ' &nbsp; · &nbsp; <b>by_shop :</b> ' . $byShopN . '</p>';
        echo '<p>recent (échantillon) :</p><pre>' . $recentDump . '</pre>';
        echo '<p>by_shop :</p><pre>' . $byShopDump . '</pre>';
        echo '<p class="note">Page temporaire de diagnostic — sera retirée après analyse.</p>';
        echo '</body></html>';
        exit;
    }

    /**
     * GET /notes
     * Globalny punkt wejscia z navbara: wybor sklepu z notatkami.
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

            if ($shopId === null) {
                $this->errors['shop'] = 'Choisissez une boutique.';
            }
            if (empty(trim($_POST['content'] ?? ''))) {
                $this->errors['content'] = 'Tresc notatki jest wymagana.';
            }

            if (empty($this->errors)) {
                $result = $employeeId !== null
                    ? $this->noteService->createEmployeeNote($shopId, $employeeId, $_POST)
                    : $this->noteService->createNote($shopId, $_POST);

                if ($result['success'] ?? false) {
                    if ($employeeId !== null) {
                        redirect("/shops/{$shopId}/employees/{$employeeId}/notes");
                    }
                    redirect("/shops/{$shopId}/notes");
                }
                $this->errors['save'] = $result['description'] ?? 'Blad zapisu notatki.';
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
