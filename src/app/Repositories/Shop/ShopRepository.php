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
}

