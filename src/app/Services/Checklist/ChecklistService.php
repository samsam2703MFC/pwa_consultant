<?php
namespace App\Consultant\app\Services\Checklist;

use App\Consultant\app\Repositories\Checklist\ChecklistRepository;

class ChecklistService
{
    public function __construct(private ChecklistRepository $checklistRepository) {}

    public function getNetworkTasksRanking(string $date): array
    {
        return $this->checklistRepository->getNetworkTasksRanking($date);
    }

    public function getShopTaskDetails(int $shopId, string $date): array
    {
        return $this->checklistRepository->getShopTaskDetails($shopId, $date);
    }

    public function getChecklistsForShop(int $shopId, string $date): array
    {
        return $this->checklistRepository->getChecklistsForShop($shopId, $date);
    }

    public function getChecklistProgress(int $shopId, int $checklistId, string $date): array
    {
        return $this->checklistRepository->getChecklistProgress($shopId, $checklistId, $date);
    }

    public function isDateValid(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        return $date <= date('Y-m-d');
    }

    public function submitTaskReview(int $shopId, array $data): array
    {
        if (empty($data['checklist_id']) || empty($data['task_id']) || empty($data['review_date'])) {
            return ['success' => false, 'error' => 'Missing required fields'];
        }
        return $this->checklistRepository->submitTaskReview($shopId, $data);
    }
}
