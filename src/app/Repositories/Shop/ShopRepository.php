<?php
namespace App\Consultant\app\Repositories\Shop;

use App\Consultant\core\Http\ApiClient;

class ShopRepository
{
    public function __construct(private ApiClient $apiClient) {}

    /**
     * Pobiera listę wszystkich sklepów.
     * Endpoint: GET /consultant/shops
     */
    public function getAllShops(): array
    {
        $response = $this->apiClient->get('/consultant/shops');
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    /**
     * P&L jednego sklepu za dany okres (day|week|month).
     * Endpoint: GET /consultant/shops/{id}/pnl?period=day
     * Zwraca: turnover{value,delta,categories}, labour, overhead, result.
     */
    public function getPnl(int $shopId, string $period = 'day'): array
    {
        $response = $this->apiClient->get('/consultant/shops/' . $shopId . '/pnl?period=' . urlencode($period));
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    /**
     * P&L de PLUSIEURS magasins pour une période, en UN SEUL aller-retour
     * parallèle (curl_multi) au lieu de N appels séquentiels. Remède au N+1 du
     * dashboard (« CA du jour »).
     *
     * @param int[] $shopIds
     * @return array<int, array> map shopId => données P&L (ou [] si indisponible).
     */
    public function getPnlMany(array $shopIds, string $period = 'day'): array
    {
        if ($shopIds === []) {
            return [];
        }

        $byEndpoint = [];
        foreach ($shopIds as $id) {
            $id = (int)$id;
            $byEndpoint[$id] = '/consultant/shops/' . $id . '/pnl?period=' . urlencode($period);
        }

        $responses = $this->apiClient->getMany(array_values($byEndpoint));

        $out = [];
        foreach ($byEndpoint as $id => $ep) {
            $r = $responses[$ep] ?? [];
            $out[$id] = (!empty($r['success']) && isset($r['data']) && is_array($r['data'])) ? $r['data'] : [];
        }
        return $out;
    }

    /**
     * KPI de vente d'un magasin depuis l'API BACKEND (source de vérité
     * demandée) : GET /shops/{id}/statistics/sales/kpis?date_from&date_to
     * → { tickets, ca, products, avg_basket, products_per_ticket }.
     * Endpoint spécifié avec le backend ; tant qu'il n'est pas déployé
     * (404/erreur), renvoie null et l'appelant replie sur le calcul local
     * identique (ShopSalesRepository::getSalesKpis). Clés tolérées :
     * tickets/transactions, ca/turnover/sales, products/items.
     *
     * @return array{tickets:int, ca:float, products:int, avg_basket:?float, products_per_ticket:?float}|null
     */
    /** Endpoint backend absent (404) → plus de sonde dans cette requête. */
    private static bool $kpiApiMissing = false;

    public function getSalesKpisFromApi(int $shopId, string $fromDate, string $toDate): ?array
    {
        if (self::$kpiApiMissing) {
            return null;
        }
        $response = $this->apiClient->get($this->salesKpisEndpoint($shopId, $fromDate, $toDate));
        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            if (($response['error'] ?? null) === 404) {
                self::$kpiApiMissing = true;
            }
            return null;
        }
        return $this->parseSalesKpisPayload($response['data']);
    }

    /**
     * KPI de vente pour PLUSIEURS fenêtres (magasin, from, to) en parallèle
     * (curl_multi) — pour les vues multi-mois (Tendances). Même endpoint et
     * même tolérance de schéma que getSalesKpisFromApi.
     *
     * @param array $windows liste de ['shop'=>int,'from'=>'Y-m-d','to'=>'Y-m-d']
     * @return array<string, ?array> map "shop|from|to" => KPIs ou null (repli local)
     */
    public function getSalesKpisManyFromApi(array $windows): array
    {
        $out = [];
        if ($windows === []) {
            return $out;
        }
        $byKey = [];
        foreach ($windows as $w) {
            $key = (int)($w['shop'] ?? 0) . '|' . ($w['from'] ?? '') . '|' . ($w['to'] ?? '');
            $out[$key] = null;
            $byKey[$key] = $this->salesKpisEndpoint((int)($w['shop'] ?? 0), (string)($w['from'] ?? ''), (string)($w['to'] ?? ''));
        }
        if (self::$kpiApiMissing) {
            return $out;
        }
        // Par paquets : des dizaines de fenêtres ne doivent pas ouvrir autant
        // de connexions simultanées vers l'API. Un 404 (endpoint absent) coupe
        // court : inutile d'envoyer les paquets suivants.
        $responses = [];
        // Chaque paquet est un aller-retour : des paquets trop petits font
        // autant d'attentes en série (12 mois × N boutiques dépassaient le
        // délai de la passerelle avant de rendre l'écran Tendances).
        foreach (array_chunk(array_values(array_unique($byKey)), 48) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
            foreach ($chunk as $ep) {
                if ((($responses[$ep]['error'] ?? null)) === 404) {
                    self::$kpiApiMissing = true;
                }
            }
            if (self::$kpiApiMissing) {
                break;
            }
        }
        foreach ($byKey as $key => $ep) {
            $r = $responses[$ep] ?? null;
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $out[$key] = $this->parseSalesKpisPayload($r['data']);
            }
        }
        return $out;
    }

    private function salesKpisEndpoint(int $shopId, string $fromDate, string $toDate): string
    {
        return '/shops/' . $shopId . '/statistics/sales/kpis'
            . '?date_from=' . urlencode($fromDate) . '&date_to=' . urlencode($toDate);
    }

