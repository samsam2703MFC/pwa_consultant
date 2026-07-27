<?php
namespace App\Consultant\app\Repositories\Campaign;

use App\Consultant\core\Http\ApiClient;

/**
 * Campagnes commerciales d'un magasin (source : API franchiseur, même endpoint
 * que l'écran Targets — GET /shops/{id}/campaigns?date_from&date_to). La forme
 * exacte varie ; le déballage est tolérant (data | campaigns | items | list).
 */
class CampaignRepository
{
    public function __construct(private ApiClient $apiClient) {}

    /** Campagnes actives sur la fenêtre [from, to] (dates YYYY-MM-DD). */
    public function getCampaigns(int $shopId, string $from, string $to): array
    {
        $res = $this->apiClient->where(
            "/shops/{$shopId}/campaigns",
            ['date_from' => $from, 'date_to' => $to]
        );
        if (empty($res['success'])) {
            return [];
        }
        $data = $res['data'] ?? [];
        // Déballage tolérant : liste directe, ou enveloppée.
        foreach (['campaigns', 'items', 'list', 'data'] as $k) {
            if (is_array($data) && !array_is_list($data) && isset($data[$k]) && is_array($data[$k])) {
                $data = $data[$k];
                break;
            }
        }
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
