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
