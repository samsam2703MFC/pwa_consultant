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
}

