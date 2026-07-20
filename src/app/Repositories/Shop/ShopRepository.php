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
}

