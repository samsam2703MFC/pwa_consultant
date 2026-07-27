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

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $j   = fn($v) => htmlspecialchars((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');

        // En-tête affiché EN PREMIER → la page montre toujours quelque chose,
        // même si un appel échoue ensuite (erreur capturée et affichée).
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Notes — diagnostic</title><style>'
           . 'body{font:13px/1.4 system-ui,sans-serif;padding:14px;max-width:960px;margin:auto;color:#222}'
           . 'h2{color:#8D1D2C}table{border-collapse:collapse;width:100%;margin:8px 0}'
           . 'td,th{border:1px solid #ccc;padding:6px;vertical-align:top;text-align:left}'
           . 'pre{white-space:pre-wrap;word-break:break-word;margin:0;font-size:11px;max-height:180px;overflow:auto}'
           . '.err{color:#b00;font-weight:700}.note{color:#888;font-size:12px}</style></head><body>';
        echo '<h2>Notes — diagnostic</h2>';

        try {
            $user = GlobalRegistry::get('user') ?? [];
            echo '<p><b>Utilisateur :</b> <code>' . $j(['id' => $user['id'] ?? null, 'membership_id' => $user['membership_id'] ?? null]) . '</code></p>';

            $shops = $this->shopService->getAllShops();
            echo '<p><b>Boutiques actives (getAllShops) :</b> ' . count($shops) . '</p>';

            // ── Test ALLER-RETOUR optionnel (?test=<shopId>) : crée une note de
            //    test via le MÊME chemin que le formulaire (createNote), puis
            //    relit immédiatement la liste et vérifie si elle réapparaît. ──
            $testShop = (int)($_GET['test'] ?? 0);
            if ($testShop > 0) {
                $content  = 'TEST DIAG ' . date('Y-m-d H:i:s');
                $postResp = $this->noteService->createNote($testShop, ['content' => $content]);
                $after    = $this->apiClient->get("/consultant/shops/{$testShop}/notes");
                $afterD   = is_array($after['data'] ?? null) ? $after['data'] : [];
                $found    = false;
                foreach ($afterD as $n) {
                    if (($n['content'] ?? '') === $content) { $found = true; break; }
                }
                echo '<div style="border:2px solid #8D1D2C;border-radius:10px;padding:12px;margin:12px 0">';
                echo '<h3 style="margin-top:0">🧪 Test aller-retour — boutique ' . $testShop . '</h3>';
                echo '<p>Contenu créé : <code>' . $esc($content) . '</code></p>';
                echo '<p><b>Réponse du POST (création) :</b></p><pre>' . $j($postResp) . '</pre>';
                echo '<p><b>Relecture immédiate (GET) :</b> ' . count($afterD) . ' notes · note de test retrouvée : '
                   . ($found ? '<b style="color:#0a0">OUI ✅ (le round-trip marche)</b>'
                             : '<b class="err">NON ❌ (créée mais non relue → backend)</b>') . '</p>';
                echo '</div>';
            }

            // ── Test note EMPLOYÉ (?testemp=<shopId>) : crée une note pour le 1er
            //    employé de la boutique, puis vérifie où elle réapparaît — liste
            //    de l'employé (/employees/{eid}/notes) vs liste de la boutique
            //    (/shops/{id}/notes, celle qu'utilise l'accueil). ──
            $testEmpShop = (int)($_GET['testemp'] ?? 0);
            if ($testEmpShop > 0) {
                echo '<div style="border:2px solid #8D1D2C;border-radius:10px;padding:12px;margin:12px 0">';
                echo '<h3 style="margin-top:0">🧪 Test note EMPLOYÉ — boutique ' . $testEmpShop . '</h3>';
                $emps = $this->noteService->getEmployeesForShop($testEmpShop);
                $emp  = $emps[0] ?? null;
                $eid  = $emp ? (int)($emp['id'] ?? 0) : 0;
                if ($eid <= 0) {
                    echo '<p class="err">Aucun employé renvoyé pour cette boutique (getEmployeesForShop vide).</p>';
                } else {
                    $elabel   = $esc($emp['display_name'] ?? $emp['employee_name'] ?? (trim((string)($emp['name'] ?? '') . ' ' . (string)($emp['surname'] ?? '')) ?: ('#' . $eid)));
                    $content  = 'TEST EMP ' . date('Y-m-d H:i:s');
                    $postResp = $this->noteService->createEmployeeNote($testEmpShop, $eid, ['content' => $content]);
                    $empData  = ($r = $this->apiClient->get("/consultant/shops/{$testEmpShop}/employees/{$eid}/notes")) && is_array($r['data'] ?? null) ? $r['data'] : [];
                    $shopData = ($r2 = $this->apiClient->get("/consultant/shops/{$testEmpShop}/notes")) && is_array($r2['data'] ?? null) ? $r2['data'] : [];
                    $inEmp = false;  foreach ($empData as $n)  { if (($n['content'] ?? '') === $content) { $inEmp = true;  break; } }
                    $inShop = false; foreach ($shopData as $n) { if (($n['content'] ?? '') === $content) { $inShop = true; break; } }
                    echo '<p>Employé : <b>' . $elabel . '</b> (id ' . $eid . ') · contenu : <code>' . $esc($content) . '</code></p>';
                    echo '<p><b>Réponse du POST :</b></p><pre>' . $j($postResp) . '</pre>';
                    echo '<p>Liste EMPLOYÉ (/employees/' . $eid . '/notes) : ' . count($empData) . ' notes · retrouvée : ' . ($inEmp ? '<b style="color:#0a0">OUI ✅</b>' : '<b class="err">NON ❌</b>') . '</p>';
                    echo '<p>Liste BOUTIQUE (/shops/' . $testEmpShop . '/notes, = accueil) : ' . count($shopData) . ' notes · retrouvée : ' . ($inShop ? '<b style="color:#0a0">OUI ✅</b>' : '<b class="err">NON ❌</b>') . '</p>';
                }
                echo '</div>';
            }

            $rows = '';
            foreach ($shops as $shop) {
                $id    = (int)($shop['id'] ?? 0);
                $name  = $esc($shop['representative_name'] ?? $shop['name'] ?? ('#' . $id));
                $resp  = $this->apiClient->get("/consultant/shops/{$id}/notes");
                $ok    = !empty($resp['success']);
                $data  = is_array($resp['data'] ?? null) ? $resp['data'] : [];
                $count = count($data);
                $http  = $ok ? '200 OK' : ('FAIL — ' . $esc(json_encode($resp['error'] ?? null)));
                $first = $count > 0 ? $j($data[0]) : '(vide)';
                $test  = '<a href="' . $esc(ROOT . '/notes/_diag?test=' . $id) . '">🧪 boutique</a><br>'
                       . '<a href="' . $esc(ROOT . '/notes/_diag?testemp=' . $id) . '">🧪 perso</a>';
                $rows .= "<tr><td>{$id}</td><td>{$name}</td><td>{$http}</td><td><b>{$count}</b></td><td>{$test}</td><td><pre>{$first}</pre></td></tr>";
            }
            echo '<h3>GET /consultant/shops/{id}/notes — réponse brute par boutique</h3>';
            echo '<p class="note">Clique « 🧪 test » sur une ligne : ça crée une note de test sur cette boutique puis vérifie si elle réapparaît.</p>';
            echo '<table><tr><th>id</th><th>boutique</th><th>HTTP</th><th>nb notes</th><th>test</th><th>1ʳᵉ note (brut)</th></tr>'
               . ($rows ?: '<tr><td colspan="6">(aucune boutique active)</td></tr>') . '</table>';

            $overview = $this->noteService->getNotesOverview($shops);
            echo '<h3>getNotesOverview() — ce que l\'accueil reçoit</h3>';
            echo '<p><b>recent :</b> ' . count($overview['recent'] ?? []) . ' &nbsp; · &nbsp; <b>by_shop :</b> ' . count($overview['by_shop'] ?? []) . '</p>';
            echo '<p>recent (échantillon) :</p><pre>' . $j($overview['recent'] ?? []) . '</pre>';
            echo '<p>by_shop :</p><pre>' . $j($overview['by_shop'] ?? []) . '</pre>';
        } catch (\Throwable $e) {
            echo '<p class="err">Erreur pendant le diagnostic :</p><pre class="err">'
               . $esc($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
        }

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
