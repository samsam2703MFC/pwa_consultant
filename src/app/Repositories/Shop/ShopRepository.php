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
        $response = $this->apiClient->get(
            '/shops/' . $shopId . '/statistics/sales/kpis'
            . '?date_from=' . urlencode($fromDate) . '&date_to=' . urlencode($toDate)
        );
        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            if (($response['error'] ?? null) === 404) {
                self::$kpiApiMissing = true;
            }
            return null;
        }
        $d = $response['data'];
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
}

