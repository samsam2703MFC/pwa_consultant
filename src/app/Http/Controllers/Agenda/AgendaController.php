<?php
namespace App\Consultant\app\Http\Controllers\Agenda;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Agenda\AgendaService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Report\ReportService;
use App\Consultant\core\Support\GlobalRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Agenda des visites franchisés du consultant : vue mois (type Google
 * Calendar), planification d'une visite (but + leviers à travailler +
 * invitation .ics / Google Agenda), et agenda partagé par boutique
 * (toutes les visites, tous consultants → anti-doublon).
 */
class AgendaController extends Controller
{
    public function __construct(
        private AgendaService $agenda,
        private ShopService $shopService,
        private ReportService $reportService
    ) {}

    /** GET /agenda?year=&month= — vue mois du consultant connecté. */
    public function index(): void
    {
        [$year, $month] = $this->reqYearMonth();
        $cid = $this->consultantId();

        $month_view = $this->safeFetch(
            fn() => $this->agenda->buildMonth($cid, $year, $month),
            $this->errors, null, ['weeks' => [], 'visits' => [], 'count' => 0, 'label' => '']
        );

        $this->view('agenda/index', [
            'month_view' => $month_view,
            'shops'      => $this->shopService->getAllShops(),
            'active_nav' => 'agenda',
        ]);
    }

    /** GET /agenda/new?shop_id=&date= — formulaire de planification. */
    public function newVisit(): void
    {
        $shopId = (int)($_GET['shop_id'] ?? 0);
        $date   = (string)($_GET['date'] ?? date('Y-m-d'));
        $shops  = $this->shopService->getAllShops();

        $shop = null;
        foreach ($shops as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) { $shop = $s; break; }
        }

        // Résultats des 6 leviers d'après le rapport du mois précédent (best-effort).
        $leverResults = $shopId > 0
            ? $this->safeFetch(fn() => $this->leverResults($shopId), $this->errors, null, [])
            : [];

