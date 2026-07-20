<?php
namespace App\Consultant\app\Services\Target;

use App\Consultant\app\Repositories\Target\ShopMetricTargetRepository;

class ShopMetricTargetService
{
    public function __construct(
        private ShopMetricTargetRepository $repo
    ) {}

    public function getTargets(int $shopId, int $year, int $month): array
    {
        return $this->repo->getTargets($shopId, $year, $month);
    }

    public function saveTargets(int $shopId, int $year, int $month, int $authorId, array $targets): array
    {
        return $this->repo->saveTargets($shopId, [
            'year'      => $year,
            'month'     => $month,
            'author_id' => $authorId,
            'targets'   => $targets,
        ]);
    }

    public function copyFromPreviousMonth(int $shopId, int $year, int $month, int $authorId): array
    {
        return $this->repo->copyFromPreviousMonth($shopId, [
            'year'      => $year,
            'month'     => $month,
            'author_id' => $authorId,
        ]);
    }

    public function getMetricDefinitions(): array
    {
        return $this->repo->getMetricDefinitions();
    }
}

