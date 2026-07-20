<?php
namespace App\Consultant\app\Services\Claim;

use App\Consultant\app\Repositories\Claim\ClaimRepository;

class ClaimService
{
    /** Statuts de réclamation gérés par le module (ordre d'affichage). */
    public const STATUSES = ['NEW', 'IN_REVIEW', 'ACCEPTED', 'REJECTED'];

    public function __construct(private ClaimRepository $claimRepository) {}

    /**
     * Nombre de réclamations par statut, calculé sur les données réelles.
     * Les statuts connus apparaissent toujours (0 si absents) ; tout statut
     * présent dans les données mais non listé est ajouté à la fin.
     *
     * @return array<string,int>  statut => nombre
     */
    public function countByStatus(array $claims): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($claims as $claim) {
            $status = strtoupper((string)($claim['status'] ?? 'NEW'));
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

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
