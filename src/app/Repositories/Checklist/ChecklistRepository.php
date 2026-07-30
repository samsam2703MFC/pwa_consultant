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

    /**
     * Avancement de PLUSIEURS checklists en parallèle (curl_multi).
     *
     * L'écran des tâches de la boutique en a besoin pour toutes les checklists
     * du jour : en séquence, ce serait autant d'attentes réseau ajoutées au
     * chargement de la page.
     *
     * @param int[] $checklistIds
     * @return array<int, array> map id de checklist => avancement ([] si indisponible)
     */
    public function getChecklistProgressMany(int $shopId, array $checklistIds, string $date): array
    {
        $out = [];
        $byId = [];
        foreach (array_unique(array_map('intval', $checklistIds)) as $cid) {
            if ($cid > 0) {
                $byId[$cid] = "/consultant/shops/{$shopId}/checklists/{$cid}/progress?date={$date}";
            }
        }
        if ($byId === []) {
            return $out;
        }
        $responses = $this->apiClient->getMany(array_values($byId));
        foreach ($byId as $cid => $ep) {
            $r = $responses[$ep] ?? null;
            $out[$cid] = (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null))
                ? $r['data'] : [];
        }
        return $out;
    }

    public function submitTaskReview(int $shopId, array $data): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/task-reviews", $data);
    }
}
