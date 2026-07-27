<?php
namespace App\Consultant\app\Services\Report;

use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Claim\ClaimService;
use App\Consultant\app\Services\Target\ShopMetricTargetService;
use App\Consultant\app\Services\Task\TaskService;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\core\Support\GlobalRegistry;

/**
 * Agrégation des données pour les RAPPORTS hebdomadaire / mensuel.
 *
 * Un rapport couvre une période PASSÉE (semaine précédente ou mois précédent)
 * et un périmètre (une boutique OU toutes les boutiques du consultant). Il
 * réunit, à partir des services existants :
 *   - Performance (Hexm)         : CA, tickets, panier moyen, produits/ticket
 *   - Targets                    : les 6 leviers et leurs seuils du mois
 *                                  (image figée du mois précédent pour le mensuel)
 *   - Notes & photos             : notes de la période, avec miniatures
 *   - Réclamations fournisseurs  : groupées par fournisseur
 *   - Demandes (Helpdesk)        : ce qui a été demandé sur la période
 *   - Tâches réalisées           : réalisations de la période
 *
 * Tout est calculé côté serveur (aucune dépendance au JS des écrans).
 */
class ReportService
{
    /** Garde-fou : nombre max de complétions de tâches inspectées par rapport. */
    private const MAX_COMPLETIONS = 120;

    /**
     * Seuils de statut vs moyenne réseau, en % RELATIFS — MÊMES valeurs que
     * l'écran HEXm : score = sens × (valeur − moyenne) / |moyenne| × 100 ;
     * ✓ bon ≥ GOOD · ⚠ attention ∈ [DANGER, GOOD[ · ● danger < DANGER.
     */
    private const THRESHOLD_GOOD   = -5.0;
    private const THRESHOLD_DANGER = -15.0;

    public function __construct(
        private ShopService $shopService,
        private NoteService $noteService,
        private ClaimService $claimService,
        private ShopMetricTargetService $targetService,
        private TaskService $taskService,
        private ConsultantUserRepository $consultantUsers
    ) {}

