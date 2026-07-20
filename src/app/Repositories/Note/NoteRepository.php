<?php
namespace App\Consultant\app\Repositories\Note;

use App\Consultant\core\Http\ApiClient;

class NoteRepository
{
    public function __construct(private ApiClient $apiClient) {}

    public function getNoteTypes(): array
    {
        $res = $this->apiClient->get('/consultant/note-types');
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function getNotesForShop(int $shopId): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/notes");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function createNote(int $shopId, array $data): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/notes", $data);
    }

    public function getEmployeesForShop(int $shopId): array
    {
        $res = $this->apiClient->get("/shops/{$shopId}/employees");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function getNotesForEmployee(int $shopId, int $employeeId): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/employees/{$employeeId}/notes");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function createEmployeeNote(int $shopId, int $employeeId, array $data): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/employees/{$employeeId}/notes", $data);
    }

    public function getNote(int $id): array
    {
        $res = $this->apiClient->get("/consultant/notes/{$id}");
        return ($res['success'] && isset($res['data'])) ? $res['data'] : [];
    }

    public function deleteNote(int $id): array
    {
        return $this->apiClient->delete("/consultant/notes/{$id}");
    }

    /**
     * Dodaje komentarz z opcjonalnymi zdjęciami (multipart).
     * $files = ['photos' => $_FILES['photos']]  (multi-file structure)
     */
    public function addComment(int $noteId, array $data, array $files = []): array
    {
        // Normalizuj $_FILES['photos'] (multi-file) na indexed CURLFile-friendly format
        // postMultipart oczekuje ['photos[0]' => singleFile, 'photos[1]' => singleFile, ...]
        $normalizedFiles = [];
        if (!empty($files['photos']) && isset($files['photos']['name'])) {
            $rawPhotos = $files['photos'];
            $names     = (array)($rawPhotos['name'] ?? []);
            $limit     = min(count($names), 4);

            for ($i = 0; $i < $limit; $i++) {
                if (($rawPhotos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $normalizedFiles["photos[{$i}]"] = [
                        'name'     => $rawPhotos['name'][$i] ?? '',
                        'type'     => $rawPhotos['type'][$i] ?? 'application/octet-stream',
                        'tmp_name' => $rawPhotos['tmp_name'][$i] ?? '',
                        'error'    => UPLOAD_ERR_OK,
                        'size'     => $rawPhotos['size'][$i] ?? 0,
                    ];
                }
            }
        }

        return $this->apiClient->postMultipart(
            "/consultant/notes/{$noteId}/comments",
            $data,
            $normalizedFiles
        );
    }

    public function deleteComment(int $id): array
    {
        return $this->apiClient->delete("/consultant/comments/{$id}");
    }
}


