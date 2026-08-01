<?php
namespace App\Consultant\app\Services\Report;

use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\app\Services\Note\NoteService;
use App\Consultant\app\Services\Claim\ClaimService;
use App\Consultant\app\Services\Target\ShopMetricTargetService;
use App\Consultant\app\Services\Task\TaskService;
use App\Consultant\app\Services\Campaign\CampaignService;
use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\app\Services\Kpi\KpiThresholdService;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\app\Repositories\Valuation\PnlSnapshotRepository;
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
     * Pourquoi la section « Tâches réalisées » est vide, le cas échéant.
     * « Aucune tâche réalisée sur la période » est une AFFIRMATION : elle est
     * fausse si des tâches sont faites mais hors période, ou si l'endpoint des
     * réalisations n'a pas répondu. Le rapport doit dire lequel des trois.
     */
    private array $tasksDoneDiag = ['done' => 0, 'resolved' => 0, 'truncated' => false];

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
        private ConsultantUserRepository $consultantUsers,
        private CampaignService $campaignService,
        private ParamService $params,
        private PnlSnapshotRepository $pnlSnapshots,
        private KpiThresholdService $kpiColors
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

        // Moyennes RÉSEAU des KPI franchisé, pour situer chaque boutique :
        // clients = moyenne des tickets du réseau ; panier = moyenne des paniers.
        // (Le B2B n'a pas de source POS fiable → pas de moyenne réseau.)
        $netTickets = [];
        $netBasket  = [];
        $netCa      = [];
        foreach ($kpisByShop as $k) {
            if (isset($k['ca']) && (float)$k['ca'] > 0) {
                $netCa[] = (float)$k['ca'];
            }
            if (isset($k['tickets']) && (int)$k['tickets'] > 0) {
                $netTickets[] = (int)$k['tickets'];
            }
            if (isset($k['avg_basket']) && $k['avg_basket'] !== null && (float)$k['avg_basket'] > 0) {
                $netBasket[] = (float)$k['avg_basket'];
            }
        }
        $netFranchise = [
            'ca'      => $netCa      !== [] ? array_sum($netCa)      / count($netCa)      : null,
            'clients' => $netTickets !== [] ? array_sum($netTickets) / count($netTickets) : null,
            'basket'  => $netBasket  !== [] ? array_sum($netBasket)  / count($netBasket)  : null,
            'b2b'     => null,
        ];

        // Fenêtre des campagnes commerciales : année-à-ce-jour (1er janvier de
        // l'année de la période → fin de période), comme l'écran Targets — les
        // campagnes sont saisonnières et souvent définies sur l'année entière.
        $campFrom = date('Y-01-01', (int)strtotime($period['to']));
        $campTo   = $period['to'];

        // « Toutes les boutiques » → une vue RÉSEAU consolidée (3 onglets :
        // tableau de bord + classement, heatmap, synthèse) au lieu d'empiler la
        // fiche complète de chaque boutique. « Une boutique » → sa fiche détaillée.
        $shopSections = [];
        $network      = null;
        if ($scopeMode === 'all') {
            $network = $this->buildNetworkView(
                $allShops, $period, $tgt, $kpisByShop, $metricsByShop, $netAvg, $netFranchise, $ofTags, $fromT, $toT
            );
            // Résultats des campagnes commerciales — rapport MENSUEL uniquement,
            // agrégées par nom sur le réseau (+ totaux CA target/réalisé).
            if ($type === 'month') {
                $lists = [];
                foreach ($allShops as $shop) {
                    $sid = (int)($shop['id'] ?? 0);
                    if ($sid > 0) {
                        $lists[] = $this->campaignService->forShop($sid, $campFrom, $campTo);
                    }
                }
                $merged = $this->campaignService->mergeByName($lists);
                $network['campaigns']       = $merged;
                $network['campaigns_totals'] = $this->campaignService->totals($merged);
            }
        } else {
            foreach ($scopeShops as $shop) {
                $shopId = (int)($shop['id'] ?? 0);
                if ($shopId <= 0) {
                    continue;
                }
                $shopName = (string)($shop['representative_name'] ?? $shop['name'] ?? ('#' . $shopId));

                $kpis      = $kpisByShop[$shopId] ?? $this->shopService->getSalesKpis($shopId, $period['from'], $period['to']);
                $metrics   = $metricsByShop[$shopId] ?? $this->hexmMetrics($shopId, $period, $kpis);
                $franchise = $this->franchiseKpisForShop($shopId, $period, $tgt, $kpis, $netFranchise);
                $notes     = $this->noteService->getNotesForPeriod($shopId, $period['from'], $period['to']);

                $shopSections[] = [
                    'id'                 => $shopId,
                    'name'               => $shopName,
                    'kpis'               => $kpis,
                    'hexm'               => $this->hexmDisplay($metrics, $netAvg, $ofTags),
                    'franchise'          => $franchise['kpis'],
                    'targets_label'      => $franchise['label'],
                    'notes'              => $notes,
                    // Notes/commentaires groupés par jour — pour le micro P&L
                    // ouvert depuis la heatmap (contexte du franchisé).
                    'notes_by_day'       => $this->notesByDay($notes),
                    'claims_by_supplier' => $this->claimsBySupplier($shopId, $fromT, $toT),
                    // Campagnes commerciales — rapport MENSUEL uniquement, fenêtre
                    // année-à-ce-jour (comme l'écran Targets) ; null en hebdo → section
                    // masquée, tableau vide en mensuel → « aucune campagne ».
                    'campaigns'          => $type === 'month'
                        ? $this->campaignService->forShop($shopId, $campFrom, $campTo)
                        : null,
                    // Comparaison structurée boutique/réseau + analyse de
                    // rentabilité (heatmap de marge) — rapport MENSUEL uniquement.
                    'compare'            => $type === 'month'
                        ? $this->structuredCompare($shopId, $period, $kpis, $kpisByShop, $metricsByShop, $netAvg, $netFranchise)
                        : null,
                    // L'analyse de rentabilité existe aussi en HEBDO : la carte
                    // par jour de semaine y montre les jours de la semaine, là
                    // où le mensuel en donne la moyenne (tous les lundis, etc.).
                    'heatmap'            => $this->rentabilityHeatmap($shopId, $period, $type),
                ];
            }
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
            'network'       => $network,
            'demandes'      => $this->demandesForPeriod($fromT, $toT, $shopFilter),
            'tasks_done'    => $this->tasksDoneForPeriod($fromT, $toT),
            'tasks_done_diag' => $this->tasksDoneDiagnostics(),
        ];
    }

    /**
     * Statuts des 6 leviers HEXm d'une boutique pour un MOIS CHOISI (vs moyenne
     * réseau du même mois) — pour l'agenda : sélectionner une période et voir
     * quels leviers doivent être travaillés (⚠/● = à travailler).
     *
     * @return array<string, array{key:string, letter:string, name:string, status:string, kpis:array}>
     */
    public function leverStatusesForMonth(int $shopId, int $year, int $month): array
    {
        $first  = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $period = [
            'from'  => $first->format('Y-m-d'),
            'to'    => $first->modify('last day of this month')->format('Y-m-d'),
            'label' => $this->monthName($month) . ' ' . $year,
        ];

        $ids = [];
        foreach ($this->shopService->getAllShops() as $shop) {
            $sid = (int)($shop['id'] ?? 0);
            if ($sid > 0) {
                $ids[] = $sid;
            }
        }
        // La boutique demandée doit TOUJOURS être calculée, même si elle est
        // absente de la liste (inactive, périmètre différent) — sinon la vue
        // recevait une réponse vide et affichait « chargement impossible ».
        if (!in_array($shopId, $ids, true)) {
            $ids[] = $shopId;
        }

        // Moyennes réseau du mois : coûteuses (métriques de TOUTES les
        // boutiques) mais IDENTIQUES pour toutes les boutiques et figées dès
        // que le mois est passé → cache disque par période. Sans ce cache, le
        // premier appel enchaînait ~50 requêtes API et la requête AJAX
        // expirait avant de répondre.
        $cacheFile = sys_get_temp_dir() . '/pwa_consultant_netavg_' . sprintf('%04d-%02d', $year, $month) . '.json';
        $netAvg = null;
        if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 1800) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && $c !== []) {
                $netAvg = $c;
            }
        }

        $fromN1 = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1   = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));

        if ($netAvg === null) {
            // PRÉCHAUFFAGE PARALLÈLE (curl_multi) : ventes période + N-1 de
            // toutes les boutiques en une rafale, P&L en une seconde. Les
            // appels unitaires de hexmMetrics deviennent des lectures de cache.
            $windows = [];
            foreach ($ids as $sid) {
                $windows[] = ['shop' => $sid, 'from' => $period['from'], 'to' => $period['to']];
                $windows[] = ['shop' => $sid, 'from' => $fromN1, 'to' => $toN1];
            }
            $batch = $this->shopService->getSalesKpisBatch($windows);
            $this->shopService->getPnlMany($ids, 'month');

            $metricsByShop = [];
            foreach ($ids as $sid) {
                $k = $batch["{$sid}|{$period['from']}|{$period['to']}"]
                    ?? $this->shopService->getSalesKpis($sid, $period['from'], $period['to']);
                $metricsByShop[$sid] = $this->hexmMetrics($sid, $period, $k);
            }
            $netAvg = $this->networkAverages($metricsByShop);
            @file_put_contents($cacheFile, json_encode($netAvg));
        } else {
            // Moyennes en cache : SEULE la boutique demandée est calculée.
            $metricsByShop = [
                $shopId => $this->hexmMetrics(
                    $shopId,
                    $period,
                    $this->shopService->getSalesKpis($shopId, $period['from'], $period['to'])
                ),
            ];
        }
        // Tags officiels : purement décoratifs ici — leur indisponibilité ne
        // doit pas empêcher le calcul des statuts.
        try {
            $ofTags = $this->consultantUsers->getOfficialTags();
        } catch (\Throwable $e) {
            $ofTags = [];
        }
        $display = $this->hexmDisplay($metricsByShop[$shopId] ?? [], $netAvg, $ofTags);

        $out = [];
        foreach (($display['levers'] ?? []) as $lev) {
            $out[(string)$lev['key']] = [
                'key'    => (string)$lev['key'],
                'letter' => (string)($lev['letter'] ?? ''),
                'name'   => (string)($lev['name'] ?? ''),
                'status' => (string)($lev['status'] ?? 'nd'),
                // Couleur OFFICIELLE du levier (of_tag si défini) — identité
                // T R E F L O, la même que l'écran HEXm et les rapports.
                'color'  => (string)($lev['color'] ?? ''),
                'kpis'   => $lev['kpis'] ?? [],
            ];
        }
        return ['period' => $period['label'], 'levers' => $out];
    }

    /**
     * Notes et commentaires (réponses) groupés par jour ('YYYY-MM-DD') — pour
     * afficher le contexte du franchisé dans le micro P&L d'un jour de la
     * heatmap. Chaque entrée : texte, auteur/type, heure.
     *
     * @return array<string, array<int, array{text:string, by:string, time:string}>>
     */
    private function notesByDay(array $notes): array
    {
        $out = [];
        foreach ($notes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $d = substr((string)($n['created_at'] ?? ''), 0, 10);
            if (strlen($d) !== 10) {
                continue;
            }
            if (!empty($n['content'])) {
                $out[$d][] = [
                    'text' => (string)$n['content'],
                    'by'   => (string)($n['author'] ?? $n['type_name'] ?? ''),
                    'time' => substr((string)$n['created_at'], 11, 5),
                ];
            }
            foreach (($n['comments_view'] ?? []) as $c) {
                if (!is_array($c) || empty($c['content'])) {
                    continue;
                }
                $cd = substr((string)($c['created_at'] ?? ''), 0, 10);
                $out[strlen($cd) === 10 ? $cd : $d][] = [
                    'text' => (string)$c['content'],
                    'by'   => (string)($c['author'] ?? ''),
                    'time' => substr((string)($c['created_at'] ?? ''), 11, 5),
                ];
            }
        }
        return $out;
    }

    /**
     * Comparaison STRUCTURÉE boutique / réseau (rapport mensuel) : position
     * (part du CA réseau, rangs CA/évolution/panier), écarts KPI vs moyenne
     * réseau et mix des CATÉGORIES de ventes (part boutique vs part réseau).
     */
    private function structuredCompare(
        int $shopId,
        array $period,
        array $kpis,
        array $kpisByShop,
        array $metricsByShop,
        array $netAvg,
        array $netFranchise
    ): ?array {
        $caShop = (float)($kpis['ca'] ?? 0);

        // Position dans le réseau : part du CA total + rangs.
        $caTotal = 0.0;
        $cas = [];
        $evos = [];
        $baskets = [];
        foreach ($kpisByShop as $sid => $k) {
            $ca = (float)($k['ca'] ?? 0);
            $caTotal += $ca;
            if ($ca > 0) {
                $cas[$sid] = $ca;
            }
            $b = $k['avg_basket'] ?? null;
            if ($b !== null && (float)$b > 0) {
                $baskets[$sid] = (float)$b;
            }
            $e = $metricsByShop[$sid]['evo'] ?? null;
            if ($e !== null) {
                $evos[$sid] = (float)$e;
            }
        }
        $rank = function (array $vals) use ($shopId): ?array {
            if (!isset($vals[$shopId])) {
                return null;
            }
            arsort($vals);
            return [array_search($shopId, array_keys($vals), true) + 1, count($vals)];
        };

        // Écarts vs moyenne réseau : delta en % (grandeurs) ou en points (%).
        $rows = [];
        $mk = function (string $label, ?float $shop, ?float $net, string $unit, int $dec) use (&$rows) {
            $delta = null;
            if ($shop !== null && $net !== null) {
                $delta = $unit === 'pct' ? $shop - $net : ($net != 0.0 ? ($shop - $net) / abs($net) * 100 : null);
            }
            $rows[] = ['label' => $label, 'shop' => $shop, 'net' => $net, 'delta' => $delta, 'unit' => $unit, 'dec' => $dec];
        };
        $evoShop    = isset($metricsByShop[$shopId]['evo']) && $metricsByShop[$shopId]['evo'] !== null
            ? (float)$metricsByShop[$shopId]['evo'] : null;
        $basketShop = isset($kpis['avg_basket']) && $kpis['avg_basket'] !== null ? (float)$kpis['avg_basket'] : null;
        $mk("Chiffre d'affaires",   $caShop > 0 ? $caShop : null,                     $netFranchise['ca'] ?? null,      'amount', 0);
        $mk('Évolution CA vs N-1',  $evoShop,                                          $netAvg['evo'] ?? null,           'pct',    1);
        $mk('Clients (tickets)',    (int)($kpis['tickets'] ?? 0) > 0 ? (float)$kpis['tickets'] : null, $netFranchise['clients'] ?? null, 'count', 0);
        $mk('Panier moyen',         $basketShop,                                       $netFranchise['basket'] ?? null,  'amount', 2);

        // Mix des catégories de ventes : part boutique vs part réseau (points).
        $categories = null;
        $mixAll  = $this->shopService->getCategorySalesMany(array_keys($kpisByShop), $period['from'], $period['to']);
        $shopMix = $mixAll[$shopId] ?? null;
        if (is_array($shopMix) && $shopMix !== []) {
            $netMix = [];
            foreach ($mixAll as $mix) {
                if (!is_array($mix)) {
                    continue;
                }
                foreach ($mix as $name => $ca) {
                    $netMix[$name] = ($netMix[$name] ?? 0.0) + (float)$ca;
                }
            }
            $shopTot = array_sum($shopMix);
            $netTot  = array_sum($netMix);
            if ($shopTot > 0 && $netTot > 0) {
                arsort($shopMix);
                $categories = [];
                foreach (array_slice($shopMix, 0, 10, true) as $name => $ca) {
                    $sp = (float)$ca / $shopTot * 100;
                    $np = isset($netMix[$name]) ? $netMix[$name] / $netTot * 100 : null;
                    $categories[] = [
                        'name'     => (string)$name,
                        'shop_ca'  => (float)$ca,
                        'shop_pct' => $sp,
                        'net_pct'  => $np,
                        'delta'    => $np !== null ? $sp - $np : null,
                    ];
                }
            }
        }

        return [
            'share_pct'   => $caTotal > 0 ? $caShop / $caTotal * 100 : null,
            'rank_ca'     => $rank($cas),
            'rank_evo'    => $rank($evos),
            'rank_basket' => $rank($baskets),
            'rows'        => $rows,
            'categories'  => $categories,
        ];
    }

    /**
     * Analyse de RENTABILITÉ (rapport mensuel) : carte de marge brute du mois
     * (endpoint margin-heatmap) — jour par jour, puis créneaux matin / midi /
     * après-midi par semaine. Les bornes des créneaux viennent de
     * mac_consultant_param (rien en dur). Couleurs par bandes ABSOLUES
     * configurables (table mac_kpi_threshold, métrique net_margin ou gross_margin).
     * null si indisponible.
     */
    private function rentabilityHeatmap(int $shopId, array $period, string $type = 'month'): ?array
    {
        // P4 : découpage hebdo fourni par l'API (1 appel au lieu de 6).
        $month = $this->shopService->getMarginHeatmap($shopId, $period['from'], $period['to'], true);
        if (!is_array($month) || !is_array($month['days'] ?? null) || $month['days'] === []) {
            return null;
        }

        // Bornes des créneaux : INCLUSES, en tranches horaires (la borne 10
        // couvre 10:00 → 10:59).
        $slots = [
            'matin' => [$this->params->getInt('daypart_morning_from', 6),    $this->params->getInt('daypart_morning_to', 10)],
            'midi'  => [$this->params->getInt('daypart_midday_from', 11),    $this->params->getInt('daypart_midday_to', 14)],
            'apm'   => [$this->params->getInt('daypart_afternoon_from', 15), $this->params->getInt('daypart_afternoon_to', 19)],
        ];
        // Une heure d'ouverture hors des plages configurées (magasin ouvert à
        // 5 h ou jusqu'à 21 h) est RATTACHÉE au créneau le plus proche, jamais
        // écartée : sinon son chiffre d'affaires disparaîtrait de l'analyse et
        // les créneaux ne se réconcilieraient plus avec les jours et le mois.
        // Le nombre d'heures rattachées est remonté (légende).
        $snapped = 0;
        $slotOf = function (int $hr) use ($slots, &$snapped): string {
            foreach ($slots as $name => [$from, $to]) {
                if ($hr >= $from && $hr <= $to) {
                    return $name;
                }
            }
            $snapped++;
            $best = null;
            $dist = PHP_INT_MAX;
            foreach ($slots as $name => [$from, $to]) {
                $d = $hr < $from ? $from - $hr : $hr - $to;
                if ($d < $dist) {   // égalité → le créneau le plus tôt gagne
                    $dist = $d;
                    $best = $name;
                }
            }
            return (string)$best;
        };

        // ── Mois / jour ──
        $days = [];
        foreach ($month['days'] as $d) {
            if (!is_array($d) || empty($d['date'])) {
                continue;
            }
            $ts  = (int)strtotime((string)$d['date']);
            $pct = !empty($d['has_data']) && isset($d['margin_pct']) && is_numeric($d['margin_pct'])
                ? (float)$d['margin_pct'] : null;
            $days[] = [
                'date'      => (string)$d['date'],
                'num'       => (int)date('j', $ts),
                'wd'        => (int)date('N', $ts),   // 1 = lundi
                'pct'       => $pct,
                'gross_pct' => $pct,                  // marge brute (avant coûts fixes)
                'mv'        => isset($d['margin_value']) && is_numeric($d['margin_value']) ? (float)$d['margin_value'] : null,
                'ca'        => isset($d['ca']) && is_numeric($d['ca']) ? (float)$d['ca'] : null,
            ];
        }
        if ($days === []) {
            return null;
        }

        // ── Semaine / période (matin, midi, après-midi) ──
        // P4 : si l'API renvoie déjà `weeks` (split=weekly), on l'utilise —
        // sinon repli : une fenêtre par semaine, récupérées en parallèle.
        $start = new \DateTimeImmutable($period['from']);
        $end   = new \DateTimeImmutable($period['to']);
        $apiWeeks = [];
        foreach (($month['weeks'] ?? []) as $w) {
            if (is_array($w) && !empty($w['from']) && !empty($w['to']) && is_array($w['hours'] ?? null)) {
                $apiWeeks[substr((string)$w['from'], 0, 10) . '|' . substr((string)$w['to'], 0, 10)] = $w;
            }
        }

        $bounds = [];
        $wStart = $start->modify('monday this week');
        while ($wStart <= $end) {
            $wEnd = $wStart->modify('+6 days');
            $bounds[] = [
                'from' => ($wStart > $start ? $wStart : $start)->format('Y-m-d'),
                'to'   => ($wEnd < $end ? $wEnd : $end)->format('Y-m-d'),
            ];
            $wStart = $wStart->modify('+7 days');
        }
        if ($apiWeeks !== []) {
            // Bornes fournies par l'API : source d'autorité pour le découpage.
            $bounds = array_map(
                fn($k) => ['from' => explode('|', $k)[0], 'to' => explode('|', $k)[1]],
                array_keys($apiWeeks)
            );
            $maps = [];
        } else {
            $windows = [];
            foreach ($bounds as $b) {
                $windows[] = ['shop' => $shopId, 'from' => $b['from'], 'to' => $b['to']];
            }
            $maps = $this->shopService->getMarginHeatmapMany($windows);
        }

        $weeks = [];
        foreach ($bounds as $b) {
            $wk = $apiWeeks["{$b['from']}|{$b['to']}"]
                ?? ($maps["{$shopId}|{$b['from']}|{$b['to']}"] ?? null);
            $parts = [
                'matin' => ['ca' => 0.0, 'mv' => 0.0, 'hrs' => 0],
                'midi'  => ['ca' => 0.0, 'mv' => 0.0, 'hrs' => 0],
                'apm'   => ['ca' => 0.0, 'mv' => 0.0, 'hrs' => 0],
            ];
            // Heures actives de la semaine : base de répartition des coûts.
            $hrsAll   = 0;
            $snapped  = 0;   // heures rattachées au créneau le plus proche
            if (is_array($wk['hours'] ?? null)) {
                foreach ($wk['hours'] as $h) {
                    if (!is_array($h)) {
                        continue;
                    }
                    $ca = isset($h['ca']) && is_numeric($h['ca']) ? (float)$h['ca'] : 0.0;
                    if ($ca <= 0 || (isset($h['has_data']) && !$h['has_data'])) {
                        continue;
                    }
                    // Marge en valeur, sinon dérivée du % (certains déploiements
                    // de l'endpoint n'exposent que margin_pct sur les heures).
                    $mv = isset($h['margin_value']) && is_numeric($h['margin_value']) ? (float)$h['margin_value'] : null;
                    if ($mv === null && isset($h['margin_pct']) && is_numeric($h['margin_pct'])) {
                        $mv = $ca * (float)$h['margin_pct'] / 100;
                    }
                    if ($mv === null) {
                        continue;
                    }
                    $hr   = (int)($h['hour'] ?? -1);
                    $hrsAll++;
                    $slot = $slotOf($hr);
                    $parts[$slot]['ca'] += $ca;
                    $parts[$slot]['mv'] += $mv;
                    $parts[$slot]['hrs']++;
                }
            }
            $weeks[] = [
                'label'       => date('d/m', (int)strtotime($b['from'])) . '–' . date('d/m', (int)strtotime($b['to'])),
                'from'        => $b['from'],
                'to'          => $b['to'],
                '_parts'      => $parts,
                '_hrsAll'     => $hrsAll,
                'hrs_snapped' => $snapped,
            ];
        }

        // ── Conversion en RÉSULTAT NET.
        //    Labour : RÉEL par jour si le backend l'expose (labour/daily),
        //    sinon labour du mois réparti PAR JOUR D'OUVERTURE (les coûts fixes
        //    quotidiens ne suivent pas le CA : un jour faible est réellement
        //    déficitaire). Overhead : toujours réparti par jour d'ouverture.
        //    Créneaux : coûts de la semaine répartis par HEURES ACTIVES.
        //    Sans structure de coûts, on reste en marge brute (signalé).
        [$fixedPct, $fixedSrc, $labourPct, $overheadPct, $fixedSrcMonth] = $this->fixedCostPct($shopId, $period);
        $basis      = 'gross';
        $labourReal = false;

        // Jours d'ouverture + CA du mois, cohérents avec la grille affichée.
        $openDates = [];
        $caMonth   = 0.0;
        foreach ($days as $d) {
            if ($d['pct'] !== null && $d['ca'] !== null && $d['ca'] > 0) {
                $openDates[$d['date']] = true;
                $caMonth += $d['ca'];
            }
        }
        $openDays = count($openDates);

        if ($fixedPct !== null && $openDays > 0 && $caMonth > 0) {
            $basis  = 'net';
            $labDay = ($labourPct / 100) * $caMonth / $openDays;
            $ovhDay = ($overheadPct / 100) * $caMonth / $openDays;

            // P0 : P&L JOURNALIER réel (chiffres du backoffice) — labour ET
            // overhead exacts par jour. Sinon P&L allégé (labour seul), sinon
            // répartition par jour d'ouverture.
            $dailyPnl = $this->shopService->getDailyPnl($shopId, $period['from'], $period['to']);
            $realLab  = $dailyPnl === null
                ? $this->shopService->getDailyLabour($shopId, $period['from'], $period['to'])
                : null;

            $labByDate = [];
            $ovhByDate = [];
            foreach ($days as &$d) {
                if ($d['pct'] === null || $d['ca'] === null || $d['ca'] <= 0) {
                    $d['lab'] = null;
                    $d['ovh'] = null;
                    $d['lab_real'] = false;
                    continue;
                }
                $mv  = $d['mv'] ?? ($d['ca'] * $d['gross_pct'] / 100);
                $p0  = $dailyPnl[$d['date']] ?? null;
                $lab = $p0['labour'] ?? ($realLab[$d['date']]['labour'] ?? null);
                $ovh = $p0['overhead'] ?? null;
                $d['lab_real'] = $lab !== null;
                if ($lab !== null) {
                    $labourReal = true;
                } else {
                    $lab = $labDay;
                }
                if ($ovh === null) {
                    $ovh = $ovhDay;
                }
                // Marge brute du jour : celle du P&L réel si disponible.
                if ($p0 !== null && $p0['revenue'] !== null && $p0['revenue'] > 0 && $p0['material'] !== null) {
                    $d['ca']        = $p0['revenue'];
                    $mv             = $p0['revenue'] - $p0['material'];
                    $d['mv']        = $mv;
                    $d['gross_pct'] = $mv / $p0['revenue'] * 100;
                }
                $d['lab'] = round($lab, 2);
                $d['ovh'] = round($ovh, 2);
                $d['pct'] = ($mv - $lab - $ovh) / $d['ca'] * 100;
                $labByDate[$d['date']] = $lab;
                $ovhByDate[$d['date']] = $ovh;
            }
            unset($d);

            foreach ($weeks as &$w) {
                // Coût de la semaine = Σ (labour + overhead) des jours ouverts.
                $wkCost = 0.0;
                foreach ($labByDate as $date => $lab) {
                    if ($date >= $w['from'] && $date <= $w['to']) {
                        $wkCost += $lab + ($ovhByDate[$date] ?? $ovhDay);
                    }
                }
                // Part labour / overhead de la semaine (pour le détail).
                $wkLab = 0.0;
                $wkOvh = 0.0;
                foreach ($labByDate as $date => $lab) {
                    if ($date >= $w['from'] && $date <= $w['to']) {
                        $wkLab += $lab;
                        $wkOvh += $ovhByDate[$date] ?? $ovhDay;
                    }
                }
                // Dénominateur : TOUTES les heures actives de la semaine (les
                // heures hors créneaux gardent leur part de coûts).
                $hrsTotal = (int)$w['_hrsAll'];
                foreach (['matin', 'midi', 'apm'] as $slot) {
                    $p = $w['_parts'][$slot];
                    if ($p['ca'] <= 0 || $hrsTotal === 0) {
                        $w[$slot] = null;
                        $w['d_' . $slot] = null;
                        continue;
                    }
                    $share    = $p['hrs'] / $hrsTotal;
                    $lab      = $wkLab * $share;
                    $ovh      = $wkOvh * $share;
                    $w[$slot] = ($p['mv'] - $lab - $ovh) / $p['ca'] * 100;
                    // Détail du créneau (modale) : chaque ligne du micro P&L.
                    $w['d_' . $slot] = [
                        'ca'        => round($p['ca'], 2),
                        'material'  => round($p['ca'] - $p['mv'], 2),
                        'mv'        => round($p['mv'], 2),
                        'gross_pct' => $p['mv'] / $p['ca'] * 100,
                        'lab'       => round($lab, 2),
                        'ovh'       => round($ovh, 2),
                        'net'       => round($p['mv'] - $lab - $ovh, 2),
                        'net_pct'   => ($p['mv'] - $lab - $ovh) / $p['ca'] * 100,
                        'hrs'       => $p['hrs'],
                        'hrs_total' => $hrsTotal,
                    ];
                }
            }
            unset($w);
        } else {
            // Marge brute par créneau (pas de structure de coûts disponible).
            foreach ($weeks as &$w) {
                foreach (['matin', 'midi', 'apm'] as $slot) {
                    $p = $w['_parts'][$slot];
                    $w[$slot] = $p['ca'] > 0 ? $p['mv'] / $p['ca'] * 100 : null;
                    $w['d_' . $slot] = $p['ca'] > 0 ? [
                        'ca'        => round($p['ca'], 2),
                        'material'  => round($p['ca'] - $p['mv'], 2),
                        'mv'        => round($p['mv'], 2),
                        'gross_pct' => $p['mv'] / $p['ca'] * 100,
                        'lab'       => null,
                        'ovh'       => null,
                        'net'       => null,
                        'net_pct'   => null,
                        'hrs'       => $p['hrs'],
                        'hrs_total' => null,
                    ] : null;
                }
            }
            unset($w);
        }
        foreach ($weeks as &$w) {
            unset($w['_parts'], $w['_hrsAll']);
        }
        unset($w);

        // ── Jour de SEMAINE ──
        //    Rapport mensuel : tous les lundis du mois agrégés, tous les mardis,
        //    etc. → on voit quel jour porte ou plombe le mois. Rapport hebdo :
        //    une seule date par colonne, donc le jour lui-même.
        //    Moyenne PONDÉRÉE par le CA (somme des postes ÷ somme du CA) : la
        //    moyenne arithmétique des pourcentages donnerait le même poids à un
        //    lundi creux qu'à un lundi plein.
        $wdAgg = [];
        foreach ($days as $d) {
            if ($d['pct'] === null || ($d['ca'] ?? null) === null || $d['ca'] <= 0) {
                continue;
            }
            $wd = (int)$d['wd'];
            $a  = $wdAgg[$wd] ?? ['ca' => 0.0, 'mv' => 0.0, 'lab' => 0.0, 'ovh' => 0.0, 'days' => 0, 'cost' => true];
            $a['ca'] += $d['ca'];
            $a['mv'] += $d['mv'] ?? ($d['ca'] * $d['gross_pct'] / 100);
            $a['days']++;
            if (($d['lab'] ?? null) !== null && ($d['ovh'] ?? null) !== null) {
                $a['lab'] += $d['lab'];
                $a['ovh'] += $d['ovh'];
            } else {
                $a['cost'] = false;   // un seul jour sans coûts → créneau en marge brute
            }
            $wdAgg[$wd] = $a;
        }
        $weekdays = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $wd) {
            $a = $wdAgg[$wd] ?? null;
            if ($a === null) {
                $weekdays[] = ['wd' => $wd, 'pct' => null, 'days' => 0, 'detail' => null];
                continue;
            }
            $net = $a['cost'] ? $a['mv'] - $a['lab'] - $a['ovh'] : null;
            $pct = ($net ?? $a['mv']) / $a['ca'] * 100;
            $weekdays[] = [
                'wd'   => $wd,
                'pct'  => $pct,
                'days' => $a['days'],
                'detail' => [
                    'ca'        => round($a['ca'], 2),
                    'material'  => round($a['ca'] - $a['mv'], 2),
                    'mv'        => round($a['mv'], 2),
                    'gross_pct' => $a['mv'] / $a['ca'] * 100,
                    'lab'       => $a['cost'] ? round($a['lab'], 2) : null,
                    'ovh'       => $a['cost'] ? round($a['ovh'], 2) : null,
                    'net'       => $net !== null ? round($net, 2) : null,
                    'net_pct'   => $net !== null ? $net / $a['ca'] * 100 : null,
                    'days'      => $a['days'],
                ],
            ];
        }

        // ── Couleurs : bandes ABSOLUES de la table mac_kpi_threshold (marge nette
        //    ou brute selon la base) — mise en forme conditionnelle configurable
        //    en base, cohérente avec l'écran Boutiques.
        $metric = $basis === 'net' ? 'net_margin' : 'gross_margin';
        foreach ($weekdays as &$wdRow) {
            $wdRow['color'] = $wdRow['pct'] !== null ? $this->kpiColors->colorFor($metric, $wdRow['pct']) : null;
        }
        unset($wdRow);
        foreach ($days as &$d) {
            $d['color'] = $d['pct'] !== null ? $this->kpiColors->colorFor($metric, $d['pct']) : null;
        }
        unset($d);
        foreach ($weeks as &$w) {
            foreach (['matin', 'midi', 'apm'] as $slot) {
                $w['color_' . $slot] = $w[$slot] !== null ? $this->kpiColors->colorFor($metric, $w[$slot]) : null;
            }
        }
        unset($w);

        $grossTotal = isset($month['totals']['margin_pct']) && is_numeric($month['totals']['margin_pct'])
            ? (float)$month['totals']['margin_pct'] : null;

        return [
            'days'         => $days,
            'weeks'        => $weeks,
            'weekdays'     => $weekdays,
            'scope'        => $type,
            'bands'        => $this->kpiColors->bands($metric),
            'basis'           => $basis,
            'fixed_pct'       => $fixedPct,
            'fixed_src'       => $fixedSrc,
            'fixed_src_month' => $fixedSrcMonth,
            'labour_pct'      => $labourPct,
            'overhead_pct'    => $overheadPct,
            'labour_real'     => $labourReal,
            'open_days'       => $openDays,
            // Bornes affichées telles qu'elles sont configurées en base.
            'labels' => [
                'matin' => 'Matin (' . $slots['matin'][0] . '–' . $slots['matin'][1] . ' h)',
                'midi'  => 'Midi (' . $slots['midi'][0] . '–' . $slots['midi'][1] . ' h)',
                'apm'   => 'Après-midi (' . $slots['apm'][0] . '–' . $slots['apm'][1] . ' h)',
            ],
            // Heures d'ouverture hors plages, rattachées au créneau le plus
            // proche (max sur les semaines) : signalé dans la légende.
            'hrs_snapped' => max(array_map(fn($w) => (int)($w['hrs_snapped'] ?? 0), $weeks) ?: [0]),
            'totals' => [
                'pct' => $grossTotal !== null ? ($basis === 'net' ? $grossTotal - $fixedPct : $grossTotal) : null,
                'ca'  => isset($month['totals']['ca']) && is_numeric($month['totals']['ca']) ? (float)$month['totals']['ca'] : null,
            ],
        ];
    }

    /**
     * Poids des coûts fixes (labour + overhead) en % du CA pour le mois du
     * rapport — pour convertir la heatmap de marge brute en RÉSULTAT NET
     * (résultat net = marge brute − labour − overhead) :
     *   1. snapshot mensuel du mois du rapport (mac_shop_monthly_pnl, capté par
     *      la valorisation) ;
     *   2. sinon P&L du mois COURANT (structure de coûts actuelle —
     *      approximation signalée dans le rapport).
     *
     * @return array{0: ?float, 1: ?string, 2: ?float, 3: ?float, 4: ?string}
     *         [pct total, 'report_month'|'snapshot'|'current_month'|null,
     *          labour %, overhead %, mois du snapshot 'YYYY-MM' (si snapshot)]
     */
    private function fixedCostPct(int $shopId, array $period): array
    {
        $ref = new \DateTimeImmutable($period['from']);
        $y   = (int)$ref->format('Y');
        $m   = (int)$ref->format('n');

        $ratios = function (array $s): array {
            $lp = (float)$s['labour'] / (float)$s['ca'] * 100;
            $op = (float)$s['overhead'] / (float)$s['ca'] * 100;
            return [$lp, $op];
        };

        // 1) Snapshot du mois exact du rapport.
        $snap = $this->pnlSnapshots->forShopMonth($shopId, $y, $m);
        if (is_array($snap)
            && ($snap['ca'] ?? null) !== null && (float)$snap['ca'] > 0
            && ($snap['labour'] ?? null) !== null && ($snap['overhead'] ?? null) !== null) {
            [$lp, $op] = $ratios($snap);
            return [$lp + $op, 'report_month', $lp, $op, null];
        }

        // 2) Snapshot complet le plus récent avant le mois du rapport — un mois
        //    ENTIER capté vaut mieux que le mois courant partiel (l'overhead,
        //    fixe, y est surpondéré tant que le mois n'est pas fini).
        $snap = $this->pnlSnapshots->latestCostStructureUpTo($shopId, $y, $m);
        if (is_array($snap)) {
            [$lp, $op] = $ratios($snap);
            return [$lp + $op, 'snapshot', $lp, $op, sprintf('%04d-%02d', (int)$snap['year'], (int)$snap['month'])];
        }

        // 3) P&L du mois courant (partiel) — dernier recours, signalé.
        $pnl = $this->shopService->getPnl($shopId, 'month');
        $ca  = $this->pnlNodeValue($pnl['turnover'] ?? null);
        $lab = $this->pnlNodeValue($pnl['labour'] ?? null);
        $ovh = $this->pnlNodeValue($pnl['overhead'] ?? null);
        if ($ca !== null && $ca > 0 && $lab !== null && $ovh !== null) {
            $lp = $lab / $ca * 100;
            $op = $ovh / $ca * 100;
            return [$lp + $op, 'current_month', $lp, $op, null];
        }
        return [null, null, null, null, null];
    }

    /** Valeur numérique d'un nœud P&L ({value:…} ou scalaire numérique). */
    private function pnlNodeValue($node): ?float
    {
        if (is_int($node) || is_float($node)) {
            return (float)$node;
        }
        if (is_string($node) && is_numeric($node)) {
            return (float)$node;
        }
        if (is_array($node) && isset($node['value']) && is_numeric($node['value'])) {
            return (float)$node['value'];
        }
        return null;
    }

    /**
     * Vue RÉSEAU consolidée (périmètre « toutes les boutiques ») alimentant les
     * 3 onglets du rapport, à partir des données déjà calculées par boutique :
     *   - kpis        : totaux réseau (CA, tickets, panier pondéré, évo CA vs N-1) ;
     *   - shops       : une ligne par boutique (CA, évo, panier, écart panier vs
     *                   réseau, statut HEXm global, statut de chaque levier) ;
     *   - objectives  : nombre de boutiques atteignant l'objectif (clients/panier/b2b) ;
     *   - attention   : boutiques en difficulté (leviers ● danger et/ou CA en baisse) ;
     *   - strong      : boutiques en forme (CA en hausse, aucun levier ●) ;
     *   - top / flop  : 3 meilleures / 3 moins bonnes par évolution ;
     *   - claims      : réclamations réseau (total + top fournisseurs).
     */
    private function buildNetworkView(
        array $allShops, array $period, array $tgt, array $kpisByShop, array $metricsByShop,
        array $netAvg, array $netFranchise, array $ofTags, int $fromT, int $toT
    ): array {
        $leverDefs = [
            ['key' => 'trafic',     'letter' => 'T', 'name' => 'Trafic'],
            ['key' => 'recurrence', 'letter' => 'R', 'name' => 'Récurrence'],
            ['key' => 'xp',         'letter' => 'E', 'name' => 'Expérience'],
            ['key' => 'food',       'letter' => 'F', 'name' => 'Food Cost'],
            ['key' => 'labour',     'letter' => 'L', 'name' => 'Labour'],
            ['key' => 'overhead',   'letter' => 'O', 'name' => 'Overhead'],
        ];

        $shops   = [];
        $sumCa   = 0.0;
        $sumTk   = 0;
        $objMet  = ['clients' => 0, 'basket' => 0, 'b2b' => 0];
        $objTot  = ['clients' => 0, 'basket' => 0, 'b2b' => 0];
        $netB    = $netFranchise['basket'] ?? null;

        foreach ($allShops as $shop) {
            $sid = (int)($shop['id'] ?? 0);
            if ($sid <= 0 || !isset($kpisByShop[$sid])) {
                continue;
            }
            $k       = $kpisByShop[$sid];
            $ca      = (float)($k['ca'] ?? 0);
            $tickets = (int)($k['tickets'] ?? 0);
            $basket  = isset($k['avg_basket']) && $k['avg_basket'] !== null ? (float)$k['avg_basket'] : null;
            $metrics = $metricsByShop[$sid] ?? [];
            $evo     = isset($metrics['evo']) && $metrics['evo'] !== null ? (float)$metrics['evo'] : null;

            // Statut de chaque levier vs moyenne réseau (comme la fiche boutique).
            $display     = $this->hexmDisplay($metrics, $netAvg, $ofTags);
            $leverStatus = [];
            $badLevers   = [];
            foreach ($display['levers'] as $lev) {
                $leverStatus[$lev['key']] = $lev['status'];
                if ($lev['status'] === 'bad') {
                    $badLevers[] = (string)($lev['tag_name'] ?? $lev['name']);
                }
            }
            $overall = $this->worstStatus(array_values($leverStatus));

            // Atteinte des objectifs franchisé (comptage réseau). Le CA n'est
            // pas un objectif franchisé → exclu du décompte.
            $labelKey = ['Clients' => 'clients', 'Panier moyen' => 'basket', 'Clients B2B' => 'b2b'];
            $fr = $this->franchiseKpisForShop($sid, $period, $tgt, $k, $netFranchise);
            foreach ($fr['kpis'] as $fk) {
                $key = $labelKey[$fk['label']] ?? null;
                if ($key === null) {
                    continue;
                }
                if (($fk['obj'] ?? null) !== null && ($fk['val'] ?? null) !== null) {
                    $objTot[$key]++;
                    if (($fk['pct'] ?? 0) >= 95.0) {
                        $objMet[$key]++;
                    }
                }
            }

            $deltaBasket = ($netB !== null && $netB > 0 && $basket !== null)
                ? (($basket - $netB) / $netB) * 100 : null;

            $shops[] = [
                'id'           => $sid,
                'name'         => (string)($shop['representative_name'] ?? $shop['name'] ?? ('#' . $sid)),
                'ca'           => $ca,
                'tickets'      => $tickets,
                'basket'       => $basket,
                'evo'          => $evo,
                'delta_basket' => $deltaBasket,
                'status'       => $overall,
                'levers'       => $leverStatus,
                'bad_levers'   => $badLevers,
            ];
            $sumCa += $ca;
            $sumTk += $tickets;
        }

        // Évolution CA réseau : somme des CA vs somme des CA N-1.
        $fromN1  = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1    = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));
        // Même fenêtre pour toutes les boutiques → un seul appel multi-boutiques
        // (P3), au lieu d'un aller-retour par boutique.
        $sumCaN1 = 0.0;
        $winN1 = [];
        foreach ($shops as $s) {
            $winN1[] = ['shop' => (int)$s['id'], 'from' => $fromN1, 'to' => $toN1];
        }
        $kpisN1 = $winN1 !== [] ? $this->shopService->getSalesKpisBatch($winN1) : [];
        foreach ($shops as $s) {
            $sumCaN1 += (float)($kpisN1[(int)$s['id'] . '|' . $fromN1 . '|' . $toN1]['ca'] ?? 0);
        }
        $netEvo    = $sumCaN1 > 0 ? (($sumCa - $sumCaN1) / $sumCaN1) * 100 : null;
        $netBasket = $sumTk > 0 ? $sumCa / $sumTk : null;

        // Classement par CA décroissant.
        usort($shops, fn($a, $b) => $b['ca'] <=> $a['ca']);

        // Top / flop par évolution (boutiques sans N-1 exclues du palmarès).
        $withEvo = array_values(array_filter($shops, fn($s) => $s['evo'] !== null));
        usort($withEvo, fn($a, $b) => $b['evo'] <=> $a['evo']);
        $top  = array_slice($withEvo, 0, 3);
        $flop = array_slice(array_reverse($withEvo), 0, 3);

        // Points d'attention (leviers ● et/ou CA en baisse), triés par gravité.
        $attention = [];
        $strong    = [];
        foreach ($shops as $s) {
            $reasons = [];
            if ($s['bad_levers'] !== []) {
                $reasons[] = implode(', ', $s['bad_levers']);
            }
            $caDown = $s['evo'] !== null && $s['evo'] < 0;
            if ($caDown) {
                $reasons[] = 'CA ' . $this->fmtEvo($s['evo']);
            }
            if ($reasons !== []) {
                $attention[] = [
                    'name'    => $s['name'],
                    'reasons' => $reasons,
                    'sev'     => count($s['bad_levers']) + ($caDown ? 1 : 0),
                ];
            } elseif ($s['evo'] !== null && $s['evo'] > 0) {
                $strong[] = ['name' => $s['name'], 'evo' => $s['evo']];
            }
        }
        usort($attention, fn($a, $b) => $b['sev'] <=> $a['sev']);
        usort($strong, fn($a, $b) => $b['evo'] <=> $a['evo']);

        // Réclamations réseau : total + top fournisseurs.
        $claimsTotal = 0;
        $bySupplier  = [];
        foreach ($shops as $s) {
            foreach ($this->claimsBySupplier($s['id'], $fromT, $toT) as $sup => $cl) {
                $claimsTotal   += count($cl);
                $bySupplier[$sup] = ($bySupplier[$sup] ?? 0) + count($cl);
            }
        }
        arsort($bySupplier);

        return [
            'kpis' => [
                'ca'         => $sumCa,
                'tickets'    => $sumTk,
                'basket'     => $netBasket,
                'evo'        => $netEvo,
                'shop_count' => count($shops),
            ],
            'lever_defs'   => $leverDefs,
            'shops'        => $shops,
            'objectives'   => [
                'clients' => $objMet['clients'] . '/' . $objTot['clients'],
                'basket'  => $objMet['basket'] . '/' . $objTot['basket'],
                'b2b'     => $objMet['b2b'] . '/' . $objTot['b2b'],
            ],
            'attention'    => $attention,
            'strong'       => $strong,
            'top'          => $top,
            'flop'         => $flop,
            'claims_total' => $claimsTotal,
            'claims_top'   => array_slice($bySupplier, 0, 5, true),
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
    private function franchiseKpisForShop(int $shopId, array $period, array $tgt, array $kpis, array $netFranchise): array
    {
        $caN      = (float)($kpis['ca'] ?? 0);
        $ticketsN = (int)($kpis['tickets'] ?? 0);
        $basketN  = isset($kpis['avg_basket']) && $kpis['avg_basket'] !== null ? (float)$kpis['avg_basket'] : null;

        $fromN1 = date('Y-m-d', (int)strtotime($period['from'] . ' -1 year'));
        $toN1   = date('Y-m-d', (int)strtotime($period['to'] . ' -1 year'));
        $kpisN1 = $this->shopService->getSalesKpis($shopId, $fromN1, $toN1);
        $caN1      = (float)($kpisN1['ca'] ?? 0);
        $ticketsN1 = (int)($kpisN1['tickets'] ?? 0);
        $basketN1  = isset($kpisN1['avg_basket']) && $kpisN1['avg_basket'] !== null ? (float)$kpisN1['avg_basket'] : null;

        [$targets, $label] = $this->bestTargets($shopId, $tgt);

        // Objectif de compteur mis à l'échelle de la période (targets mensuels) :
        // un mois complet = objectif mensuel tel quel (comme /targets) ; une
        // semaine = objectif mensuel prorata des jours. Le panier moyen (une
        // moyenne) n'est jamais mis à l'échelle.
        $days  = max(1, (int)round((strtotime($period['to']) - strtotime($period['from'])) / 86400) + 1);
        $scale = $days >= 28 ? 1.0 : $days / 30.44;

        $mCa      = $this->findFranchiseMetric($targets, ['revenue', 'turnover', 'chiffre', 'sales', 'obrot', 'sprzeda']);
        $mClients = $this->findFranchiseMetric($targets, ['client', 'ticket', 'trafic', 'traffic', 'visit']);
        $mBasket  = $this->findFranchiseMetric($targets, ['basket', 'panier', 'koszyk', 'avg_ticket']);
        $mB2b     = $this->findFranchiseMetric($targets, ['b2b']);

        $objCa      = $mCa      ? $this->scaleCount($this->objectiveOfEntry($mCa), $scale) : null;
        $objClients = $mClients ? $this->scaleCount($this->objectiveOfEntry($mClients), $scale) : null;
        $objBasket  = $mBasket  ? $this->objectiveOfEntry($mBasket) : null;
        $objB2b     = $mB2b     ? $this->scaleCount($this->objectiveOfEntry($mB2b), $scale) : null;
        $valB2b     = $mB2b     ? $this->encodedValueOf($mB2b) : null;

        // Ordre demandé : Chiffre d'affaires · Clients · Panier moyen · Clients B2B.
        // `dec` = nombre de décimales d'affichage (CA en entier, panier à 2 déc.).
        $kpisOut = [
            ['label' => "Chiffre d'affaires", 'unit' => 'amount', 'dec' => 0, 'n1' => $caN1 ?: null,      'val' => $caN ?: null,      'obj' => $objCa,      'net' => $netFranchise['ca']      ?? null],
            ['label' => 'Clients',            'unit' => 'count',  'dec' => 0, 'n1' => $ticketsN1 ?: null, 'val' => $ticketsN ?: null, 'obj' => $objClients, 'net' => $netFranchise['clients'] ?? null],
            ['label' => 'Panier moyen',       'unit' => 'amount', 'dec' => 2, 'n1' => $basketN1,          'val' => $basketN,          'obj' => $objBasket,  'net' => $netFranchise['basket']  ?? null],
            ['label' => 'Clients B2B',        'unit' => 'count',  'dec' => 0, 'n1' => null,               'val' => $valB2b,           'obj' => $objB2b,     'net' => $netFranchise['b2b']     ?? null],
        ];
        foreach ($kpisOut as &$k) {
            $k['status'] = $this->targetStatus($k['val'], $k['obj']);
            // Taux d'atteinte de l'objectif en % (réalisé ÷ objectif).
            $k['pct'] = ($k['val'] !== null && $k['obj'] !== null && $k['obj'] > 0)
                ? ($k['val'] / $k['obj']) * 100 : null;
            // Évolution vs N-1 en %.
            $k['evo'] = ($k['n1'] !== null && $k['n1'] > 0 && $k['val'] !== null)
                ? (($k['val'] - $k['n1']) / $k['n1']) * 100 : null;
            // Écart de la boutique vs la moyenne RÉSEAU en %.
            $k['delta'] = ($k['net'] !== null && $k['net'] > 0 && $k['val'] !== null)
                ? (($k['val'] - $k['net']) / $k['net']) * 100 : null;
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

    /**
     * Sous-tableau de seuils RETENU d'une entrée target — exactement comme
     * l'écran /targets (franchiseData) : seuils ENCODÉS par le consultant,
     * sinon par l'admin. Le défaut franchiseur (sous-clé `default`) est
     * volontairement IGNORÉ : ce sont des gabarits non calibrés par boutique,
     * qui produisent des pourcentages absurdes (ex. 1500 clients/mois pour une
     * boutique qui en fait 8500). À défaut d'override → l'entrée à plat (forme
     * legacy t1/t2/t3), qui ne contient aucun seuil quand seul `default` existe.
     */
    private function overrideSource(array $entry): array
    {
        if (isset($entry['consultant']) && is_array($entry['consultant'])) {
            return $entry['consultant'];
        }
        if (isset($entry['admin']) && is_array($entry['admin'])) {
            return $entry['admin'];
        }
        return $entry;
    }

    /**
     * Objectif = meilleur seuil ENCODÉ (max, ou min si « plus bas = mieux »),
     * repéré par clé threshold/seuil/tN — mêmes règles que /targets::objectiveOf.
     * Renvoie null quand la boutique n'a pas de target encodée (défaut ignoré).
     */
    private function objectiveOfEntry(array $entry): ?float
    {
        $src  = $this->overrideSource($entry);
        $vals = [];
        foreach ($src as $k => $v) {
            if (is_numeric($v) && preg_match('/threshold|seuil|t\d|target|objective|goal/i', (string)$k)) {
                $vals[] = (float)$v;
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
        $src = $this->overrideSource($entry);
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
        // Les plus récentes d'abord : à l'intérieur de chaque fournisseur, puis
        // les fournisseurs eux-mêmes (celui dont la réclamation la plus récente
        // est la plus récente en tête).
        $recent = fn($a, $b) => strcmp((string)($b['reported_at'] ?? ''), (string)($a['reported_at'] ?? ''));
        foreach ($out as &$claims) {
            usort($claims, $recent);
        }
        unset($claims);
        uasort($out, fn($a, $b) => strcmp((string)($b[0]['reported_at'] ?? ''), (string)($a[0]['reported_at'] ?? '')));
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

        // Les réalisations partent EN LOT. Une par une, trente tâches faites
        // coûtaient trente attentes réseau : à 10 s de délai par appel, la
        // passerelle coupait avant la fin et la section revenait vide.
        $byTask = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || empty($task['is_done'])) {
                continue;
            }
            $completionId = (int)($task['completion_id'] ?? 0);
            if ($completionId > 0) {
                $byTask[$completionId] = $task;
            }
        }
        // Le plafond protège d'une liste démesurée. Il s'applique aux appels,
        // pas au résultat : la troncature est donc SIGNALÉE plus bas plutôt que
        // silencieuse — une section courte se lit sinon comme une section
        // complète.
        $this->tasksDoneDiag = [
            'done'      => count($byTask),
            'resolved'  => 0,
            'truncated' => count($byTask) > self::MAX_COMPLETIONS,
        ];
        if ($this->tasksDoneDiag['truncated']) {
            $byTask = array_slice($byTask, 0, self::MAX_COMPLETIONS, true);
        }
        $completions = $byTask !== [] ? $this->taskService->getCompletionsMany(array_keys($byTask)) : [];
        $this->tasksDoneDiag['resolved'] = count(array_filter($completions));

        $out = [];
        foreach ($byTask as $completionId => $task) {
            $completion = $completions[$completionId] ?? [];
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
     * Ce que le rapport sait sur la section « Tâches réalisées » quand elle est
     * vide : combien de tâches sont marquées faites, combien de réalisations
     * ont pu être lues, et si la liste a été tronquée.
     */
    public function tasksDoneDiagnostics(): array
    {
        return $this->tasksDoneDiag;
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

        // Labour et Overhead en % du CA — sinon les leviers L et O restent
        // TOUJOURS gris (aucune métrique). Source : snapshot du mois de la
        // période (mac_shop_monthly_pnl), sinon P&L du mois courant.
        [$labourPct, $overheadPct] = $this->costRatiosForPeriod($shopId, $period);

        // Expérience client : réclamations de la période pour 1 000 tickets
        // (moins = mieux) — seule mesure « client » disponible côté panel.
        $claimsPer1k = null;
        if ($tickets > 0) {
            $n = 0;
            $fromT = (int)strtotime($period['from'] . ' 00:00:00');
            $toT   = (int)strtotime($period['to'] . ' 23:59:59');
            foreach ($this->claimsBySupplier($shopId, $fromT, $toT) as $claims) {
                $n += count($claims);
            }
            $claimsPer1k = $n / $tickets * 1000;
        }

        return [
            'ticketsDay'  => $ticketsDay,
            'avgBasket'   => $basket !== null ? (float)$basket : null,
            'foodPct'     => $foodPct,
            'grossPct'    => $grossPct,
            'evo'         => $evo,
            'labourPct'   => $labourPct,
            'overheadPct' => $overheadPct,
            'claimsPer1k' => $claimsPer1k,
        ];
    }

    /**
     * Labour et Overhead en % du CA pour la période — snapshot du mois
     * (mac_shop_monthly_pnl) en priorité, sinon P&L du mois courant.
     *
     * @return array{0: ?float, 1: ?float} [labour %, overhead %]
     */
    private function costRatiosForPeriod(int $shopId, array $period): array
    {
        $ref  = new \DateTimeImmutable($period['from']);
        $snap = $this->pnlSnapshots->forShopMonth($shopId, (int)$ref->format('Y'), (int)$ref->format('n'));
        if (is_array($snap) && ($snap['ca'] ?? null) !== null && (float)$snap['ca'] > 0) {
            $ca  = (float)$snap['ca'];
            $lab = ($snap['labour'] ?? null) !== null ? (float)$snap['labour'] / $ca * 100 : null;
            $ovh = ($snap['overhead'] ?? null) !== null ? (float)$snap['overhead'] / $ca * 100 : null;
            if ($lab !== null || $ovh !== null) {
                return [$lab, $ovh];
            }
        }
        $pnl = $this->shopService->getPnl($shopId, 'month');
        $ca  = $this->pnlNodeValue($pnl['turnover'] ?? null);
        if ($ca === null || $ca <= 0) {
            return [null, null];
        }
        $lab = $this->pnlNodeValue($pnl['labour'] ?? null);
        $ovh = $this->pnlNodeValue($pnl['overhead'] ?? null);
        return [
            $lab !== null ? $lab / $ca * 100 : null,
            $ovh !== null ? $ovh / $ca * 100 : null,
        ];
    }

    /** Moyenne réseau de chaque métrique (boutiques ayant une valeur). */
    private function networkAverages(array $metricsByShop): array
    {
        $avg = [];
        foreach (['ticketsDay', 'avgBasket', 'foodPct', 'grossPct', 'evo', 'labourPct', 'overheadPct', 'claimsPer1k'] as $k) {
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
            ['num' => 2, 'key' => 'xp',         'letter' => 'E', 'name' => 'Expérience client', 'color' => '#2D7A3E', 'kpis' => [
                ['metric' => 'claimsPer1k', 'dir' => -1, 'label' => 'Réclamations / 1 000 tickets', 'fmt' => 'dec1'],
            ]],
            ['num' => 5, 'key' => 'food',       'letter' => 'F', 'name' => 'Food Cost',         'color' => '#C9A227', 'kpis' => [
                ['metric' => 'foodPct',    'dir' => -1, 'label' => 'Coût matière (% CA)', 'fmt' => 'pct'],
                ['metric' => 'grossPct',   'dir' => 1,  'label' => 'Marge brute (% CA)',  'fmt' => 'pct'],
            ]],
            ['num' => 6, 'key' => 'labour',     'letter' => 'L', 'name' => 'Labour Cost',       'color' => '#8D1D2C', 'kpis' => [
                ['metric' => 'labourPct',  'dir' => -1, 'label' => 'Labour (% CA)', 'fmt' => 'pct'],
            ]],
            ['num' => 7, 'key' => 'overhead',   'letter' => 'O', 'name' => 'Overhead Cost',     'color' => '#7a7168', 'kpis' => [
                ['metric' => 'overheadPct', 'dir' => -1, 'label' => 'Overhead (% CA)', 'fmt' => 'pct'],
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
            'dec1'  => number_format($v, 1, ',', ' '),
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