    /**
     * @param string $type  'week' | 'month'
     * @param string $scope 'all' | id de boutique (numérique)
     */
    public function generate(string $type, string $scope): array
    {
        $type   = $type === 'month' ? 'month' : 'week';
        $period = $this->computePeriod($type);
        $tgt    = $this->targetsMonth($type, $period);

        $allShops = $this->shopService->getAllShops();

        // Périmètre : une boutique précise ou toutes.
        $scopeShops     = $allShops;
        $scopeLabel     = 'Toutes les boutiques';
        $scopeMode      = 'all';
        $scopeId        = null;
        $scopeShopNames = [];
        if ($scope !== 'all' && ctype_digit($scope)) {
            $sid = (int)$scope;
            $one = array_values(array_filter($allShops, fn($s) => (int)($s['id'] ?? 0) === $sid));
            if ($one !== []) {
                $scopeShops = $one;
                $scopeMode  = 'shop';
                $scopeId    = $sid;
                $scopeLabel = (string)($one[0]['representative_name'] ?? $one[0]['name'] ?? ('#' . $sid));
                foreach ([$one[0]['representative_name'] ?? null, $one[0]['name'] ?? null] as $nm) {
                    $nm = mb_strtolower(trim((string)$nm));
                    if ($nm !== '') {
                        $scopeShopNames[] = $nm;
                    }
                }
            }
        }

        // Filtre boutique pour les demandes : appliqué uniquement quand une
        // boutique précise est choisie (sinon toutes les demandes).
        $shopFilter = $scopeMode === 'shop'
            ? ['id' => $scopeId, 'names' => $scopeShopNames]
            : null;

        $fromT = strtotime($period['from'] . ' 00:00:00');
        $toT   = strtotime($period['to'] . ' 23:59:59');

        // Tags OFFICIELS des leviers (of_tag) — mêmes couleurs/identité que l'écran HEXm.
        $ofTags = $this->consultantUsers->getOfficialTags();

        // Métriques HEXm de TOUTES les boutiques → moyenne réseau, référence du
        // statut ✓ bon / ⚠ attention / ● danger (comme l'écran HEXm). Même en
        // périmètre « une boutique », on a besoin du réseau pour situer.
        $metricsByShop = [];
        $kpisByShop    = [];
        foreach ($allShops as $shop) {
            $sid = (int)($shop['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $k = $this->shopService->getSalesKpis($sid, $period['from'], $period['to']);
            $kpisByShop[$sid]    = $k;
            $metricsByShop[$sid] = $this->hexmMetrics($sid, $period, $k);
        }
        $netAvg = $this->networkAverages($metricsByShop);

        $shopSections = [];
        foreach ($scopeShops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }
            $shopName = (string)($shop['representative_name'] ?? $shop['name'] ?? ('#' . $shopId));

            $kpis      = $kpisByShop[$shopId] ?? $this->shopService->getSalesKpis($shopId, $period['from'], $period['to']);
            $metrics   = $metricsByShop[$shopId] ?? $this->hexmMetrics($shopId, $period, $kpis);
            $franchise = $this->franchiseKpisForShop($shopId, $period, $tgt, $kpis);

            $shopSections[] = [
                'id'                 => $shopId,
                'name'               => $shopName,
                'kpis'               => $kpis,
                'hexm'               => $this->hexmDisplay($metrics, $netAvg, $ofTags),
                'franchise'          => $franchise['kpis'],
                'targets_label'      => $franchise['label'],
                'notes'              => $this->noteService->getNotesForPeriod($shopId, $period['from'], $period['to']),
                'claims_by_supplier' => $this->claimsBySupplier($shopId, $fromT, $toT),
            ];
        }

        $user = GlobalRegistry::get('user') ?? [];

        return [
            'type'          => $type,
            'type_label'    => $type === 'month' ? 'Mensuel' : 'Hebdomadaire',
            'period'        => $period,
            'targets_month' => $tgt,
            'scope'         => ['mode' => $scopeMode, 'shop_id' => $scopeId, 'label' => $scopeLabel],
            'consultant'    => (string)($user['display_name'] ?? ''),
            'generated_at'  => date('Y-m-d H:i'),
            'shops'         => $shopSections,
            'demandes'      => $this->demandesForPeriod($fromT, $toT, $shopFilter),
            'tasks_done'    => $this->tasksDoneForPeriod($fromT, $toT),
        ];
    }

    /** Fenêtre de la période : semaine précédente (lun→dim) ou mois précédent. */
    private function computePeriod(string $type): array
    {
        if ($type === 'month') {
            $firstThis = new \DateTimeImmutable('first day of this month 00:00:00');
            $from = $firstThis->modify('-1 month');
            $to   = $firstThis->modify('-1 day');
            $label = $this->monthName((int)$from->format('n')) . ' ' . $from->format('Y');
        } else {
            $mondayThis = (new \DateTimeImmutable('today'))->modify('monday this week');
            $from = $mondayThis->modify('-7 days');
            $to   = $mondayThis->modify('-1 day');
            $label = 'du ' . $from->format('d/m/Y') . ' au ' . $to->format('d/m/Y');
        }
        return [
            'from'  => $from->format('Y-m-d'),
            'to'    => $to->format('Y-m-d'),
            'label' => $label,
        ];
    }

    /**
     * Mois des targets : le mois précédent pour le rapport mensuel (image
     * figée demandée) ; le mois de la période pour l'hebdo.
     */
    private function targetsMonth(string $type, array $period): array
    {
        $ref = new \DateTimeImmutable(($type === 'month' ? $period['from'] : $period['to']));
        return [
            'year'  => (int)$ref->format('Y'),
            'month' => (int)$ref->format('n'),
            'label' => $this->monthName((int)$ref->format('n')) . ' ' . $ref->format('Y'),
        ];
    }

    /**
     * Les 3 objectifs franchisé d'une boutique (comme l'écran /targets) :
     * Clients (tickets), Panier moyen, B2B — chacun avec le réalisé de la
     * PÉRIODE, la même période un an avant (N-1) et l'OBJECTIF, plus un statut
     * ✓/⚠/● (% de l'objectif). Réalisé/N-1 depuis les ventes ; objectif depuis
     * les targets du mois (source active), mis à l'échelle de la période pour
     * les compteurs (le panier moyen, une moyenne, reste inchangé).
     *
     * @return array{label:string, kpis:array}
     */
    private function franchiseKpisForShop(int $shopId, array $period, array $tgt, array $kpis): array
    {
        $ticketsN = (int)($kpis['tickets'] ?? 0);
        $basketN  = isset($kpis['avg_basket']) && $kpis['avg_basket'] !== null ? (float)$kpis['avg_basket'] : null;

        $fromN1 = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1   = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));
        $kpisN1 = $this->shopService->getSalesKpis($shopId, $fromN1, $toN1);
        $ticketsN1 = (int)($kpisN1['tickets'] ?? 0);
        $basketN1  = isset($kpisN1['avg_basket']) && $kpisN1['avg_basket'] !== null ? (float)$kpisN1['avg_basket'] : null;

