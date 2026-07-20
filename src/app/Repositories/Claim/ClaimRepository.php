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

    public function getAttachmentPreviewUrl(int $attachmentId): ?string
    {
        $res = $this->apiClient->get("/attachments/{$attachmentId}/presigned-url");
        if (!$res['success'] || empty($res['data']['url'])) {
            return null;
        }

        return $res['data']['url'];
    }
}