    /** @return array{tickets:int, ca:float, products:int, avg_basket:?float, products_per_ticket:?float}|null */
    private function parseSalesKpisPayload(array $d): ?array
    {
        // Certains backends enveloppent encore dans data/kpis.
        foreach (['data', 'kpis'] as $wrap) {
            if (isset($d[$wrap]) && is_array($d[$wrap])) {
                $d = $d[$wrap];
            }
        }

        $pick = function (array $keys) use ($d) {
            foreach ($keys as $k) {
                if (isset($d[$k]) && is_numeric($d[$k])) {
                    return (float)$d[$k];
                }
            }
            return null;
        };

        $tickets = $pick(['tickets', 'tickets_count', 'transactions', 'transactions_count']);
        $ca      = $pick(['ca', 'turnover', 'sales', 'revenue', 'total']);
        if ($tickets === null || $ca === null) {
            return null; // schéma inattendu → repli local
        }
        $products = $pick(['products', 'products_count', 'items', 'quantity']) ?? 0.0;
        $basket   = $pick(['avg_basket', 'average_basket', 'basket']);
        $ppt      = $pick(['products_per_ticket', 'avg_products', 'items_per_ticket']);

        $t = (int)round($tickets);
        return [
            'tickets'             => $t,
            'ca'                  => $ca,
            'products'            => (int)round($products),
            'avg_basket'          => $basket ?? ($t > 0 ? $ca / $t : null),
            'products_per_ticket' => $ppt ?? (($t > 0 && $products > 0) ? $products / $t : null),
        ];
    }

    /**
     * Coût matière TOTAL d'un magasin sur une fenêtre [from, to] (Y-m-d) —
     * MÊME source que l'écran HEXm/Boutiques : somme du coût matière par
     * catégorie (product-category-groups), repli daily-summary. Sert au levier
     * Food Cost du rapport. null si indisponible (le rapport affiche « à venir »).
     */
    public function getMaterialCost(int $shopId, string $from, string $to): ?float
    {
        foreach (['category', 'group', 'month'] as $grouping) {
            $res = $this->apiClient->get(
                '/shops/' . $shopId . '/statistics/sales/product-category-groups'
                . '?date_from=' . urlencode($from) . '&date_to=' . urlencode($to) . '&grouping=' . $grouping
            );
            if (!empty($res['success']) && is_array($res['data'] ?? null)) {
                $total = $this->sumMaterialCost($res['data']);
                if ($total !== null && $total > 0) {
                    return $total;
                }
            }
        }
        $res = $this->apiClient->get(
            '/shops/' . $shopId . '/statistics/daily-summary'
            . '?date_from=' . urlencode($from) . '&date_to=' . urlencode($to)
        );
        if (!empty($res['success']) && is_array($res['data'] ?? null)) {
            $total = $this->sumMaterialCost($res['data']);
            return ($total !== null && $total > 0) ? $total : null;
        }
        return null;
    }

    /**
     * Somme le coût matière des nœuds « catégorie » d'une réponse imbriquée
     * (port PHP de l'extraction de l'écran HEXm) : pour chaque nœud nommé, on
     * prend UNE seule clé de coût (material_cost prioritaire), en ignorant les
     * clés de ratio/quantité, pour ne pas doubler. null si aucun coût trouvé.
     */
    private function sumMaterialCost($data): ?float
    {
        $costPrefs = ['material_cost', 'materials_cost', 'food_cost', 'goods_cost', 'cost_of_goods', 'purchase_cost', 'total_cost', 'cost'];
        $skip = fn($k) => (bool)preg_match('/(pct|percent|ratio|rate|delta|margin|qty|quantity|count)/i', (string)$k);
        $numOf = function ($v): ?float {
            if (is_int($v) || is_float($v)) return (float)$v;
            if (is_string($v) && $v !== '' && is_numeric($v)) return (float)$v;
            if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) return (float)$v['value'];
            return null;
        };

        $total = 0.0;
        $found = false;
        $walk = function ($n) use (&$walk, &$total, &$found, $costPrefs, $skip, $numOf) {
            if (!is_array($n)) {
                return;
            }
            if (array_is_list($n)) {
                foreach ($n as $x) {
                    $walk($x);
                }
                return;
            }
            $name = null;
            foreach (['category_name', 'category', 'group_name', 'name', 'label'] as $k) {
                if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                    $name = trim($n[$k]);
                    break;
                }
            }
            if ($name !== null) {
                $cost = null;
                foreach ($costPrefs as $pref) {
                    foreach ($n as $k => $v) {
                        if ($skip($k)) continue;
                        if (strtolower((string)$k) === $pref) {
                            $x = $numOf($v);
                            if ($x !== null) { $cost = $x; break 2; }
                        }
                    }
                }
                if ($cost === null) {
                    foreach ($n as $k => $v) {
                        if ($skip($k)) continue;
                        if (preg_match('/cost/i', (string)$k)) {
                            $x = $numOf($v);
                            if ($x !== null) { $cost = $x; break; }
                        }
                    }
                }
                if ($cost !== null) { $total += $cost; $found = true; }
            }
            foreach ($n as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);

