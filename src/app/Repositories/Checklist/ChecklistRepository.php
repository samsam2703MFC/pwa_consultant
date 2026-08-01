<?php
namespace App\Consultant\app\Repositories\Checklist;

use App\Consultant\core\Http\ApiClient;

class ChecklistRepository
{
    /**
     * Échantillonnage des réponses brutes, à la demande.
     *
     * Trois tours à supposer laquelle des sources se tait, sans jamais voir ce
     * que l'API renvoie : il faut pouvoir le lire. Désactivé par défaut, armé
     * par le rapport quand on le lui demande.
     */
    private static bool $sampling = false;
    private static array $samples = [];

    public static function sampleOn(): void
    {
        self::$sampling = true;
        self::$samples  = [];
    }

    /** @return array<string, array> map clé => ['endpoint'=>…, 'response'=>…] */
    public static function samples(): array
    {
        return self::$samples;
    }

    /** Un seul échantillon par clé : le premier suffit à lire le format. */
    private static function sample(string $key, string $endpoint, $response): void
    {
        if (!self::$sampling || isset(self::$samples[$key])) {
            return;
        }
        // Tronqué : une journée de checklists complète rendrait la page de
        // diagnostic illisible.
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && strlen($json) > 12000) {
            $response = ['_tronque' => true, '_taille_octets' => strlen($json),
                         '_extrait' => substr($json, 0, 12000)];
        }
        self::$samples[$key] = ['endpoint' => $endpoint, 'response' => $response];
    }

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
     * Les TÂCHES d'une boutique sur plusieurs dates, en un aller-retour.
     *
     * C'est la source de vérité de l'écran du jour : lui appelle `/tasks`
     * d'abord, et n'enrichit avec les checklists que dans un try/catch. Un
     * rapport bâti uniquement sur `/checklists` héritait donc d'un endpoint
     * dont la défaillance est invisible ailleurs — et rendait « 0 tâche
     * planifiée » alors que l'écran du jour en affichait trente-quatre.
     *
     * @param string[] $dates 'Y-m-d'
     * @return array<string, array> map date => liste de tâches
     */
    public function getShopTasksForDates(int $shopId, array $dates): array
    {
        $byDate = [];
        foreach (array_unique($dates) as $d) {
            $byDate[$d] = '/consultant/shops/' . $shopId . '/tasks?' . http_build_query(['date' => $d]);
        }
        if ($byDate === []) {
            return [];
        }
        $responses = $this->apiClient->getMany(array_values($byDate));
        $out = [];
        foreach ($byDate as $d => $ep) {
            $r = $responses[$ep] ?? null;
            self::sample('tasks', $ep, $r);
            $data = (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) ? $r['data'] : [];
            $out[$d] = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
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
            self::sample('checklists', $ep, $r);
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
            self::sample('progress', $ep, $r);
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
