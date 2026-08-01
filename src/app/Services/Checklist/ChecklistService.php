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

    /**
     * Avancement de plusieurs checklists en parallèle.
     *
     * @param int[] $checklistIds
     * @return array<int, array> map id de checklist => avancement
     */
    public function getChecklistProgressMany(int $shopId, array $checklistIds, string $date): array
    {
        return $this->checklistRepository->getChecklistProgressMany($shopId, $checklistIds, $date);
    }

    public function getChecklistProgress(int $shopId, int $checklistId, string $date): array
    {
        return $this->checklistRepository->getChecklistProgress($shopId, $checklistId, $date);
    }

    /**
     * Checklists sur plusieurs dates, en une attente réseau (rapports).
     *
     * @param string[] $dates
     * @return array<string, array> map date => réponse
     */
    public function getChecklistsForDates(int $shopId, array $dates): array
    {
        return $this->checklistRepository->getChecklistsForDates($shopId, $dates);
    }

    /**
     * Avancement de N couples (date, checklist), en une attente réseau.
     *
     * @param array<int, array{date: string, checklist_id: int}> $pairs
     * @return array<string, array> map "date|checklistId" => avancement
     */
    public function getProgressForPairs(int $shopId, array $pairs): array
    {
        return $this->checklistRepository->getProgressForPairs($shopId, $pairs);
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
