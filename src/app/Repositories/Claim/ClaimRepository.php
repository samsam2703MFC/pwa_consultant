<?php
namespace App\Consultant\app\Repositories\Claim;

use App\Consultant\core\Http\ApiClient;

class ClaimRepository
{
    public function __construct(private ApiClient $apiClient) {}

    /**
     * Pobiera reklamacje sklepu ze wszystkich dostawcow.
     * Endpoint API nie filtruje po dostawcy, tylko po id_shop.
     */
    public function getClaimsForShop(int $shopId): array
    {
        $res = $this->apiClient->get("/shops/{$shopId}/material-complaints");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    /**
     * Réclamations de PLUSIEURS boutiques en parallèle (curl_multi).
     *
     * La vue réseau les demandait boutique par boutique : autant d'attentes
     * réseau en série qu'il y a de boutiques.
     *
     * @param int[] $shopIds
     * @return array<int, array> map shopId => réclamations
     */
    public function getClaimsForShops(array $shopIds): array
    {
        $byId = [];
        foreach (array_unique(array_map('intval', $shopIds)) as $id) {
            if ($id > 0) {
                $byId[$id] = "/shops/{$id}/material-complaints";
            }
        }
        if ($byId === []) {
            return [];
        }
        $responses = [];
        foreach (array_chunk(array_values($byId), 32) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        $out = [];
        foreach ($byId as $id => $ep) {
            $r = $responses[$ep] ?? null;
            $out[$id] = (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null))
                ? $r['data'] : [];
        }
        return $out;
    }

    public function getAttachmentPreviewUrl(int $attachmentId): ?string
    {
        $res = $this->apiClient->get("/attachments/{$attachmentId}/presigned-url");
        if (!$res['success'] || empty($res['data']['url'])) {
            return null;
        }

        return $res['data']['url'];
    }
}
