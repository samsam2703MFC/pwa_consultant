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

        // Leviers à travailler d'après le rapport du mois précédent (best-effort).
        $suggested = $shopId > 0
            ? $this->safeFetch(fn() => $this->suggestedLevers($shopId), $this->errors, null, [])
            : [];

        $this->view('agenda/create', [
            'shops'      => $shops,
            'shop'       => $shop,
            'shop_id'    => $shopId,
            'date'       => $date,
            'levers'     => AgendaService::LEVERS,
            'suggested'  => $suggested,
            'active_nav' => 'agenda',
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
            $visitId = $this->agenda->createVisit([
                'id_consultant'   => $this->consultantId(),
                'consultant_name' => (string)($user['display_name'] ?? ''),
                'id_shop'         => $shopId,
                'shop_name'       => $shopName,
                'scheduled_at'    => $date . ' ' . $time . ':00',
                'duration_min'    => (int)($r->get('duration') ?: 60),
                'goal'            => trim((string)$r->get('goal')) ?: null,
                'status'          => 'planned',
                'report_ref'      => trim((string)$r->get('report_ref')) ?: null,
                'shared'          => $r->get('shared') ? 1 : 0,
            ]);

            // Actions à travailler par levier : champs action_<key>.
            if ($visitId > 0) {
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
        }

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

    /** Leviers faibles (⚠/●) d'après le rapport mensuel de la boutique. */
    private function suggestedLevers(int $shopId): array
    {
        $report = $this->reportService->generate('month', (string)$shopId);
        $levers = $report['shops'][0]['hexm']['levers'] ?? [];
        $out = [];
        foreach ($levers as $lev) {
            if (in_array($lev['status'] ?? '', ['bad', 'mid'], true)) {
                $out[] = ['key' => $lev['key'], 'letter' => $lev['letter'] ?? '', 'name' => $lev['name'] ?? '', 'status' => $lev['status']];
            }
        }
        return $out;
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
