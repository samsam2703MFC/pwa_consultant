<?php
namespace App\Consultant\app\Http\Controllers\Agenda;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Agenda\AgendaService;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Report\ReportService;
use App\Consultant\app\Services\Checklist\ChecklistService;
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
        private ReportService $reportService,
        private ChecklistService $checklistService
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

        // Période de référence des leviers : mois précédent par défaut,
        // modifiable dans le formulaire (rechargement AJAX).
        $period = $this->validPeriod((string)($_GET['period'] ?? ''));
        $leverResults = $shopId > 0
            ? $this->safeFetch(fn() => $this->leverResults($shopId, $period), $this->errors, null, [])
            : [];

        $this->view('agenda/create', [
            'shops'         => $shops,
            'shop'          => $shop,
            'shop_id'       => $shopId,
            'date'          => $date,
            'levers'        => AgendaService::LEVERS,
            'types'         => AgendaService::TYPES,
            'lever_results' => $leverResults,
            'lever_period'  => $period,
            'checklists'    => $shopId > 0 ? $this->checklistsFor($shopId) : [],
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
                'id_checklist'    => (int)$r->get('id_checklist') ?: null,
                'checklist_name'  => trim((string)$r->get('checklist_name')) ?: null,
                'lever_period'    => $this->validPeriod((string)$r->get('lever_period')),
                // Rapport mensuel joint à l'envoi au franchisé (case à cocher).
                'send_report'     => $r->get('send_report') ? 1 : 0,
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
        if ($v === null || !$this->ownsVisit($v)) {
            $this->redirect('/agenda');
            return;
        }
        $shopId = (int)($v['id_shop'] ?? 0);

        $existing = [];
        foreach ($this->agenda->leverActionsForVisit($id) as $a) {
            $existing[(string)$a['lever']] = (string)$a['action'];
        }

        $period = $this->validPeriod((string)($_GET['period'] ?? ($v['lever_period'] ?? '')));

        $this->view('agenda/create', [
            'shops'       => $this->shopService->getAllShops(),
            'shop_id'     => $shopId,
            'date'        => $v['date'] ?? substr((string)$v['scheduled_at'], 0, 10),
            'levers'      => AgendaService::LEVERS,
            'types'       => AgendaService::TYPES,
            'lever_results' => $shopId > 0 ? $this->safeFetch(fn() => $this->leverResults($shopId, $period), $this->errors, null, []) : [],
            'lever_period' => $period,
            'checklists'  => $shopId > 0 ? $this->checklistsFor($shopId) : [],
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
        if ($v === null || !$this->ownsVisit($v)) {
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
            'id_checklist' => (int)$r->get('id_checklist') ?: null,
            'checklist_name' => trim((string)$r->get('checklist_name')) ?: null,
            'lever_period' => $this->validPeriod((string)$r->get('lever_period')),
            'send_report'  => $r->get('send_report') ? 1 : 0,
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
        $r      = Request::createFromGlobals()->request;
        $status = (string)$r->get('status', 'done');
        $v      = $this->agenda->getVisit($id);
        if ($v !== null && $this->ownsVisit($v) && in_array($status, ['planned', 'done', 'cancelled'], true)) {
            $this->agenda->updateVisitStatus($id, $status);
        }
        $back = (string)$r->get('back', '');
        $this->redirect($back === 'agenda' ? '/agenda' : '/agenda/shop/' . (int)($v['id_shop'] ?? 0));
    }

    /** POST /agenda/visits/{id}/delete — supprime définitivement la visite. */
    public function deleteVisit(int $id): void
    {
        $v = $this->agenda->getVisit($id);
        if ($v === null || !$this->ownsVisit($v)) {
            $this->redirect('/agenda');
            return;
        }
        $this->agenda->deleteVisit($id);
        $back = (string)Request::createFromGlobals()->request->get('back', '');
        $this->redirect($back === 'shop' ? '/agenda/shop/' . (int)($v['id_shop'] ?? 0) : '/agenda');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Résultats des 6 leviers HEXm d'après le rapport mensuel de la boutique. */
    private function leverResults(int $shopId, string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));
        $res = $this->reportService->leverStatusesForMonth($shopId, $y, $m);
        return $res['levers'] ?? [];
    }

    /**
     * Diagnostic du chargement des leviers (GET /agenda/levers?debug=1) :
     * chaque étape de la chaîne avec son résultat et sa durée, pour
     * identifier ce qui bloque (boutiques, ventes, food cost, statuts).
     */
    private function leversDiagnostic(int $shopId, string $period, float $t0): array
    {
        $out = ['debug' => true, 'shop_id' => $shopId, 'period' => $period, 'steps' => []];
        $step = function (string $name, callable $fn) use (&$out) {
            $t = microtime(true);
            try {
                $res = $fn();
                $out['steps'][] = ['step' => $name, 'ok' => true, 'result' => $res, 's' => round(microtime(true) - $t, 2)];
                return $res;
            } catch (\Throwable $e) {
                $out['steps'][] = [
                    'step'  => $name,
                    'ok'    => false,
                    'error' => get_class($e) . ' — ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
                    's'     => round(microtime(true) - $t, 2),
                ];
                return null;
            }
        };

        [$y, $m] = array_map('intval', explode('-', $period));
        $first = sprintf('%04d-%02d-01', $y, $m);
        $last  = date('Y-m-t', (int)strtotime($first));

        $step('shops', function () {
            $n = count($this->shopService->getAllShops());
            return $n . ' boutique(s) actives';
        });
        $step('sales_kpis_shop', function () use ($shopId, $first, $last) {
            $k = $this->shopService->getSalesKpis($shopId, $first, $last);
            return ['ca' => $k['ca'] ?? null, 'tickets' => $k['tickets'] ?? null];
        });
        $step('material_cost_shop', fn() => $this->shopService->getMaterialCost($shopId, $first, $last));
        $step('pnl_month', function () use ($shopId) {
            $p = $this->shopService->getPnl($shopId, 'month');
            return ['turnover' => $p['turnover']['value'] ?? null, 'labour' => $p['labour']['value'] ?? null,
                    'overhead' => $p['overhead']['value'] ?? null];
        });
        $levers = $step('lever_statuses', fn() => $this->leverResults($shopId, $period));
        $out['levers'] = is_array($levers) ? $levers : [];
        $out['ok'] = !empty($out['levers']);
        $out['elapsed_s'] = round(microtime(true) - $t0, 2);
        return $out;
    }

    /** Période 'YYYY-MM' valide ; défaut = mois précédent. */
    private function validPeriod(string $p): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $p, $mm)) {
            $y = (int)$mm[1];
            $m = (int)$mm[2];
            if ($y >= 2000 && $y <= 2100 && $m >= 1 && $m <= 12) {
                return sprintf('%04d-%02d', $y, $m);
            }
        }
        return date('Y-m', (int)strtotime('first day of last month'));
    }

    /** Checklists disponibles pour la boutique (best-effort). */
    private function checklistsFor(int $shopId): array
    {
        $raw = $this->safeFetch(
            fn() => $this->checklistService->getChecklistsForShop($shopId, date('Y-m-d')),
            $this->errors, null, []
        );
        $out = [];
        foreach ((array)$raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (int)($c['id'] ?? $c['id_checklist'] ?? 0);
            $nm = (string)($c['name'] ?? $c['title'] ?? $c['label'] ?? '');
            if ($id > 0 && $nm !== '') {
                $out[] = ['id' => $id, 'name' => $nm];
            }
        }
        return $out;
    }

    /** La visite appartient-elle au consultant connecté ? */
    private function ownsVisit(array $v): bool
    {
        $cid = $this->consultantId();
        return $cid > 0 && (int)($v['id_consultant'] ?? 0) === $cid;
    }

    /** GET /agenda/levers?shop_id=&period=YYYY-MM — statuts TREFLO (JSON). */
    public function leversJson(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $shopId = (int)($_GET['shop_id'] ?? 0);
        $period = $this->validPeriod((string)($_GET['period'] ?? ''));
        if ($shopId <= 0) {
            return $this->json(['ok' => false, 'error' => 'shop_id manquant'], 422);
        }

        // Diagnostic (?debug=1) : AVANT le cache, pour observer la chaîne réelle.
        if (($_GET['debug'] ?? '') === '1') {
            @set_time_limit(120);
            return $this->json($this->leversDiagnostic($shopId, $period, microtime(true)));
        }

        // Le calcul compare la boutique à la moyenne réseau du mois : c'est
        // coûteux (métriques de toutes les boutiques). Cache serveur par
        // (boutique, période) — les mois passés ne changent plus.
        $cache = sys_get_temp_dir() . '/pwa_consultant_levers_' . $shopId . '_' . $period . '.json';
        if (!isset($_GET['fresh']) && is_file($cache) && (time() - (int)@filemtime($cache)) < 1800) {
            $c = json_decode((string)@file_get_contents($cache), true);
            if (is_array($c) && !empty($c['levers'])) {
                return $this->json(['ok' => true, 'period' => $period, 'levers' => $c['levers'], 'cached' => true]);
            }
        }

        @set_time_limit(120);
        $t0 = microtime(true);
        try {
            $levers = $this->leverResults($shopId, $period);
            @file_put_contents($cache, json_encode(['levers' => $levers]));
            return $this->json([
                'ok'        => true,
                'period'    => $period,
                'levers'    => $levers,
                'elapsed_s' => round(microtime(true) - $t0, 1),
            ]);
        } catch (\Throwable $e) {
            // Throwable : un TypeError ne doit pas devenir un 500 muet.
            error_log('[agenda] leversJson ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $this->json([
                'ok'        => false,
                'period'    => $period,
                'levers'    => [],
                'error'     => get_class($e) . ' — ' . $e->getMessage(),
                'elapsed_s' => round(microtime(true) - $t0, 1),
            ]);
        }
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
