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
        foreach (array_chunk(array_values(array_unique($byKey)), 24) as $chunk) {
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
    public function getMarginHeatmap(int $shopId, string $from, string $to): ?array
    {
        $response = $this->apiClient->get($this->marginHeatmapEndpoint($shopId, $from, $to));
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
}

