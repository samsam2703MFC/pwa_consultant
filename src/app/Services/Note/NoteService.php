<?php
namespace App\Consultant\app\Services\Note;

use App\Consultant\app\Repositories\Note\NoteRepository;
use App\Consultant\core\Support\GlobalRegistry;

class NoteService
{
    public function __construct(private NoteRepository $noteRepository) {}

    private function getCurrentUser(): array
    {
        return GlobalRegistry::get('user') ?? [];
    }

    public function getNoteTypes(): array
    {
        return $this->noteRepository->getNoteTypes();
    }

    public function getNotesForShop(int $shopId): array
    {
        return $this->noteRepository->getNotesForShop($shopId);
    }

    public function createNote(int $shopId, array $postData): array
    {
        $data = $this->buildNotePayload($postData);
        return $this->noteRepository->createNote($shopId, $data);
    }

    public function getEmployeesForShop(int $shopId): array
    {
        return $this->noteRepository->getEmployeesForShop($shopId);
    }

    public function getNotesForEmployee(int $shopId, int $employeeId): array
    {
        return $this->noteRepository->getNotesForEmployee($shopId, $employeeId);
    }

    public function createEmployeeNote(int $shopId, int $employeeId, array $postData): array
    {
        $data = $this->buildNotePayload($postData);
        return $this->noteRepository->createEmployeeNote($shopId, $employeeId, $data);
    }

    private function buildNotePayload(array $postData): array
    {
        $user = $this->getCurrentUser();

        return [
            'consultant_id' => $user['id'] ?? 0,
            'membership_id' => $user['membership_id'] ?? null,
            'note_type_id'  => !empty($postData['note_type_id']) ? (int)$postData['note_type_id'] : null,
            'content'       => trim($postData['content'] ?? ''),
        ];
    }

    public function getNote(int $id): array
    {
        return $this->noteRepository->getNote($id);
    }

    public function deleteNote(int $id): array
    {
        return $this->noteRepository->deleteNote($id);
    }

    public function addComment(int $noteId, array $postData, array $files = []): array
    {
        $user = $this->getCurrentUser();

        $data = [
            'consultant_id' => $user['id'] ?? 0,
            'content'       => trim($postData['content'] ?? ''),
        ];

        $photosFiles = [];
        if (!empty($files['photos'])) {
            $photosFiles = ['photos' => $files['photos']];
        }

        return $this->noteRepository->addComment($noteId, $data, $photosFiles);
    }

    public function deleteComment(int $id): array
    {
        return $this->noteRepository->deleteComment($id);
    }
}

