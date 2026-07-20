<?php
namespace App\Consultant\app\Repositories\Target;

use App\Consultant\core\Http\ApiClient;

class ShopMetricTargetRepository
{
    public function __construct(private ApiClient $apiClient) {}

    public function getTargets(int $shopId, int $year, int $month): array
    {
        $response = $this->apiClient->get(
            "/consultant/shops/{$shopId}/targets?year={$year}&month={$month}"
        );
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function saveTargets(int $shopId, array $payload): array
    {
        return $this->apiClient->put("/consultant/shops/{$shopId}/targets", $payload);
    }

    public function copyFromPreviousMonth(int $shopId, array $payload): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/targets/copy", $payload);
    }

    public function getMetricDefinitions(): array
    {
        $response = $this->apiClient->get('/consultant/metric-definitions');
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }
}
