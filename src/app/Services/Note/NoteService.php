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

    /**
     * Agreguje notatki wszystkich sklepów konsultanta:
     *   - 'recent'  : najnowsze notatki (z nazwą sklepu), posortowane malejąco
     *   - 'by_shop' : sklepy z liczbą notatek
     * Wykorzystuje istniejący endpoint /consultant/shops/{id}/notes (po jednym
     * zapytaniu na sklep).
     */
    public function getNotesOverview(array $shops, int $recentLimit = 6): array
    {
        $recent = [];
        $byShop = [];

        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId === 0) {
                continue;
            }

            $shopName = $shop['representative_name'] ?? $shop['name'] ?? '';
            $notes    = $this->noteRepository->getNotesForShop($shopId);
            $count    = 0;

            foreach ($notes as $n) {
                if (!empty($n['deleted_at'])) {
                    continue;
                }
                $count++;
                $recent[] = [
                    'id'         => $n['id'] ?? null,
                    'content'    => $n['content'] ?? '',
                    'created_at' => $n['created_at'] ?? null,
                    'type_name'  => $n['type_name'] ?? null,
                    'author'     => $n['employee_name'] ?? trim(($n['employee_first_name'] ?? '') . ' ' . ($n['employee_last_name'] ?? '')),
                    'shop_id'    => $shopId,
                    'shop_name'  => $shopName,
                ];
            }

            $byShop[] = [
                'id'      => $shopId,
                'name'    => $shopName,
                'address' => $shop['address'] ?? $shop['city'] ?? '',
                'count'   => $count,
            ];
        }

        usort($recent, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

        return [
            'recent'  => array_slice($recent, 0, $recentLimit),
            'by_shop' => $byShop,
        ];
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

