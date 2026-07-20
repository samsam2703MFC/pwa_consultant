<?php
namespace App\Consultant\app\Services\Claim;

use App\Consultant\app\Repositories\Claim\ClaimRepository;

class ClaimService
{
    public function __construct(private ClaimRepository $claimRepository) {}

    public function getClaimsForShop(?int $shopId): array
    {
        if (!$shopId) {
            return [];
        }

        return $this->claimRepository->getClaimsForShop($shopId);
    }

    public function getClaimsForAllShops(array $shops): array
    {
        $claims = [];

        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }

            $shopName = $shop['representative_name'] ?? $shop['name'] ?? 'Sklep';
            foreach ($this->claimRepository->getClaimsForShop($shopId) as $claim) {
                $claim['shop_id'] = $claim['id_shop'] ?? $shopId;
                $claim['shop_name'] = $shopName;
                $claims[] = $claim;
            }
        }

        usort($claims, function (array $a, array $b): int {
            return strcmp((string)($b['reported_at'] ?? ''), (string)($a['reported_at'] ?? ''));
        });

        return $claims;
    }

    public function filterByStatus(array $claims, string $status): array
    {
        if ($status === 'ALL') {
            return $claims;
        }

        return array_values(array_filter($claims, function (array $claim) use ($status): bool {
            return ($claim['status'] ?? '') === $status;
        }));
    }

    public function getAttachmentPreviewUrl(int $attachmentId): ?string
    {
        return $this->claimRepository->getAttachmentPreviewUrl($attachmentId);
    }
}