        return $found ? $total : null;
    }

    // ─────────────────────── Labour réel par jour ───────────────────────

    /** Endpoint backend absent (404) → plus de sonde dans cette requête. */
    private static bool $dailyLabourMissing = false;

    /**
     * Labour RÉEL par jour (planning/pointage) : GET
     * /consultant/shops/{id}/labour/daily?from&to → { days:[{date, labour}] }.
     * Endpoint optionnel — tant qu'il n'est pas déployé côté backend, renvoie
     * null et la heatmap de rentabilité répartit le labour du mois par jour
     * d'ouverture. Clés tolérées : labour / labour_cost / labor / cost.
     *
     * @return array<string, array{labour: float}>|null map 'Y-m-d' => labour €
     */
    public function getDailyLabour(int $shopId, string $from, string $to): ?array
    {
        if (self::$dailyLabourMissing) {
            return null;
        }
        $res = $this->apiClient->get(
            '/consultant/shops/' . $shopId . '/labour/daily'
            . '?from=' . urlencode($from) . '&to=' . urlencode($to)
        );
        if (empty($res['success']) || !is_array($res['data'] ?? null)) {
            if (($res['error'] ?? null) === 404) {
                self::$dailyLabourMissing = true;
            }
            return null;
        }
        $d = $res['data'];
        foreach (['data', 'days'] as $wrap) {
            if (isset($d[$wrap]) && is_array($d[$wrap])) {
                $d = $d[$wrap];
            }
        }
        $out = [];
        foreach ($d as $row) {
            if (!is_array($row) || empty($row['date'])) {
                continue;
            }
            foreach (['labour', 'labour_cost', 'labor', 'cost'] as $k) {
                if (isset($row[$k]) && is_numeric($row[$k])) {
                    $out[substr((string)$row['date'], 0, 10)] = ['labour' => (float)$row[$k]];
                    break;
                }
            }
        }
        return $out !== [] ? $out : null;
    }

    // ───────────────────────── Heatmap de marge ─────────────────────────

    /**
     * Carte de marge brute d'un magasin (jours + heures) sur [from, to]
     * (≤ 31 jours) : GET /consultant/shops/{id}/margin-heatmap?from&to
     * → { totals, days:[{date, margin_pct, ca…}], hours:[{hour, ca,
     * margin_value, margin_pct…}] }. null si indisponible.
     */
    public function getMarginHeatmap(int $shopId, string $from, string $to, bool $weekly = false): ?array
    {
        $ep = $this->marginHeatmapEndpoint($shopId, $from, $to);
        if ($weekly) {
            $ep .= '&split=weekly';   // P4 : découpage hebdo fourni par l'API
        }
        $response = $this->apiClient->get($ep);
        return (!empty($response['success']) && is_array($response['data'] ?? null)) ? $response['data'] : null;
    }

    /**
     * Cartes de marge pour PLUSIEURS fenêtres (magasin, from, to) en parallèle
     * (ex. les semaines d'un mois pour les créneaux matin/midi/après-midi).
     *
     * @param array $windows liste de ['shop'=>int,'from'=>'Y-m-d','to'=>'Y-m-d']
     * @return array<string, ?array> map "shop|from|to" => données ou null
     */
    public function getMarginHeatmapMany(array $windows): array
    {
        $out = [];
        if ($windows === []) {
            return $out;
        }
        $byKey = [];
        foreach ($windows as $w) {
            $key = (int)($w['shop'] ?? 0) . '|' . ($w['from'] ?? '') . '|' . ($w['to'] ?? '');
            $out[$key] = null;
            $byKey[$key] = $this->marginHeatmapEndpoint((int)($w['shop'] ?? 0), (string)($w['from'] ?? ''), (string)($w['to'] ?? ''));
        }
        $responses = [];
        foreach (array_chunk(array_values(array_unique($byKey)), 24) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        foreach ($byKey as $key => $ep) {
            $r = $responses[$ep] ?? null;
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $out[$key] = $r['data'];
            }
        }
        return $out;
    }

    private function marginHeatmapEndpoint(int $shopId, string $from, string $to): string
    {
        return '/consultant/shops/' . $shopId . '/margin-heatmap'
            . '?from=' . urlencode($from) . '&to=' . urlencode($to);
    }

    // ─────────────────── Ventes par catégorie (mix) ───────────────────

    /**
     * Ventes par CATÉGORIE d'un magasin sur [from, to] — même source que le
     * treemap Boutiques (product-category-groups), extraction tolérante par
     * entrée nommée (le schéma exact varie). Map nom => CA. null si aucune
     * ventilation exploitable.
     *
     * @return array<string, float>|null
     */
    public function getCategorySales(int $shopId, string $from, string $to): ?array
    {
        foreach (['category', 'group', 'month'] as $grouping) {
            $res = $this->apiClient->get($this->categoryGroupsEndpoint($shopId, $from, $to, $grouping));
            if (empty($res['success']) || !is_array($res['data'] ?? null)) {
                continue;
            }
            $mix = $this->extractCategorySales($res['data']);
            if ($mix !== null) {
                return $mix;
            }
        }
        return null;
    }

    /**
     * Ventes par catégorie pour PLUSIEURS magasins sur la même fenêtre, en
     * parallèle (grouping=category) — repli séquentiel complet (getCategorySales)
     * pour les magasins sans résultat.
     *
     * @param int[] $shopIds
     * @return array<int, ?array> map shopId => (nom => CA) ou null
     */
    public function getCategorySalesMany(array $shopIds, string $from, string $to): array
    {
        // P7 : un seul appel multi-boutiques quand l'endpoint est déployé.
        $all = $this->getCategorySalesAllShops($from, $to);
        if ($all !== null) {
            $out = [];
            foreach ($shopIds as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $out[$id] = $all[$id] ?? null;
                }
            }
            return $out;
        }
        $out = [];
        $byId = [];
        foreach ($shopIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = null;
                $byId[$id] = $this->categoryGroupsEndpoint($id, $from, $to, 'category');
            }
        }
        $responses = [];
        foreach (array_chunk(array_values($byId), 24) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        foreach ($byId as $id => $ep) {
            $r = $responses[$ep] ?? null;
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $out[$id] = $this->extractCategorySales($r['data']);
            }
            if ($out[$id] === null) {
                $out[$id] = $this->getCategorySales($id, $from, $to);
            }
        }
        return $out;
    }

    private function categoryGroupsEndpoint(int $shopId, string $from, string $to, string $grouping): string
    {
        return '/shops/' . $shopId . '/statistics/sales/product-category-groups'
            . '?date_from=' . urlencode($from) . '&date_to=' . urlencode($to)
            . '&grouping=' . urlencode($grouping);
    }

    /**
     * Extraction tolérante des VENTES par catégorie — pendant PHP de
     * extractCategoryCosts (shop/list.twig) : entrées nommées, première clé
     * « ventes » plausible (jamais coût/pct/quantité), sommées par nom.
     *
     * @return array<string, float>|null null si aucune entrée exploitable
     */
    private function extractCategorySales($data): ?array
    {
        $map   = [];
        $found = false;

        $numOf = function ($v): ?float {
            if (is_int($v) || is_float($v)) {
                return (float)$v;
            }
            if (is_string($v) && $v !== '' && is_numeric($v)) {
                return (float)$v;
            }
            if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) {
                return (float)$v['value'];
            }
            return null;
        };
        $salesPrefs = ['turnover', 'sales', 'revenue', 'gross', 'value', 'amount', 'total', 'net'];
        $skip = fn($k) => preg_match('/cost|pct|percent|ratio|rate|delta|margin|qty|quantity|count/i', (string)$k);

        $walk = function ($n) use (&$walk, &$map, &$found, $numOf, $salesPrefs, $skip) {
            if (!is_array($n)) {
                return;
            }
            $name = null;
            foreach (['category_name', 'category', 'group_name', 'name', 'label'] as $k) {
                if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                    $name = trim($n[$k]);
                    break;
                }
            }
            if ($name !== null) {
                $sales = null;
                foreach ($salesPrefs as $pref) {
                    foreach ($n as $k => $v) {
                        if ($skip($k)) {
                            continue;
                        }
                        if (stripos((string)$k, $pref) !== false) {
                            $x = $numOf($v);
                            if ($x !== null) {
                                $sales = $x;
                                break 2;
                            }
                        }
                    }
                }
                if ($sales !== null) {
                    $key = mb_strtolower($name);
                    $map[$key] = ($map[$key] ?? ['name' => $name, 'sales' => 0.0]);
                    $map[$key]['sales'] += $sales;
                    $found = true;
                }
            }
            foreach ($n as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);

        if (!$found) {
            return null;
        }
        $out = [];
        foreach ($map as $e) {
            $out[$e['name']] = $e['sales'];
        }
        return $out;
    }

    // ══════════════ Endpoints batch backend (spec P0–P8) ══════════════
    // Déployés le 30/07/2026. Chaque méthode renvoie null si l'endpoint est
    // absent (404) → l'appelant garde son mécanisme actuel : le panel reste
    // fonctionnel quel que soit l'état du backend.

    /** Endpoints batch indisponibles → plus de sonde dans cette requête. */
    private static array $batchMissing = [];

    /** Journal des sondes batch (statut + durée) pour les écrans ?debug=1. */
    private static array $batchLog = [];

    /**
     * Disjoncteur : un endpoint batch qui échoue n'est plus sondé pendant un
     * court moment.
     *
     * C'est vital. Le timeout cURL est de 10 s : sans disjoncteur, un endpoint
     * lent ou en erreur coûte 10 s À CHAQUE sonde — la valorisation en fait une
     * par boutique et le repli des tendances une par fenêtre, soit plusieurs
     * minutes de mur avant même de commencer à calculer. Les replis donnent des
     * données valides, donc renoncer vite est toujours préférable à insister.
     */
    private const BREAKER_TTL_ABSENT = 1800;  // 404 : l'endpoint n'existe pas
    private const BREAKER_TTL_FAIL   = 300;   // timeout / 5xx / réponse illisible

    private static function breakerPath(): string
    {
        return sys_get_temp_dir() . '/pwa_consultant_batch_breaker.json';
    }

    /** État du disjoncteur : lu une fois sur disque, tenu à jour en mémoire. */
    private static ?array $breaker = null;

    /** @return array<string, array{until:int, why:string}> */
    private static function breakerCurrent(): array
    {
        if (self::$breaker === null) {
            $raw   = @file_get_contents(self::breakerPath());
            $state = ($raw !== false ? json_decode($raw, true) : null) ?: [];
            if (!is_array($state)) {
                $state = [];
            }
            foreach ($state as $k => $e) {
                if (!is_array($e) || (int)($e['until'] ?? 0) <= time()) {
                    unset($state[$k]);   // délai écoulé → on retentera
                }
            }
            self::$breaker = $state;
        }
        return self::$breaker;
    }

    private static function breakerTrip(string $key, int $ttl, string $why): void
    {
        $state       = self::breakerCurrent();
        $state[$key] = ['until' => time() + $ttl, 'why' => $why];
        self::$breaker = $state;
        @file_put_contents(self::breakerPath(), json_encode($state), LOCK_EX);
    }

    /** Réarme tous les endpoints batch (écrans ?fresh=1). */
    public static function batchBreakerReset(): void
    {
        self::$batchMissing = [];
        self::$breaker      = [];
        @unlink(self::breakerPath());
    }

    /** Journal des sondes + disjoncteurs actifs (écrans ?debug=1). */
    /**
     * Capture d'échantillons BRUTS — désactivée par défaut.
     *
     * Diagnostiquer « l'endpoint ne renvoie rien » sans voir sa réponse revient
     * à deviner. Le drapeau est posé par le contrôleur quand `?debug=1` est
     * demandé : la donnée exposée est celle que l'utilisateur a déjà le droit
     * de voir à l'écran, jamais plus.
     */
    private static bool $sampling = false;
    private static array $samples = [];

    public static function sampleOn(): void
    {
        self::$sampling = true;
        self::$samples  = [];
    }

    /** @return array<string, array> map clé => ['endpoint'=>…, 'response'=>…] */
    public static function samples(): array
    {
        return self::$samples;
    }

    /** Un seul échantillon par clé : le premier suffit à lire le format. */
    private static function sample(string $key, string $endpoint, $response): void
    {
        if (!self::$sampling || isset(self::$samples[$key])) {
            return;
        }
        // Réponse tronquée : un P&L de douze mois pour vingt-cinq boutiques
        // rendrait la page de diagnostic illisible et lourde.
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && strlen($json) > 20000) {
            $response = ['_tronque' => true, '_taille_octets' => strlen($json),
                         '_extrait' => substr($json, 0, 20000)];
        }
        self::$samples[$key] = ['endpoint' => $endpoint, 'response' => $response];
    }

    public static function batchDiagnostics(): array
    {
        $breaker = [];
        foreach (self::breakerCurrent() as $k => $e) {
            $breaker[$k] = [
                'why'       => (string)($e['why'] ?? ''),
                'retry_in_s' => max(0, (int)($e['until'] ?? 0) - time()),
            ];
        }
        return ['probes' => self::$batchLog, 'breaker' => $breaker];
    }

    private function batchGet(string $key, string $endpoint): ?array
    {
        if (!empty(self::$batchMissing[$key])) {
            return null;
        }
        $open = self::breakerCurrent()[$key] ?? null;
        if ($open !== null) {
            self::$batchMissing[$key] = true;
            self::$batchLog[] = [
                'key' => $key, 'endpoint' => $endpoint, 'status' => 'breaker',
                'why' => (string)($open['why'] ?? ''), 'ms' => 0,
            ];
            return null;
        }

        $t0 = microtime(true);
        $r  = $this->apiClient->get($endpoint);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if (empty($r['success']) || !is_array($r['data'] ?? null)) {
            // 0 = timeout cURL (10 s) ou hôte injoignable ; [] = pas de code.
            $code = is_int($r['error'] ?? null) ? (int)$r['error'] : 0;
            $why  = $code === 404 ? 'http_404'
                : ($code > 0 ? ('http_' . $code) : (empty($r['success']) ? 'timeout_or_unreachable' : 'bad_payload'));
            // Toute panne — pas seulement un 404 — retire l'endpoint de la
            // requête : re-sonder un endpoint qui vient d'échouer coûte un
            // nouveau timeout pour le même résultat.
            self::$batchMissing[$key] = true;
            // Le disjoncteur persistant ne concerne pas l'authentification :
            // un 401/403 dépend du jeton de l'appelant, pas de l'endpoint.
            if (!in_array($code, [401, 403], true)) {
                self::breakerTrip($key, $code === 404 ? self::BREAKER_TTL_ABSENT : self::BREAKER_TTL_FAIL, $why);
            }
            self::$batchLog[] = ['key' => $key, 'endpoint' => $endpoint, 'status' => $why, 'ms' => $ms];
            return null;
        }
        self::$batchLog[] = ['key' => $key, 'endpoint' => $endpoint, 'status' => 'ok', 'ms' => $ms];
        return $r['data'];
    }

    /**
     * P0 — P&L JOURNALIER d'une boutique (chiffres du backoffice) :
     * days:[{date, revenue, material, labour, overhead, result}].
     *
     * @return array<string, array{revenue: ?float, material: ?float, labour: ?float, overhead: ?float, result: ?float}>|null
     *         map 'Y-m-d' => postes
     */
    public function getDailyPnl(int $shopId, string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'pnl_daily',
            '/consultant/shops/' . $shopId . '/pnl/daily?from=' . urlencode($from) . '&to=' . urlencode($to)
        );
        return $d !== null ? $this->parseDailyPnl($d) : null;
    }

    /** @return array<string, array>|null map 'Y-m-d' => postes du jour */
    private function parseDailyPnl(array $d): ?array
    {
        $num = fn($v) => is_numeric($v) ? (float)$v : null;
        $out = [];
        foreach (($d['days'] ?? $d) as $row) {
            if (!is_array($row) || empty($row['date'])) {
                continue;
            }
            $out[substr((string)$row['date'], 0, 10)] = [
                'revenue'  => $num($row['revenue'] ?? $row['turnover'] ?? null),
                'material' => $num($row['material'] ?? null),
                'labour'   => $num($row['labour'] ?? null),
                'overhead' => $num($row['overhead'] ?? null),
                'result'   => $num($row['result'] ?? null),
            ];
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P0 en LOT : le P&L QUOTIDIEN de plusieurs boutiques en parallèle.
     *
     * C'est la source qu'utilise la heatmap de rentabilité, et celle qui porte
     * réellement le coût matière. Le P&L mensuel (P2) ne le renvoie pas
     * toujours : la valorisation s'en sert alors comme repli pour reconstituer
     * les postes mois par mois plutôt que de lire un `result` incomplet.
     *
     * @param array $windows liste de ['shop'=>int,'from'=>'Y-m-d','to'=>'Y-m-d']
     * @return array<int, array<string, array>> map shopId => (date => postes)
     */
    public function getDailyPnlMany(array $windows): array
    {
        $out = [];
        if ($windows === [] || !empty(self::$batchMissing['pnl_daily'])
            || isset(self::breakerCurrent()['pnl_daily'])) {
            return $out;
        }
        $byShop = [];
        foreach ($windows as $w) {
            $sid = (int)($w['shop'] ?? 0);
            if ($sid > 0) {
                $byShop[$sid] = '/consultant/shops/' . $sid . '/pnl/daily?from='
                    . urlencode((string)($w['from'] ?? '')) . '&to=' . urlencode((string)($w['to'] ?? ''));
            }
        }
        $t0        = microtime(true);
        $responses = [];
        foreach (array_chunk(array_values($byShop), 16) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);

        foreach ($byShop as $sid => $ep) {
            $r = $responses[$ep] ?? null;
            self::sample('pnl_daily', $ep, $r);
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $parsed = $this->parseDailyPnl($r['data']);
                if ($parsed !== null) {
                    $out[$sid] = $parsed;
                }
            }
        }
        self::$batchLog[] = [
            'key'      => 'pnl_daily_many',
            'endpoint' => '/consultant/shops/{id}/pnl/daily × ' . count($byShop) . ' (parallèle)',
            'status'   => $out !== [] ? 'ok (' . count($out) . '/' . count($byShop) . ')' : 'empty',
            'ms'       => $ms,
        ];
        return $out;
    }

    /**
     * P2 — P&L MENSUEL d'une boutique sur [from, to] ('YYYY-MM').
     *
     * @return array<string, array{turnover: ?float, material: ?float, labour: ?float, overhead: ?float, result: ?float}>|null
     *         map 'YYYY-MM' => postes
     */
    public function getMonthlyPnl(int $shopId, string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'pnl_monthly',
            '/consultant/shops/' . $shopId . '/pnl/monthly?from=' . urlencode($from) . '&to=' . urlencode($to)
        );
        return $d !== null ? $this->parseMonthlyPnl($d) : null;
    }

    /**
     * P2 en LOT : le P&L mensuel de plusieurs boutiques en parallèle
     * (curl_multi). Une boucle séquentielle sur `getMonthlyPnl` paierait N fois
     * la latence — et jusqu'à N × 10 s si l'endpoint traîne.
     *
     * @param int[] $shopIds
     * @return array<int, array> map shopId => (map 'YYYY-MM' => postes) — les
     *         boutiques sans données sont absentes.
     */
    public function getMonthlyPnlMany(array $shopIds, string $from, string $to): array
    {
        $shopIds = array_values(array_unique(array_map('intval', $shopIds)));
        if ($shopIds === [] || !empty(self::$batchMissing['pnl_monthly'])
            || isset(self::breakerCurrent()['pnl_monthly'])) {
            self::$batchMissing['pnl_monthly'] = true;
            return [];
        }

        $byId = [];
        foreach ($shopIds as $id) {
            $byId[$id] = '/consultant/shops/' . $id . '/pnl/monthly?from='
                . urlencode($from) . '&to=' . urlencode($to);
        }
        $t0        = microtime(true);
        $responses = [];
        foreach (array_chunk(array_values($byId), 16) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $out   = [];
        $codes = [];
        foreach ($byId as $id => $ep) {
            $r = $responses[$ep] ?? null;
            // Échantillon BRUT de la première boutique, sur demande : c'est ce
            // qui permet de voir si l'endpoint existe, ce qu'il renvoie et sous
            // quel format — sans avoir à configurer quoi que ce soit.
            self::sample('pnl_monthly', $ep, $r);
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $parsed = $this->parseMonthlyPnl($r['data']);
                if ($parsed !== null) {
                    $out[$id] = $parsed;
                    continue;
                }
                $codes[] = 'bad_payload';
                continue;
            }
            $code    = is_int($r['error'] ?? null) ? (int)$r['error'] : 0;
            $codes[] = $code === 404 ? 'http_404'
                : ($code > 0 ? ('http_' . $code) : 'timeout_or_unreachable');
        }

        // Aucune boutique servie → l'endpoint est indisponible : on ouvre le
        // disjoncteur pour que l'appelant reparte tout de suite sur son repli.
        if ($out === [] && $codes !== []) {
            $why = (string)array_key_first(array_count_values($codes));
            self::$batchMissing['pnl_monthly'] = true;
            if (!in_array($why, ['http_401', 'http_403'], true)) {
                self::breakerTrip(
                    'pnl_monthly',
                    $why === 'http_404' ? self::BREAKER_TTL_ABSENT : self::BREAKER_TTL_FAIL,
                    $why
                );
            }
        }
        self::$batchLog[] = [
            'key'      => 'pnl_monthly',
            'endpoint' => '/consultant/shops/{id}/pnl/monthly × ' . count($byId),
            'status'   => $out !== [] ? 'ok (' . count($out) . '/' . count($byId) . ')' : ($codes[0] ?? 'empty'),
            'ms'       => $ms,
        ];
        return $out;
    }

    /** @return array<string, array>|null map 'YYYY-MM' => postes P&L */
    private function parseMonthlyPnl(array $d): ?array
    {
        $num = fn($v) => is_numeric($v) ? (float)$v : null;
        $out = [];
        foreach (($d['months'] ?? $d) as $row) {
            if (!is_array($row) || empty($row['month'])) {
                continue;
            }
            $out[substr((string)$row['month'], 0, 7)] = [
                'turnover' => $num($row['turnover'] ?? $row['revenue'] ?? null),
                'material' => $num($row['material'] ?? null),
                'labour'   => $num($row['labour'] ?? null),
                'overhead' => $num($row['overhead'] ?? null),
                'result'   => $num($row['result'] ?? null),
            ];
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P1 — Série de ventes MENSUELLES de toutes les boutiques sur [from, to]
     * ('YYYY-MM') : un seul appel au lieu d'une fenêtre par mois et par
     * boutique.
     *
     * @return array<int, array<string, array{ca: float, tickets: int}>>|null
     *         map shopId => ('YYYY-MM' => KPIs)
     */
    public function getMonthlySalesAllShops(string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'monthly_sales',
            '/consultant/shops/monthly-sales?from=' . urlencode($from) . '&to=' . urlencode($to)
        );
        return $d !== null ? $this->parseMonthlySales($d) : null;
    }

    /**
     * P1 sur PLUSIEURS plages en un aller-retour (curl_multi) — les tendances
     * demandent la fenêtre N et son équivalent N-1 ; en séquence, c'est deux
     * attentes réseau pour rien.
     *
     * @param array<int, array{0: string, 1: string}> $ranges couples [from, to] en 'YYYY-MM'
     * @return array<string, array|null> map 'from|to' => série (ou null)
     */
    public function getMonthlySalesRanges(array $ranges): array
    {
        $out = [];
        foreach ($ranges as [$from, $to]) {
            $out["{$from}|{$to}"] = null;
        }
        if ($ranges === [] || !empty(self::$batchMissing['monthly_sales'])
            || isset(self::breakerCurrent()['monthly_sales'])) {
            self::$batchMissing['monthly_sales'] = true;
            return $out;
        }

        $byKey = [];
        foreach ($ranges as [$from, $to]) {
            $byKey["{$from}|{$to}"] = '/consultant/shops/monthly-sales?from='
                . urlencode($from) . '&to=' . urlencode($to);
        }
        $t0        = microtime(true);
        $responses = $this->apiClient->getMany(array_values($byKey));
        $ms        = (int)round((microtime(true) - $t0) * 1000);

        $codes = [];
        foreach ($byKey as $key => $ep) {
            $r = $responses[$ep] ?? null;
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $parsed = $this->parseMonthlySales($r['data']);
                if ($parsed !== null) {
                    $out[$key] = $parsed;
                    continue;
                }
                $codes[] = 'bad_payload';
                continue;
            }
            $code    = is_int($r['error'] ?? null) ? (int)$r['error'] : 0;
            $codes[] = $code === 404 ? 'http_404' : ($code > 0 ? ('http_' . $code) : 'timeout_or_unreachable');
        }

        if (array_filter($out) === [] && $codes !== []) {
            $why = (string)array_key_first(array_count_values($codes));
            self::$batchMissing['monthly_sales'] = true;
            if (!in_array($why, ['http_401', 'http_403'], true)) {
                self::breakerTrip(
                    'monthly_sales',
                    $why === 'http_404' ? self::BREAKER_TTL_ABSENT : self::BREAKER_TTL_FAIL,
                    $why
                );
            }
        }
        self::$batchLog[] = [
            'key'      => 'monthly_sales',
            'endpoint' => '/consultant/shops/monthly-sales × ' . count($byKey) . ' (parallèle)',
            'status'   => array_filter($out) !== [] ? 'ok (' . count(array_filter($out)) . '/' . count($byKey) . ')' : ($codes[0] ?? 'empty'),
            'ms'       => $ms,
        ];
        return $out;
    }

    /** @return array<int, array<string, array{ca: float, tickets: int}>>|null */
    private function parseMonthlySales(array $d): ?array
    {
        $out = [];
        foreach (($d['shops'] ?? $d) as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            foreach (($shop['months'] ?? []) as $m) {
                if (!is_array($m) || empty($m['month'])) {
                    continue;
                }
                $out[$sid][substr((string)$m['month'], 0, 7)] = [
                    'ca'      => isset($m['ca']) && is_numeric($m['ca']) ? (float)$m['ca'] : 0.0,
                    'tickets' => (int)($m['tickets'] ?? 0),
                ];
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P3 — KPI de ventes de TOUTES les boutiques sur une fenêtre.
     *
     * @return array<int, array>|null map shopId => KPIs (schéma getSalesKpis)
     */
    public function getSalesKpisAllShops(string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'sales_kpis_all',
            '/consultant/shops/sales-kpis?date_from=' . urlencode($from) . '&date_to=' . urlencode($to)
        );
        if ($d === null) {
            return null;
        }
        $out = [];
        foreach (($d['shops'] ?? $d) as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
            if ($sid > 0) {
                $k = $this->parseSalesKpisPayload($shop);
                if ($k !== null) {
                    $out[$sid] = $k;
                }
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P6a — Targets de TOUTES les boutiques pour un mois.
     *
     * @return array<int, array>|null map shopId => targets
     */
    public function getTargetsAllShops(int $year, int $month): ?array
    {
        $d = $this->batchGet('targets_all', "/consultant/targets?year={$year}&month={$month}");
        if ($d === null) {
            return null;
        }
        $out = [];
        foreach (($d['shops'] ?? $d) as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
            if ($sid > 0 && is_array($shop['targets'] ?? null)) {
                $out[$sid] = $shop['targets'];
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P6a en LOT — targets de toutes les boutiques pour PLUSIEURS mois, en
     * parallèle (curl_multi).
     *
     * Les tendances demandent 12 mois : en séquence, c'est 12 fois la latence
     * de l'API (jusqu'à 12 × 10 s si elle traîne), assez pour dépasser le délai
     * de la passerelle et rendre l'écran vide. En parallèle, c'est un aller-retour.
     *
     * @param string[] $yms mois 'YYYY-MM'
     * @return array<string, array<int, array>> map 'YYYY-MM' => (shopId => targets) ;
     *         les mois non servis sont absents.
     */
    public function getTargetsAllShopsMany(array $yms): array
    {
        $yms = array_values(array_unique($yms));
        if ($yms === [] || !empty(self::$batchMissing['targets_all'])
            || isset(self::breakerCurrent()['targets_all'])) {
            self::$batchMissing['targets_all'] = true;
            return [];
        }

        $byYm = [];
        foreach ($yms as $ym) {
            [$y, $m] = array_map('intval', explode('-', (string)$ym));
            if ($y > 0 && $m > 0) {
                $byYm[$ym] = "/consultant/targets?year={$y}&month={$m}";
            }
        }
        $t0        = microtime(true);
        $responses = [];
        foreach (array_chunk(array_values($byYm), 16) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $out   = [];
        $codes = [];
        foreach ($byYm as $ym => $ep) {
            $r = $responses[$ep] ?? null;
            if (!is_array($r) || empty($r['success']) || !is_array($r['data'] ?? null)) {
                $code    = is_int($r['error'] ?? null) ? (int)$r['error'] : 0;
                $codes[] = $code === 404 ? 'http_404' : ($code > 0 ? ('http_' . $code) : 'timeout_or_unreachable');
                continue;
            }
            $shops = [];
            foreach (($r['data']['shops'] ?? $r['data']) as $shop) {
                if (!is_array($shop)) {
                    continue;
                }
                $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
                if ($sid > 0 && is_array($shop['targets'] ?? null)) {
                    $shops[$sid] = $shop['targets'];
                }
            }
            if ($shops !== []) {
                $out[$ym] = $shops;
            } else {
                $codes[] = 'bad_payload';
            }
        }

        if ($out === [] && $codes !== []) {
            $why = (string)array_key_first(array_count_values($codes));
            self::$batchMissing['targets_all'] = true;
            if (!in_array($why, ['http_401', 'http_403'], true)) {
                self::breakerTrip(
                    'targets_all',
                    $why === 'http_404' ? self::BREAKER_TTL_ABSENT : self::BREAKER_TTL_FAIL,
                    $why
                );
            }
        }
        self::$batchLog[] = [
            'key'      => 'targets_all',
            'endpoint' => '/consultant/targets × ' . count($byYm) . ' mois (parallèle)',
            'status'   => $out !== [] ? 'ok (' . count($out) . '/' . count($byYm) . ')' : ($codes[0] ?? 'empty'),
            'ms'       => $ms,
        ];
        return $out;
    }

    /**
     * P6b — Targets d'une boutique sur une PLAGE de mois ('YYYY-MM').
     *
     * @return array<string, array>|null map 'YYYY-MM' => targets
     */
    public function getTargetsRange(int $shopId, string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'targets_range',
            '/consultant/shops/' . $shopId . '/targets/range?from=' . urlencode($from) . '&to=' . urlencode($to)
        );
        if ($d === null) {
            return null;
        }
        $out = [];
        foreach (($d['months'] ?? $d) as $row) {
            if (!is_array($row) || empty($row['month'])) {
                continue;
            }
            if (is_array($row['targets'] ?? null)) {
                $out[substr((string)$row['month'], 0, 7)] = $row['targets'];
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P7 — Ventes par catégorie de TOUTES les boutiques sur une fenêtre.
     *
     * @return array<int, array<string, float>>|null map shopId => (nom => CA)
     */
    public function getCategorySalesAllShops(string $from, string $to): ?array
    {
        $d = $this->batchGet(
            'category_sales_all',
            '/consultant/shops/category-sales?date_from=' . urlencode($from) . '&date_to=' . urlencode($to)
        );
        if ($d === null) {
            return null;
        }
        $out = [];
        foreach (($d['shops'] ?? $d) as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $mix = [];
            foreach (($shop['categories'] ?? []) as $c) {
                $name = trim((string)($c['name'] ?? ''));
                if ($name !== '' && isset($c['ca']) && is_numeric($c['ca'])) {
                    $mix[$name] = ($mix[$name] ?? 0.0) + (float)$c['ca'];
                }
            }
            if ($mix !== []) {
                $out[$sid] = $mix;
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * P8 — P&L (période courante) de TOUTES les boutiques.
     *
     * @return array<int, array>|null map shopId => P&L (schéma getPnl)
     */
    public function getPnlSummaryAllShops(string $period = 'month'): ?array
    {
        $d = $this->batchGet('pnl_summary', '/consultant/shops/pnl-summary?period=' . urlencode($period));
        if ($d === null) {
            return null;
        }
        $out = [];
        foreach (($d['shops'] ?? $d) as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $sid = (int)($shop['shop_id'] ?? $shop['id'] ?? 0);
            if ($sid > 0) {
                // Le P&L peut être imbriqué (`pnl`) ou à plat sur la boutique.
                $out[$sid] = is_array($shop['pnl'] ?? null) ? $shop['pnl'] : $shop;
            }
        }
        return $out !== [] ? $out : null;
    }
}
