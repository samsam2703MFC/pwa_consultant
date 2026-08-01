<?php
namespace App\Consultant\app\Repositories\Task;

use App\Consultant\core\Http\ApiClient;

class TaskRepository
{
    public function __construct(private ApiClient $apiClient) {}

    public function getConsultantTasks(): array
    {
        $response = $this->apiClient->get('/consultant/tasks');
        return ($response['success'] && isset($response['data']))
            ? $response['data']
            : ['position' => null, 'tasks' => []];
    }

    public function markAsDone(int $taskId, array $data, array $files = []): array
    {
        return $this->apiClient->postMultipart(
            "/consultant/tasks/{$taskId}/mark-as-done",
            $data,
            $files
        );
    }

    /**
     * Plusieurs réalisations en un aller-retour (curl_multi).
     *
     * Le rapport a besoin de la DATE de chaque réalisation, et cette date ne
     * vit que dans la réalisation — la tâche ne la porte pas. En séquence,
     * trente tâches faites coûtaient trente attentes, et à 10 s de délai par
     * appel la passerelle coupait avant la fin : la section « Tâches
     * réalisées » revenait vide sans dire pourquoi.
     *
     * @param int[] $ids
     * @return array<int, array> map id => réalisation ([] si indisponible)
     */
    public function getCompletionsMany(array $ids): array
    {
        $byId = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0) {
                $byId[$id] = "/consultant/tasks/completions/{$id}";
            }
        }
        if ($byId === []) {
            return [];
        }
        $responses = $this->apiClient->getMany(array_values($byId));
        $out = [];
        foreach ($byId as $id => $ep) {
            $r = $responses[$ep] ?? null;
            $out[$id] = (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null))
                ? $r['data'] : [];
        }
        return $out;
    }

    public function getCompletion(int $id): array
    {
        $response = $this->apiClient->get("/consultant/tasks/completions/{$id}");
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function getHelpdeskTasks(array $filters = []): array
    {
        $query = array_filter([
            'status'   => $filters['status'] ?? null,
            'area_key' => $filters['area_key'] ?? null,
            'q'        => $filters['q'] ?? null,
        ], fn($value) => $value !== null && $value !== '');

        $endpoint = '/consultant/tasks/helpdesk';
        if (!empty($query)) {
            $endpoint .= '?' . http_build_query($query);
        }

        $response = $this->apiClient->get($endpoint);
        return ($response['success'] && isset($response['data']))
            ? $response['data']
            : ['areas' => [], 'cases' => []];
    }

    public function getCaseDetails(int $id): array
    {
        $response = $this->apiClient->get("/cases/{$id}?include=attachments");
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function getEligibleConsultants(int $id): array
    {
        $response = $this->apiClient->get("/cases/{$id}/eligible-consultants");
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function getMeetings(int $id): array
    {
        $response = $this->apiClient->get("/cases/{$id}/meetings");
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function getAttachmentUrl(int $attachmentId): array
    {
        $response = $this->apiClient->get("/attachments/{$attachmentId}/presigned-url");
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    public function updateCaseStatus(int $id, string $status): array
    {
        return $this->apiClient->patch("/cases/{$id}/status", ['status' => $status]);
    }

    public function assignConsultant(int $id, ?int $consultantId): array
    {
        return $this->apiClient->patch("/cases/{$id}/consultant", ['consultant_id' => $consultantId]);
    }

    public function assignMeeting(int $id, ?int $meetingId): array
    {
        return $this->apiClient->patch("/cases/{$id}/meeting", ['id_meeting' => $meetingId]);
    }

    public function saveReply(int $id, ?string $reply): array
    {
        return $this->apiClient->patch("/cases/{$id}/reply", ['admin_reply' => $reply]);
    }
}
