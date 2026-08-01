<?php
namespace App\Consultant\app\Http\Controllers\Report;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Report\ReportShareRepository;
use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\app\Services\Report\ChecklistReportService;
use App\Consultant\app\Services\Report\ReportService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\core\Support\GlobalRegistry;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Partage d'un rapport mensuel par lien.
 *
 * Le rapport est FIGÉ au moment du partage. Ce n'est pas un raccourci : le
 * jeton d'API vit dans le cookie du consultant, et une page publique n'en a
 * pas. Recalculer à l'ouverture supposerait de donner au porteur du lien des
 * identifiants qui ouvrent tout le panel — ce qu'on ne fera pas pour afficher
 * un rapport.
 *
 * Le lien porte donc UN rapport, d'UNE boutique, d'UN mois, et rien d'autre.
 */
class ShareController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private ChecklistReportService $checklistReport,
        private ShopService $shopService,
        private ReportShareRepository $shares,
        private ParamService $params
    ) {}

    /**
     * POST /reports/share — fabrique le lien.
     *
     * Corps : { shop_id, ym }. Réponse : { url, expires_at, token }.
     */
    public function create(): JsonResponse
    {
        $data   = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
        $shopId = (int)($data['shop_id'] ?? 0);
        $ym     = (string)($data['ym'] ?? '');

        if ($shopId <= 0 || preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            return $this->json(['success' => false, 'error' => 'shop_id et ym (AAAA-MM) sont requis.'], 400);
        }
        // Un mois à venir n'a rien à partager, et un lien vers du vide se
        // retourne contre celui qui l'envoie.
        if ($ym > date('Y-m')) {
            return $this->json(['success' => false, 'error' => 'Ce mois n’est pas encore écoulé.'], 422);
        }

        $html = $this->figer($shopId, $ym);
        if ($html === null) {
            return $this->json(['success' => false,
                'error' => 'Le rapport n’a pas pu être construit : rien à figer.'], 502);
        }

        $user  = GlobalRegistry::get('user') ?? [];
        $jours = max(1, $this->params->getInt('report_share_days', 14));
        $token = $this->shares->create([
            'id_shop'         => $shopId,
            'ym'              => $ym,
            'label'           => $this->libelle($shopId, $ym),
            'html'            => $html,
            'id_consultant'   => (int)($user['membership_id'] ?? $user['id'] ?? 0),
            'consultant_name' => $user['display_name'] ?? null,
            'days'            => $jours,
        ]);

        if ($token === null) {
            return $this->json(['success' => false,
                'error' => 'Lien non enregistré : ' . ($this->shares->lastError ?: 'base indisponible')], 500);
        }

        return $this->json([
            'success'    => true,
            'token'      => $token,
            'url'        => $this->lien($token),
            'expires_at' => (new DateTimeImmutable('+' . $jours . ' days'))->format('Y-m-d'),
            'days'       => $jours,
        ], 200);
    }

    /** POST /reports/share/revoke — coupe un lien avant son terme. */
    public function revoke(): JsonResponse
    {
        $data  = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
        $token = (string)($data['token'] ?? '');
        $user  = GlobalRegistry::get('user') ?? [];

        $ok = $this->shares->revoke($token, (int)($user['membership_id'] ?? $user['id'] ?? 0));
        return $this->json($ok
            ? ['success' => true]
            : ['success' => false, 'error' => 'Lien introuvable, déjà révoqué, ou partagé par quelqu’un d’autre.'],
            $ok ? 200 : 422);
    }

    /** GET /reports/share — les liens du consultant, pour les suivre et les couper. */
    public function index(): void
    {
        $user = GlobalRegistry::get('user') ?? [];
        $this->view('report/shares', [
            'shares'     => $this->shares->forConsultant((int)($user['membership_id'] ?? $user['id'] ?? 0)),
            'base'       => $this->lien(''),
            'active_nav' => 'reports',
        ]);
    }

    /**
     * Le HTML des DEUX rapports du mois — gestion, puis checklist en dessous.
     *
     * On rend les blocs `head` et `content` des gabarits EXISTANTS : le lien
     * montre exactement ce que le consultant a sous les yeux, et il n'y a pas
     * deux chemins de rendu à garder d'accord.
     */
    private function figer(int $shopId, string $ym): ?string
    {
        $mois = new DateTimeImmutable($ym . '-01');

        // 1) Rapport de gestion mensuel, périmètre « une boutique ».
        $gestion = $this->safeFetch(
            [$this->reportService, 'generate'], $this->errors, ['month', (string)$shopId], []
        );
        // 2) Rapport Checklist mensuel, même boutique, même mois.
        $checklist = $this->safeFetch(
            [$this->checklistReport, 'month'], $this->errors, [$shopId, $ym], []
        );

        if (($gestion['shops'] ?? null) === null && ($checklist['totals'] ?? null) === null) {
            return null;   // les deux sources muettes : rien à figer
        }

        $a = $this->viewBlocks('report/view', ['head', 'content'], [
            'report' => $gestion, 'shared' => true,
        ]);
        $b = $this->viewBlocks('report/checklist_month', ['head', 'content', 'scripts'], [
            'report' => $checklist, 'shop_id' => $shopId, 'ym' => $ym,
            'prev_month' => null, 'next_month' => null, 'shared' => true,
        ]);

        // Les styles d'abord, les deux corps ensuite, le script d'impression en
        // fin — c'est lui qui déplie les non-conformités avant le PDF.
        return $a['head'] . $b['head']
            . '<div class="sa-rpt">' . $a['content'] . '</div>'
            . '<div class="sa-rpt sa-second">' . $b['content'] . '</div>'
            . $b['scripts'];
    }

    private function libelle(int $shopId, string $ym): string
    {
        $nom = '#' . $shopId;
        foreach ($this->shopService->getAllShops() as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) {
                $nom = (string)($s['representative_name'] ?? $s['name'] ?? $nom);
                break;
            }
        }
        return $nom . ' · ' . (new DateTimeImmutable($ym . '-01'))->format('m/Y');
    }

    private function lien(string $token): string
    {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $hote  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $hote . ROOT . '/r/' . $token;
    }
}