        $this->view('agenda/create', [
            'shops'         => $shops,
            'shop'          => $shop,
            'shop_id'       => $shopId,
            'date'          => $date,
            'levers'        => AgendaService::LEVERS,
            'types'         => AgendaService::TYPES,
            'lever_results' => $leverResults,
            'active_nav'    => 'agenda',
        ]);
    }

    /** POST /agenda/visits — crée la visite (+ actions par levier). */
    public function store(): void
    {
        $r    = Request::createFromGlobals()->request;
        $user = GlobalRegistry::get('user') ?? [];

        $shopId = (int)$r->get('shop_id');
        $date   = trim((string)$r->get('date'));
        $time   = trim((string)$r->get('time')) ?: '10:00';
        $shops  = $this->shopService->getAllShops();
        $shopName = '';
        foreach ($shops as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) {
                $shopName = (string)($s['representative_name'] ?? $s['name'] ?? '');
                break;
            }
        }

        if ($shopId > 0 && $date !== '') {
            $type   = $this->validType((string)$r->get('type'));
            // Visite SURPRISE : jamais partagée au franchisé (pas d'envoi).
            $shared = ($type === 'surprise') ? 0 : ($r->get('shared') ? 1 : 0);

            $visitId = $this->agenda->createVisit([
                'id_consultant'   => $this->consultantId(),
                'consultant_name' => (string)($user['display_name'] ?? ''),
                'id_shop'         => $shopId,
                'shop_name'       => $shopName,
                'scheduled_at'    => $date . ' ' . $time . ':00',
                'duration_min'    => (int)($r->get('duration') ?: 60),
                'type'            => $type,
                'goal'            => trim((string)$r->get('goal')) ?: null,
                'status'          => 'planned',
                'report_ref'      => $this->reportLink($shopId),
                'shared'          => $shared,
            ]);

            if ($visitId > 0) {
                $this->saveLeverActions($visitId, $shopId, $r);
            }
        }

        $this->redirect('/agenda/shop/' . $shopId);
    }

    /** GET /agenda/visits/{id}/edit — édition d'une visite existante. */
    public function editVisit(int $id): void
    {
        $v = $this->agenda->getVisit($id);
        if ($v === null) {
            $this->redirect('/agenda');
            return;
        }
        $shopId = (int)($v['id_shop'] ?? 0);

        $existing = [];
        foreach ($this->agenda->leverActionsForVisit($id) as $a) {
            $existing[(string)$a['lever']] = (string)$a['action'];
        }

        $this->view('agenda/create', [
            'shops'       => $this->shopService->getAllShops(),
            'shop_id'     => $shopId,
            'date'        => $v['date'] ?? substr((string)$v['scheduled_at'], 0, 10),
            'levers'      => AgendaService::LEVERS,
            'types'       => AgendaService::TYPES,
            'lever_results' => $shopId > 0 ? $this->safeFetch(fn() => $this->leverResults($shopId), $this->errors, null, []) : [],
            'edit'        => $v,
            'existing'    => $existing,
            'active_nav'  => 'agenda',
        ]);
    }

    /** POST /agenda/visits/{id}/update — enregistre les modifications. */
    public function update(int $id): void
    {
        $r = Request::createFromGlobals()->request;
        $v = $this->agenda->getVisit($id);
        if ($v === null) {
            $this->redirect('/agenda');
            return;
        }
        $shopId = (int)$r->get('shop_id');
        $shopName = '';
        foreach ($this->shopService->getAllShops() as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) {
                $shopName = (string)($s['representative_name'] ?? $s['name'] ?? '');
                break;
            }
        }
        $type   = $this->validType((string)$r->get('type'));
        $shared = ($type === 'surprise') ? 0 : ($r->get('shared') ? 1 : 0);

        $this->agenda->updateVisit($id, [
            'id_shop'      => $shopId,
            'shop_name'    => $shopName,
            'scheduled_at' => trim((string)$r->get('date')) . ' ' . (trim((string)$r->get('time')) ?: '10:00') . ':00',
            'duration_min' => (int)($r->get('duration') ?: 60),
            'type'         => $type,
            'goal'         => trim((string)$r->get('goal')) ?: null,
            'report_ref'   => $this->reportLink($shopId),
            'shared'       => $shared,
        ]);

        // Réécrit les actions par levier.
        $this->agenda->replaceLeverActions($id);
        $this->saveLeverActions($id, $shopId, $r);

        $this->redirect('/agenda/shop/' . $shopId);
    }

    /** GET /agenda/shop/{id} — agenda partagé de la boutique (tous consultants). */
    public function shopAgenda(int $shopId): void
    {
        [$year, $month] = $this->reqYearMonth();
        $shops = $this->shopService->getAllShops();
        $shop  = null;
        foreach ($shops as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) { $shop = $s; break; }
        }

        $visits  = $this->safeFetch(fn() => $this->agenda->visitsForShopMonth($shopId, $year, $month), $this->errors, null, []);
        $actions = $this->safeFetch(fn() => $this->agenda->leverActionsForShop($shopId), $this->errors, null, []);

        // Actions groupées par levier (pour l'affichage).
        $byLever = [];
        foreach ($actions as $a) {
            $byLever[(string)$a['lever']][] = $a;
        }

        $this->view('agenda/shop', [
            'shop'            => $shop,
            'shop_id'         => $shopId,
            'visits'          => $visits,
            'levers'          => AgendaService::LEVERS,
            'actions_by_lever' => $byLever,
            'month_label'     => $this->agenda->buildMonth($this->consultantId(), $year, $month)['label'] ?? '',
            'active_nav'      => 'agenda',
        ]);
    }

    /** GET /agenda/visits/{id}/ics — invitation calendrier téléchargeable. */
    public function ics(int $id): void
    {
        $v = $this->agenda->getVisit($id);
        if ($v === null) {
            http_response_code(404);
            echo 'Visite introuvable';
            return;
        }
        $ics = $this->agenda->icsForVisit($v);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="visite-' . $id . '.ics"');
        echo $ics;
    }

    /** POST /agenda/visits/{id}/status — change le statut d'une visite. */
    public function setStatus(int $id): void
    {
        $status = (string)Request::createFromGlobals()->request->get('status', 'done');
        $v = $this->agenda->getVisit($id);
        if ($v !== null && in_array($status, ['planned', 'done', 'cancelled'], true)) {
            $this->agenda->updateVisitStatus($id, $status);
        }
        $this->redirect('/agenda/shop/' . (int)($v['id_shop'] ?? 0));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Résultats des 6 leviers HEXm d'après le rapport mensuel de la boutique. */
    private function leverResults(int $shopId): array
    {
        $report = $this->reportService->generate('month', (string)$shopId);
        $levers = $report['shops'][0]['hexm']['levers'] ?? [];
        $out = [];
        foreach ($levers as $lev) {
            $out[(string)$lev['key']] = [
                'key'    => $lev['key'],
                'letter' => $lev['letter'] ?? '',
                'name'   => $lev['name'] ?? '',
                'status' => $lev['status'] ?? 'nd',
            ];
        }
        return $out;
    }

    /** Type de visite valide (défaut : development). */
    private function validType(string $t): string
    {
        return array_key_exists($t, AgendaService::TYPES) ? $t : 'development';
    }

    /** Lien qui exécute le rapport mensuel de la boutique. */
    private function reportLink(int $shopId): string
    {
        return ROOT . '/reports/view?type=month&scope=' . $shopId;
    }

    /** Enregistre les actions par levier (champs action_<key>). */
    private function saveLeverActions(int $visitId, int $shopId, $r): void
    {
        foreach (AgendaService::LEVERS as $lev) {
            $txt = trim((string)$r->get('action_' . $lev['key']));
            if ($txt !== '') {
                $this->agenda->addLeverAction([
                    'id_shop'       => $shopId,
                    'id_visit'      => $visitId,
                    'id_consultant' => $this->consultantId(),
                    'lever'         => $lev['key'],
                    'action'        => $txt,
                ]);
            }
        }
    }

    /** @return array{0:int,1:int} année, mois (défaut : mois courant). */
    private function reqYearMonth(): array
    {
        $year  = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        if ($month < 1 || $month > 12) { $month = (int)date('n'); }
        if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }
        return [$year, $month];
    }

    private function consultantId(): int
    {
        $user = GlobalRegistry::get('user') ?? [];
        return (int)($user['membership_id'] ?? $user['id'] ?? 0);
    }

    private function redirect(string $path): void
    {
        header('Location: ' . ROOT . $path);
    }
}