        [$targets, $label] = $this->bestTargets($shopId, $tgt);

        // Objectif de compteur mis à l'échelle de la période (targets mensuels).
        $days  = max(1, (int)round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1);
        $scale = $days / 30.44;

        $mClients = $this->findFranchiseMetric($targets, ['client', 'ticket', 'trafic', 'traffic', 'visit']);
        $mBasket  = $this->findFranchiseMetric($targets, ['basket', 'panier', 'koszyk', 'avg_ticket']);
        $mB2b     = $this->findFranchiseMetric($targets, ['b2b']);

        $objClients = $mClients ? $this->scaleCount($this->objectiveOfEntry($mClients), $scale) : null;
        $objBasket  = $mBasket  ? $this->objectiveOfEntry($mBasket) : null;
        $objB2b     = $mB2b     ? $this->scaleCount($this->objectiveOfEntry($mB2b), $scale) : null;
        $valB2b     = $mB2b     ? $this->encodedValueOf($mB2b) : null;

        $kpisOut = [
            ['label' => 'Clients',      'unit' => 'count',  'n1' => $ticketsN1 ?: null, 'val' => $ticketsN ?: null, 'obj' => $objClients],
            ['label' => 'Panier moyen', 'unit' => 'amount', 'n1' => $basketN1,          'val' => $basketN,          'obj' => $objBasket],
            ['label' => 'B2B',          'unit' => 'count',  'n1' => null,               'val' => $valB2b,           'obj' => $objB2b],
        ];
        foreach ($kpisOut as &$k) {
            $k['status'] = $this->targetStatus($k['val'], $k['obj']);
            $k['evo']    = ($k['n1'] !== null && $k['n1'] > 0 && $k['val'] !== null)
                ? (($k['val'] - $k['n1']) / $k['n1']) * 100 : null;
        }
        unset($k);

