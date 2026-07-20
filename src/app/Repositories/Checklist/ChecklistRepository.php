<?php
namespace App\Consultant\app\Repositories\Checklist;

use App\Consultant\core\Http\ApiClient;

class ChecklistRepository
{
    public function __construct(private ApiClient $apiClient) {}

    public function getNetworkTasksRanking(string $date): array
    {
        $res = $this->apiClient->get('/consultant/network/tasks/ranking?' . http_build_query([
            'date' => $date,
        ]));

        return ($res['success'] && isset($res['data']))
            ? $res['data']
            : ['network' => [], 'shops' => []];
    }

    public function getShopTaskDetails(int $shopId, string $date): array
    {
        $res = $this->apiClient->get('/consultant/shops/' . $shopId . '/tasks?' . http_build_query([
            'date' => $date,
        ]));

        return ($res['success'] && isset($res['data']))
            ? $res['data']
            : ['summary' => [], 'tasks' => [], 'trend' => []];
    }

    public function getChecklistsForShop(int $shopId, string $date): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/checklists?date={$date}");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function getChecklistProgress(int $shopId, int $checklistId, string $date): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/checklists/{$checklistId}/progress?date={$date}");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function submitTaskReview(int $shopId, array $data): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/task-reviews", $data);
    }
}
