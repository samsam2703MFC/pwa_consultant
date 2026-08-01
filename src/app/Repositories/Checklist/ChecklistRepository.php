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

    /**
     * Les checklists d'une boutique sur PLUSIEURS dates, en un aller-retour.
     *
     * Un rapport hebdomadaire couvre 6 jours, un mensuel une vingtaine. En
     * séquence, c'est autant d'attentes réseau — et à 10 s de délai par appel,
     * la passerelle coupe avant la fin. Ici, une seule attente quel que soit le
     * nombre de jours.
     *
     * @param string[] $dates 'Y-m-d'
     * @return array<string, array> map date => réponse ([] si indisponible)
     */
    public function getChecklistsForDates(int $shopId, array $dates): array
    {
        $byDate = [];
        foreach (array_unique($dates) as $d) {
            $byDate[$d] = "/consultant/shops/{$shopId}/checklists?date={$d}";
        }
        if ($byDate === []) {
            return [];
        }
        $responses = $this->apiClient->getMany(array_values($byDate));
        $out = [];
        foreach ($byDate as $d => $ep) {
            $r = $responses[$ep] ?? null;
            $out[$d] = (is_array($r) && !empty($r['success']) && isset($r['data'])) ? $r['data'] : [];
        }
        return $out;
    }

    /**
     * L'avancement de N couples (date, checklist) en un aller-retour.
     *
     * Même raison que ci-dessus, à l'échelle supérieure : un mois × trois
     * checklists, c'est ~78 appels. Groupés, c'est une attente.
     *
     * @param array<int, array{date: string, checklist_id: int}> $pairs
     * @return array<string, array> map "date|checklistId" => avancement
     */
    public function getProgressForPairs(int $shopId, array $pairs): array
    {
        $byKey = [];
        foreach ($pairs as $p) {
            $d   = (string)($p['date'] ?? '');
            $cid = (int)($p['checklist_id'] ?? 0);
            if ($d !== '' && $cid > 0) {
                $byKey["{$d}|{$cid}"] = "/consultant/shops/{$shopId}/checklists/{$cid}/progress?date={$d}";
            }
        }
        if ($byKey === []) {
            return [];
        }
        $responses = $this->apiClient->getMany(array_values($byKey));
        $out = [];
        foreach ($byKey as $key => $ep) {
            $r = $responses[$ep] ?? null;
            $out[$key] = (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null))
                ? $r['data'] : [];
        }
        return $out;
    }

    public function submitTaskReview(int $shopId, array $data): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/task-reviews", $data);
    }
}
