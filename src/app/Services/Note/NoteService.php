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
    public function getNotesOverview(array $shops, int $recentLimit = 5): array
    {
        // Ids + métadonnées des boutiques.
        $shopIds  = [];
        $shopMeta = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId === 0) {
                continue;
            }
            $shopIds[] = $shopId;
            $shopMeta[$shopId] = [
                'name'    => $shop['representative_name'] ?? $shop['name'] ?? '',
                'address' => $shop['address'] ?? $shop['city'] ?? '',
            ];
        }

        if ($shopIds === []) {
            return ['recent' => [], 'by_shop' => []];
        }

        // Récupérations PARALLÈLES : notes niveau boutique + employés de chaque
        // boutique, puis notes de chaque employé. Les notes « employé » vivent
        // sur un endpoint séparé (/employees/{id}/notes) : sans les agréger ici,
        // elles n'apparaissaient ni dans « Récentes » ni dans les compteurs.
        $shopNotesByShop = $this->noteRepository->getNotesForShopsBulk($shopIds);
        $empsByShop      = $this->noteRepository->getEmployeesForShopsBulk($shopIds);

        $pairs    = [];
        $empLabel = [];
        foreach ($empsByShop as $sid => $emps) {
            foreach ($emps as $emp) {
                $eid = (int)($emp['id'] ?? 0);
                if ($eid <= 0) {
                    continue;
                }
                $pairs[] = [$sid, $eid];
                $empLabel[$sid . ':' . $eid] = $emp['display_name'] ?? $emp['employee_name']
                    ?? trim(((string)($emp['name'] ?? '')) . ' ' . ((string)($emp['surname'] ?? '')));
            }
        }
        $empNotes = $pairs !== [] ? $this->noteRepository->getNotesForEmployeesBulk($pairs) : [];

        $recent = [];
        $byShop = [];

        $push = function (array $n, int $shopId, string $shopName, string $author) use (&$recent) {
            $recent[] = [
                'id'         => $n['id'] ?? null,
                'content'    => $n['content'] ?? '',
                'created_at' => $n['created_at'] ?? null,
                'type_name'  => $n['type_name'] ?? null,
                'author'     => $author,
                'shop_id'    => $shopId,
                'shop_name'  => $shopName,
            ];
        };

        // Seuil « activité récente » : note créée dans les dernières 48 h.
        $threshold48h = time() - 48 * 3600;
        $isRecent = fn($n) => !empty($n['created_at']) && strtotime((string)$n['created_at']) >= $threshold48h;

        foreach ($shopIds as $sid) {
            $name      = $shopMeta[$sid]['name'];
            $count     = 0;
            $hasRecent = false;

            // Notes niveau boutique.
            foreach (($shopNotesByShop[$sid] ?? []) as $n) {
                if (!empty($n['deleted_at'])) {
                    continue;
                }
                $count++;
                if ($isRecent($n)) {
                    $hasRecent = true;
                }
                $author = $n['employee_name'] ?? trim(((string)($n['employee_first_name'] ?? '')) . ' ' . ((string)($n['employee_last_name'] ?? '')));
                $push($n, $sid, $name, $author);
            }

            // Notes des employés de la boutique.
            foreach (($empsByShop[$sid] ?? []) as $emp) {
                $eid = (int)($emp['id'] ?? 0);
                if ($eid <= 0) {
                    continue;
                }
                $key = $sid . ':' . $eid;
                foreach (($empNotes[$key] ?? []) as $n) {
                    if (!empty($n['deleted_at'])) {
                        continue;
                    }
                    $count++;
                    if ($isRecent($n)) {
                        $hasRecent = true;
                    }
                    $push($n, $sid, $name, (string)($empLabel[$key] ?? ''));
                }
            }

            $byShop[] = [
                'id'         => $sid,
                'name'       => $name,
                'address'    => $shopMeta[$sid]['address'],
                'count'      => $count,
                'recent_48h' => $hasRecent,
            ];
        }

        usort($recent, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $recent = array_slice($recent, 0, $recentLimit);

        // Miniature = 1re photo de la note. Les photos vivent sur les commentaires,
        // absents de la liste : on récupère le détail des notes affichées EN
        // PARALLÈLE et on en extrait la première image (le cas échéant).
        $ids = [];
        foreach ($recent as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            $details = $this->noteRepository->getNotesByIdsBulk($ids);
            foreach ($recent as &$r) {
                $r['thumb'] = $this->firstPhotoAttachmentId($details[(int)($r['id'] ?? 0)] ?? []);
            }
            unset($r);
        }

        return [
            'recent'  => $recent,
            'by_shop' => $byShop,
        ];
    }

    /**
     * Id de la 1re pièce jointe IMAGE d'une note (attachments de la note, sinon
     * des commentaires). Renvoie l'id — la vue construit l'URL via l'endpoint
     * /notes/attachments/{id}/preview. Champs tolérants (attachments|photos).
     */
    private function firstPhotoAttachmentId(array $note): ?int
    {
        $isImage = function (array $a): bool {
            $mime = strtolower((string)($a['mime_type'] ?? $a['content_type'] ?? $a['mime'] ?? ''));
            $name = strtolower((string)($a['original_name'] ?? $a['name'] ?? ''));
            return str_starts_with($mime, 'image/')
                || (bool)preg_match('/\.(jpe?g|png|gif|webp|heic|heif|bmp|avif)$/', $name)
                || ($mime === '' && $name === '');  // pas de méta → on suppose une image (les pièces de note sont des photos)
        };
        $scan = function ($list) use ($isImage): ?int {
            foreach ((array)$list as $a) {
                if (is_array($a) && !empty($a['id']) && $isImage($a)) {
                    return (int)$a['id'];
                }
            }
            return null;
        };

        foreach (['attachments', 'photos'] as $f) {
            if ($id = $scan($note[$f] ?? [])) {
                return $id;
            }
        }
        foreach (($note['comments'] ?? []) as $c) {
            foreach (['attachments', 'photos'] as $f) {
                if ($id = $scan($c[$f] ?? [])) {
                    return $id;
                }
            }
        }
        return null;
    }

    public function createNote(int $shopId, array $postData, array $files = []): array
    {
        $data = $this->buildNotePayload($postData);
        return !empty($files['photos'])
            ? $this->noteRepository->createNoteWithPhotos($shopId, $data, $files)
            : $this->noteRepository->createNote($shopId, $data);
    }

    public function getEmployeesForShop(int $shopId): array
    {
        return $this->noteRepository->getEmployeesForShop($shopId);
    }

    public function getNotesForEmployee(int $shopId, int $employeeId): array
    {
        return $this->noteRepository->getNotesForEmployee($shopId, $employeeId);
    }

    public function createEmployeeNote(int $shopId, int $employeeId, array $postData, array $files = []): array
    {
        $data = $this->buildNotePayload($postData);
        return !empty($files['photos'])
            ? $this->noteRepository->createEmployeeNoteWithPhotos($shopId, $employeeId, $data, $files)
            : $this->noteRepository->createEmployeeNote($shopId, $employeeId, $data);
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

    public function getAttachmentPreviewUrl(int $attachmentId): ?string
    {
        return $this->noteRepository->getAttachmentPreviewUrl($attachmentId);
    }
}

