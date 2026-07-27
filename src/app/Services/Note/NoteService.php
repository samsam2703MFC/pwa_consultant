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
                $detail = $details[(int)($r['id'] ?? 0)] ?? [];

                $first = $this->firstPhoto($detail);
                $r['thumb_url'] = $first['url'] ?? null;

                // Dernière réponse (commentaire) : la tuile la met en avant, avec
                // sa mini-photo → la photo a enfin son contexte. Le clic ouvre le
                // fil complet (page de la note).
                $comments = is_array($detail['comments'] ?? null) ? $detail['comments'] : [];
                $r['reply_count'] = 0;
                $r['last_reply']  = null;
                $last = $this->latestComment($comments);
                if ($last !== null) {
                    $photos = $this->imageAttachments($last);
                    $r['reply_count'] = count(array_filter($comments, fn($c) => is_array($c) && empty($c['deleted_at'])));
                    $r['last_reply']  = [
                        'author'     => $this->commentAuthor($last),
                        'content'    => (string)($last['content'] ?? ''),
                        'created_at' => $last['created_at'] ?? null,
                        'photo_url'  => $photos[0]['url'] ?? null,
                    ];
                }
            }
            unset($r);
        }

        return [
            'recent'  => $recent,
            'by_shop' => $byShop,
        ];
    }

    /**
     * Photos (images) d'un conteneur note OU commentaire, prêtes pour la vue :
     * [ ['id' => 389, 'url' => 'https://…'], … ].
     *
     * L'API renvoie chaque pièce jointe avec une `presigned_url` directement
     * utilisable (R2) → on la privilégie : aucun aller-retour serveur pour
     * afficher l'image. À défaut (id brut sans méta), on retombe sur l'endpoint
     * de redirection /notes/attachments/{id}/preview. On balaye plusieurs noms
     * de champ et on tolère aussi bien les objets que les id bruts ; sans
     * métadonnée on suppose une image (les pièces de note sont des photos).
     *
     * @return array<int, array{id:int, url:string}> photos dédoublonnées
     */
    private function imageAttachments(array $container): array
    {
        $isImage = static function ($a): bool {
            if (!is_array($a)) {
                return is_numeric($a);
            }
            $mime = strtolower((string)($a['mime_type'] ?? $a['content_type'] ?? $a['mime'] ?? ''));
            $name = strtolower((string)($a['original_name'] ?? $a['name'] ?? $a['filename'] ?? ''));
            return str_starts_with($mime, 'image/')
                || (bool)preg_match('/\.(jpe?g|png|gif|webp|heic|heif|bmp|avif)$/', $name)
                || ($mime === '' && $name === '');
        };
        $idOf = static function ($a): ?int {
            if (is_array($a)) {
                $id = $a['id'] ?? $a['attachment_id'] ?? $a['id_attachment'] ?? $a['file_id'] ?? null;
                return ($id !== null && (int)$id > 0) ? (int)$id : null;
            }
            return (is_numeric($a) && (int)$a > 0) ? (int)$a : null;
        };
        $urlOf = static function ($a, int $id): string {
            if (is_array($a)) {
                foreach (['presigned_url', 'url', 'signed_url', 'download_url'] as $k) {
                    if (!empty($a[$k]) && is_string($a[$k])) {
                        return $a[$k];
                    }
                }
            }
            $root = defined('ROOT') ? ROOT : '';
            return $root . '/notes/attachments/' . $id . '/preview';
        };

        $out  = [];
        $seen = [];
        foreach (['attachments', 'photos', 'files', 'images', 'media', 'documents'] as $field) {
            foreach ((array)($container[$field] ?? []) as $a) {
                if (!$isImage($a)) {
                    continue;
                }
                $id = $idOf($a);
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = ['id' => $id, 'url' => $urlOf($a, $id)];
            }
        }
        return $out;
    }

    /**
     * 1re photo d'une note (celles de la note, sinon de ses commentaires) —
     * sert de miniature aux tuiles « Récentes ». Renvoie ['id','url'] ou null.
     */
    private function firstPhoto(array $note): ?array
    {
        $p = $this->imageAttachments($note);
        if ($p !== []) {
            return $p[0];
        }
        foreach (($note['comments'] ?? []) as $c) {
            if (is_array($c)) {
                $cp = $this->imageAttachments($c);
                if ($cp !== []) {
                    return $cp[0];
                }
            }
        }
        return null;
    }

    /** Commentaire le plus récent (non supprimé) d'une liste, ou null. */
    private function latestComment(array $comments): ?array
    {
        $best = null;
        $bestT = -1;
        foreach ($comments as $c) {
            if (!is_array($c) || !empty($c['deleted_at'])) {
                continue;
            }
            $t = !empty($c['created_at']) ? (int)strtotime((string)$c['created_at']) : 0;
            if ($t >= $bestT) {
                $bestT = $t;
                $best  = $c;
            }
        }
        return $best;
    }

    /** Nom d'auteur d'un commentaire (champs tolérants), '' si introuvable. */
    private function commentAuthor(array $c): string
    {
        $name = $c['consultant_name']
            ?? $c['author_name']
            ?? $c['author']
            ?? trim(((string)($c['consultant_first_name'] ?? $c['first_name'] ?? '')) . ' '
                  . ((string)($c['consultant_last_name'] ?? $c['last_name'] ?? '')));
        return trim((string)$name);
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
        $note = $this->noteRepository->getNote($id);
        if ($note === []) {
            return $note;
        }

        // Normalise les photos (note + chaque commentaire) en [{id, url}] prêts
        // pour la vue : URL présignée renvoyée par l'API si présente, sinon
        // repli sur /notes/attachments/{id}/preview. La vue n'a plus à deviner
        // le nom du champ ni la forme renvoyée par l'API.
        $note['photos_view'] = $this->imageAttachments($note);
        if (!empty($note['comments']) && is_array($note['comments'])) {
            foreach ($note['comments'] as &$c) {
                if (is_array($c)) {
                    $c['photos_view'] = $this->imageAttachments($c);
                }
            }
            unset($c);
        }

        return $note;
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

