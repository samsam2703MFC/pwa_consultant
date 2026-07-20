<?php
namespace App\Consultant\app\Services\Task;

use App\Consultant\app\Repositories\Task\TaskRepository;

class TaskService
{
    public function __construct(private TaskRepository $taskRepository) {}

    public function getConsultantTasks(): array
    {
        return $this->taskRepository->getConsultantTasks();
    }

    public function filterById(int $id, array $tasks): ?array
    {
        foreach ($tasks as $task) {
            if ((int)($task['id'] ?? 0) === $id) {
                return $task;
            }
        }

        return null;
    }

    public function markAsDone(int $taskId, array $data, array $files = []): array
    {
        $data['task_id'] = $data['task_id'] ?? $taskId;
        return $this->taskRepository->markAsDone($taskId, $data, $files);
    }

    public function getCompletion(int $id): array
    {
        return $this->taskRepository->getCompletion($id);
    }

    public function getHelpdeskTasks(array $filters = []): array
    {
        return $this->taskRepository->getHelpdeskTasks($filters);
    }

    public function getCaseDetails(int $id): array
    {
        return $this->taskRepository->getCaseDetails($id);
    }

    public function getEligibleConsultants(int $id): array
    {
        return $this->taskRepository->getEligibleConsultants($id);
    }

    public function getMeetings(int $id): array
    {
        return $this->taskRepository->getMeetings($id);
    }

    public function getAttachmentUrl(int $attachmentId): array
    {
        return $this->taskRepository->getAttachmentUrl($attachmentId);
    }

    public function updateCaseStatus(int $id, string $status): array
    {
        return $this->taskRepository->updateCaseStatus($id, $status);
    }

    public function assignConsultant(int $id, ?int $consultantId): array
    {
        return $this->taskRepository->assignConsultant($id, $consultantId);
    }

    public function assignMeeting(int $id, ?int $meetingId): array
    {
        return $this->taskRepository->assignMeeting($id, $meetingId);
    }

    public function saveReply(int $id, ?string $reply): array
    {
        return $this->taskRepository->saveReply($id, $reply);
    }
}