        return ['label' => $label, 'kpis' => $kpisOut];
    }

    /**
     * Targets bruts du mois de référence, avec repli mois courant → précédent
     * (pour ne pas rester vide alors que des objectifs existent). Renvoie
     * [targets, libellé_du_mois].
     *
     * @return array{0: array, 1: string}
     */
    private function bestTargets(int $shopId, array $tgt): array
    {
        $get = function (int $y, int $m) use ($shopId): ?array {
            $t = $this->targetService->getTargets($shopId, $y, $m);
            return (is_array($t) && $this->hasAnyThreshold($t)) ? $t : null;
        };

        if (($t = $get($tgt['year'], $tgt['month'])) !== null) {
            return [$t, $tgt['label']];
        }
        $prev = strtotime('first day of -1 month');
        foreach ([[(int)date('Y'), (int)date('n')], [(int)date('Y', $prev), (int)date('n', $prev)]] as [$y, $m]) {
            if ($y === $tgt['year'] && $m === $tgt['month']) {
                continue;
            }
            if (($t = $get($y, $m)) !== null) {
                return [$t, $this->monthName($m) . ' ' . $y];
            }
        }
        return [[], $tgt['label']];
    }

    private function hasAnyThreshold(array $targets): bool
    {
        foreach ($targets as $e) {
            if (is_array($e) && $this->objectiveOfEntry($e) !== null) {
                return true;
            }
        }
        return false;
    }

    /** Sous-clé de seuils ACTIVE d'une entrée target (active → consultant → admin → default). */
    private function activeSource(array $entry): ?array
    {
        $active = is_string($entry['active'] ?? null) ? $entry['active'] : null;
        foreach ([$active, 'consultant', 'admin', 'default'] as $src) {
            if ($src !== null && isset($entry[$src]) && is_array($entry[$src])) {
                return $entry[$src];
            }
        }
        if (isset($entry['t1']) || isset($entry['t2']) || isset($entry['t3'])) {
            return $entry; // ancienne forme à plat
        }
        return null;
    }

    /** Objectif = meilleur seuil de la source active (max, ou min si « plus bas = mieux »). */
    private function objectiveOfEntry(array $entry): ?float
    {
        $src = $this->activeSource($entry);
        if ($src === null) {
            return null;
        }
        $vals = [];
        foreach (['t1', 't2', 't3'] as $k) {
            if (isset($src[$k]) && is_numeric($src[$k])) {
                $vals[] = (float)$src[$k];
            }
        }
        if ($vals === []) {
            return null;
        }
        return !empty($entry['lower_is_better']) ? min($vals) : max($vals);
    }

    /** Valeur réalisée éventuellement encodée (ex. B2B, hors POS). */
    private function encodedValueOf(array $entry): ?float
    {
        $src = $this->activeSource($entry) ?? [];
        foreach (['value', 'actual', 'current', 'result', 'realised', 'realized'] as $k) {
            if (isset($entry[$k]) && is_numeric($entry[$k])) {
                return (float)$entry[$k];
            }
            if (isset($src[$k]) && is_numeric($src[$k])) {
                return (float)$src[$k];
            }
        }
        return null;
    }

    /** Métrique target dont la clé/le libellé contient l'un des fragments. */
    private function findFranchiseMetric(array $targets, array $frags): ?array
    {
        foreach ($targets as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $hay = mb_strtolower((string)$key . ' ' . (string)($entry['label'] ?? ''));
            foreach ($frags as $f) {
                if (mb_strpos($hay, $f) !== false) {
                    return $entry;
                }
            }
        }
        return null;
    }

    private function scaleCount(?float $v, float $scale): ?float
    {
        return $v === null ? null : $v * $scale;
    }

    /** Statut vs objectif : ✓ ≥ 95 % · ⚠ ≥ 80 % · ● en dessous (comme /targets). */
    private function targetStatus(?float $val, ?float $obj): string
    {
        if ($val === null || $obj === null || $obj <= 0) {
            return 'nd';
        }
        $pct = ($val / $obj) * 100;
        if ($pct >= 95.0) {
            return 'ok';
        }
        if ($pct >= 80.0) {
            return 'mid';
        }
        return 'bad';
    }

    /** Réclamations de la période, groupées par fournisseur. */
    private function claimsBySupplier(int $shopId, int $fromT, int $toT): array
    {
        $out = [];
        foreach ($this->claimService->getClaimsForShop($shopId) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $t = !empty($c['reported_at']) ? strtotime((string)$c['reported_at']) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            $supplier = trim((string)($c['supplier_name'] ?? '')) ?: 'Fournisseur inconnu';
            $out[$supplier][] = $c;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Demandes (Helpdesk) créées sur la période. Quand une boutique précise est
     * choisie ($shopFilter non null), on ne garde que ses demandes ; sinon
     * toutes. Le cas n'expose que `shop_name` : filtrage par id si présent,
     * sinon par nom normalisé (égalité ou inclusion pour tolérer les formes
     * courtes « Corbais » vs « Atelier by Berlo - Corbais »).
     *
     * @param array{id:?int, names:string[]}|null $shopFilter
     */
    private function demandesForPeriod(int $fromT, int $toT, ?array $shopFilter = null): array
    {
        $hd = $this->taskService->getHelpdeskTasks([]);
        $cases = is_array($hd['cases'] ?? null) ? $hd['cases'] : [];

        $out = [];
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }
            $t = !empty($case['created_at']) ? strtotime((string)$case['created_at']) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            if ($shopFilter !== null && !$this->caseMatchesShop($case, $shopFilter)) {
                continue;
            }
            $out[] = $case;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $out;
    }

    /**
     * Une demande appartient-elle à la boutique filtrée ? Par id si le cas en
     * porte un, sinon par nom (normalisé, égalité ou inclusion). Sans aucune
     * info boutique exploitable → exclue (le rapport est limité à cette boutique).
     *
     * @param array{id:?int, names:string[]} $filter
     */
    private function caseMatchesShop(array $case, array $filter): bool
    {
        foreach (['shop_id', 'id_shop', 'id_boutique', 'store_id'] as $k) {
            if (isset($case[$k]) && (int)$case[$k] > 0) {
                return (int)$case[$k] === (int)($filter['id'] ?? 0);
            }
        }

        $cn = mb_strtolower(trim((string)($case['shop_name'] ?? '')));
        if ($cn === '') {
            return false;
        }
        foreach ($filter['names'] as $nm) {
            if ($nm !== '' && ($cn === $nm || mb_strpos($cn, $nm) !== false || mb_strpos($nm, $cn) !== false)) {
                return true;
            }
        }
        return false;
    }

    /** Tâches réalisées (complétions) sur la période — niveau consultant. */
    private function tasksDoneForPeriod(int $fromT, int $toT): array
    {
        $data  = $this->taskService->getConsultantTasks();
        $tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];

        $out     = [];
        $checked = 0;
        foreach ($tasks as $task) {
            if (!is_array($task) || empty($task['is_done'])) {
                continue;
            }
            $completionId = (int)($task['completion_id'] ?? 0);
            if ($completionId <= 0 || $checked >= self::MAX_COMPLETIONS) {
                continue;
            }
            $checked++;

            $completion = $this->taskService->getCompletion($completionId);
            $done = $completion['completed_at'] ?? $completion['scheduled_for_date'] ?? null;
            $t = $done ? strtotime((string)$done) : false;
            if ($t === false || $t < $fromT || $t > $toT) {
                continue;
            }
            $out[] = [
                'name'         => $task['name'] ?? ($completion['task_name'] ?? ''),
                'category'     => $task['section_name'] ?? $task['category_name'] ?? null,
                'completed_at' => $completion['completed_at'] ?? $completion['scheduled_for_date'] ?? null,
                'by'           => $completion['completed_by_display_name'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => strcmp((string)($b['completed_at'] ?? ''), (string)($a['completed_at'] ?? '')));
        return $out;
    }

    /**
     * Métriques HEXm BRUTES d'un magasin sur la période (nombres, pour la
     * moyenne réseau et le statut) : tickets/jour, panier moyen, coût matière
     * %, marge brute %, évolution CA vs N-1. Food cost = même source que
     * l'écran HEXm. null quand la donnée n'est pas disponible.
     *
     * @param array $kpis résultat de ShopService::getSalesKpis (période)
     * @return array{ticketsDay:?float, avgBasket:?float, foodPct:?float, grossPct:?float, evo:?float}
     */
    private function hexmMetrics(int $shopId, array $period, array $kpis): array
    {
        $ca      = (float)($kpis['ca'] ?? 0);
        $tickets = (int)($kpis['tickets'] ?? 0);
        $basket  = $kpis['avg_basket'] ?? null;

        $days       = max(1, (int)round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1);
        $ticketsDay = $tickets > 0 ? $tickets / $days : null;

        $material = $this->shopService->getMaterialCost($shopId, $period['from'], $period['to']);
        $foodPct  = ($material !== null && $ca > 0) ? ($material / $ca) * 100 : null;
        $grossPct = $foodPct !== null ? 100 - $foodPct : null;

        $fromN1 = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1   = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));
        $caN1   = (float)($this->shopService->getSalesKpis($shopId, $fromN1, $toN1)['ca'] ?? 0);
        $evo    = $caN1 > 0 ? (($ca - $caN1) / $caN1) * 100 : null;

        return [
            'ticketsDay' => $ticketsDay,
            'avgBasket'  => $basket !== null ? (float)$basket : null,
            'foodPct'    => $foodPct,
            'grossPct'   => $grossPct,
            'evo'        => $evo,
        ];
    }

    /** Moyenne réseau de chaque métrique (boutiques ayant une valeur). */
    private function networkAverages(array $metricsByShop): array
    {
        $avg = [];
        foreach (['ticketsDay', 'avgBasket', 'foodPct', 'grossPct', 'evo'] as $k) {
            $vals = [];
            foreach ($metricsByShop as $m) {
                if (isset($m[$k]) && $m[$k] !== null && is_finite((float)$m[$k])) {
                    $vals[] = (float)$m[$k];
                }
            }
            $avg[$k] = $vals !== [] ? array_sum($vals) / count($vals) : null;
        }
        return $avg;
    }

    /**
     * Affichage des 6 leviers HEXm d'un magasin : mêmes leviers/numéros/couleurs
     * (couleur officielle of_tag par nom), KPI formatés de la période, et STATUT
     * ✓ bon / ⚠ attention / ● danger vs moyenne réseau (comme l'écran HEXm ; le
     * statut du levier = le pire de ses KPI). Labour et Expérience client
     * restent « à venir » (pas de source par période côté serveur).
     */
    private function hexmDisplay(array $metrics, array $netAvg, array $ofTags): array
    {
        // metric, sens (dir), libellé, format. `letter` = initiale du levier
        // affichée dans la pastille carrée (T Trafic, R Récurrence, …).
        $defs = [
            ['num' => 4, 'key' => 'trafic',     'letter' => 'T', 'name' => 'Trafic',            'color' => '#1F4F6B', 'kpis' => [
                ['metric' => 'ticketsDay', 'dir' => 1,  'label' => 'Trafic (tickets/jour)', 'fmt' => 'int'],
            ]],
            ['num' => 3, 'key' => 'recurrence', 'letter' => 'R', 'name' => 'Récurrence',        'color' => '#8a4a24', 'kpis' => [
                ['metric' => 'avgBasket',  'dir' => 1,  'label' => 'Panier moyen', 'fmt' => 'eur'],
            ]],
            ['num' => 2, 'key' => 'xp',         'letter' => 'E', 'name' => 'Expérience client', 'color' => '#2D7A3E', 'kpis' => []],
            ['num' => 5, 'key' => 'food',       'letter' => 'F', 'name' => 'Food Cost',         'color' => '#C9A227', 'kpis' => [
                ['metric' => 'foodPct',    'dir' => -1, 'label' => 'Coût matière (% CA)', 'fmt' => 'pct'],
                ['metric' => 'grossPct',   'dir' => 1,  'label' => 'Marge brute (% CA)',  'fmt' => 'pct'],
            ]],
            ['num' => 6, 'key' => 'labour',     'letter' => 'L', 'name' => 'Labour Cost',       'color' => '#8D1D2C', 'kpis' => []],
            ['num' => 7, 'key' => 'overhead',   'letter' => 'O', 'name' => 'Overhead Cost',     'color' => '#7a7168', 'kpis' => [
                ['metric' => 'evo',        'dir' => 1,  'label' => 'Évolution CA vs N-1', 'fmt' => 'evo'],
            ]],
        ];

        $levers = [];
        foreach ($defs as $def) {
            $kpis     = [];
            $statuses = [];
            foreach ($def['kpis'] as $k) {
                $v      = isset($metrics[$k['metric']]) && $metrics[$k['metric']] !== null ? (float)$metrics[$k['metric']] : null;
                $status = $this->statusOf($v, $netAvg[$k['metric']] ?? null, (int)$k['dir']);
                $statuses[] = $status;
                $kpis[] = [
                    'label'  => $k['label'],
                    'value'  => $v !== null ? $this->fmtMetric($v, $k['fmt']) : null,
                    'status' => $status,
                ];
            }
            $levers[] = [
                'num'    => $def['num'],
                'key'    => $def['key'],
                'letter' => $def['letter'],
                'name'   => $def['name'],
                'color'  => $def['color'],
                'status' => $this->worstStatus($statuses),
                'kpis'   => $kpis,
            ];
        }

        // Couleur/nom officiels of_tag (correspondance de nom, comme l'écran HEXm).
        foreach ($levers as &$lev) {
            foreach ($ofTags as $tag) {
                $tn = strtolower(trim((string)($tag['name'] ?? '')));
                if ($tn === '' || empty($tag['color'])) {
                    continue;
                }
                if (strpos($tn, $lev['key']) !== false || strpos($tn, strtolower($lev['name'])) !== false) {
                    $lev['color']    = (string)$tag['color'];
                    $lev['tag_name'] = (string)$tag['name'];
                    break;
                }
            }
        }
        unset($lev);

        return ['levers' => $levers];
    }

    /** Statut d'une valeur vs moyenne réseau : ok / mid / bad, ou nd si indécidable. */
    private function statusOf(?float $v, ?float $avg, int $dir): string
    {
        if ($v === null || $avg === null || $avg == 0.0 || !is_finite($v) || !is_finite($avg)) {
            return 'nd';
        }
        $score = $dir * (($v - $avg) / abs($avg)) * 100;
        if ($score >= self::THRESHOLD_GOOD) {
            return 'ok';
        }
        if ($score >= self::THRESHOLD_DANGER) {
            return 'mid';
        }
        return 'bad';
    }

    /** Pire statut d'une liste (bad > mid > ok) ; nd si aucun décidable. */
    private function worstStatus(array $statuses): string
    {
        $rank = ['ok' => 1, 'mid' => 2, 'bad' => 3];
        $worst = 'nd';
        foreach ($statuses as $s) {
            if (isset($rank[$s]) && ($worst === 'nd' || $rank[$s] > $rank[$worst])) {
                $worst = $s;
            }
        }
        return $worst;
    }

    private function fmtMetric(float $v, string $fmt): string
    {
        return match ($fmt) {
            'int'   => $this->fmtInt($v),
            'eur'   => $this->fmtEur($v),
            'pct'   => $this->fmtPct($v),
            'evo'   => $this->fmtEvo($v),
            default => (string)$v,
        };
    }

    private function fmtInt(float $v): string
    {
        return number_format(round($v), 0, ',', ' ');
    }

    private function fmtEur(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }

    private function fmtPct(float $v): string
    {
        return str_replace('.', ',', (string)round($v, 1)) . ' %';
    }

    private function fmtEvo(float $v): string
    {
        return ($v > 0 ? '+' : '') . str_replace('.', ',', (string)round($v, 1)) . ' %';
    }

    private function monthName(int $m): string
    {
        $names = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return $names[$m] ?? (string)$m;
    }
}
