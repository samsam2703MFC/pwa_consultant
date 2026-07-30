<?php
namespace App\Consultant\app\Repositories\Checklist;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Journal des avis consultant sur les tâches réalisées (mac_task_review).
 *
 * L'API d'avancement des checklists expose la note, la conformité et le
 * commentaire (review_rating, review_is_accepted, review_comment) mais PAS qui
 * a évalué ni quand. Le panel, lui, connaît le consultant connecté au moment
 * où l'avis est posé : on le consigne ici.
 *
 * Cette table sert deux usages : afficher « Vérifié par … » sur la tâche, et
 * suivre l'activité d'évaluation par consultant. Dégradation propre si la base
 * est indisponible — l'avis part quand même à l'API.
 */
class TaskReviewRepository
{
    private bool $ready = false;

    /**
     * Motif du dernier échec d'écriture (table absente, privilège manquant,
     * base injoignable). Sans lui, un journal muet se traduit par un
     * « Vérifié par » sans nom, sans qu'on sache pourquoi.
     */
    public ?string $lastError = null;

    protected function pdo(): ?PDO
    {
        return Database::pdo();
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->ready = true;
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_task_review ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'id_checklist BIGINT UNSIGNED NULL,'
                . 'id_task BIGINT UNSIGNED NOT NULL,'
                . 'review_date DATE NOT NULL,'
                . 'completion_id BIGINT UNSIGNED NULL,'
                . 'id_consultant BIGINT UNSIGNED NOT NULL,'
                . 'consultant_name VARCHAR(190) NULL,'
                . 'rating TINYINT UNSIGNED NULL,'
                . 'is_accepted TINYINT(1) NULL,'
                . 'comment TEXT NULL,'
                . 'created_at DATETIME NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_review (id_shop, id_task, review_date),'
                . 'KEY idx_consultant (id_consultant, review_date),'
                . 'KEY idx_shop_date (id_shop, review_date)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Validation par l'Owner (celui qui contrôle les consultants).
            // ALTER séparés et tolérants : sur une table déjà créée par une
            // version antérieure, les colonnes manquent.
            foreach ([
                'owner_validated_at DATETIME NULL',
                'id_owner BIGINT UNSIGNED NULL',
                'owner_name VARCHAR(190) NULL',
            ] as $col) {
                try {
                    $pdo->exec('ALTER TABLE mac_task_review ADD COLUMN ' . $col);
                } catch (Throwable $e) {
                    // colonne déjà présente — rien à faire
                }
            }
        } catch (Throwable $e) {
            $this->lastError = 'schéma : ' . $e->getMessage();
            error_log('[task_review] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Validation (ou retrait de validation) de l'avis d'un consultant par
     * l'Owner. Ne crée pas de ligne : on ne valide que ce qui a été évalué.
     */
    public function setOwnerValidation(
        int $shopId,
        int $taskId,
        string $date,
        bool $validated,
        int $ownerId,
        ?string $ownerName
    ): bool {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'UPDATE mac_task_review SET owner_validated_at = :at, id_owner = :oid,'
                . ' owner_name = :oname, updated_at = :now'
                . ' WHERE id_shop = :s AND id_task = :t AND review_date = :d'
            );
            $st->execute([
                ':at'    => $validated ? date('Y-m-d H:i:s') : null,
                ':oid'   => $validated ? $ownerId : null,
                ':oname' => $validated && $ownerName !== null ? mb_substr($ownerName, 0, 190) : null,
                ':now'   => date('Y-m-d H:i:s'),
                ':s'     => $shopId,
                ':t'     => $taskId,
                ':d'     => $date,
            ]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[task_review] setOwnerValidation: ' . $e->getMessage());
            return false;
        }
    }

    /** Enregistre (ou met à jour) l'avis d'un consultant sur une tâche. */
    public function upsert(array $r): bool
    {
        $this->lastError = null;
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            $this->lastError = 'base de données injoignable (config/db.local.php)';
            return false;
        }
        if (empty($r['id_shop']) || empty($r['id_task']) || empty($r['review_date'])) {
            $this->lastError = 'id_shop, id_task et review_date sont requis';
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_task_review '
                . '(id_shop, id_checklist, id_task, review_date, completion_id, id_consultant,'
                . ' consultant_name, rating, is_accepted, comment, created_at, updated_at) '
                . 'VALUES (:shop, :cl, :task, :date, :comp, :cons, :name, :rating, :acc, :cmt, :now, :now) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'id_checklist = VALUES(id_checklist), completion_id = VALUES(completion_id),'
                . 'id_consultant = VALUES(id_consultant), consultant_name = VALUES(consultant_name),'
                . 'rating = VALUES(rating), is_accepted = VALUES(is_accepted),'
                . 'comment = VALUES(comment), updated_at = VALUES(updated_at)'
            );
            return $st->execute([
                ':shop'   => (int)$r['id_shop'],
                ':cl'     => !empty($r['id_checklist']) ? (int)$r['id_checklist'] : null,
                ':task'   => (int)$r['id_task'],
                ':date'   => (string)$r['review_date'],
                ':comp'   => !empty($r['completion_id']) ? (int)$r['completion_id'] : null,
                ':cons'   => (int)($r['id_consultant'] ?? 0),
                ':name'   => $r['consultant_name'] !== null ? mb_substr((string)$r['consultant_name'], 0, 190) : null,
                ':rating' => isset($r['rating']) && $r['rating'] !== null ? (int)$r['rating'] : null,
                ':acc'    => isset($r['is_accepted']) && $r['is_accepted'] !== null ? (int)(bool)$r['is_accepted'] : null,
                ':cmt'    => $r['comment'] !== null ? (string)$r['comment'] : null,
                ':now'    => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[task_review] upsert: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Avis d'une boutique pour une journée.
     *
     * @return array<int, array> map id_task => avis
     */
    public function forShopDate(int $shopId, string $date): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT * FROM mac_task_review WHERE id_shop = :s AND review_date = :d'
            );
            $st->execute([':s' => $shopId, ':d' => $date]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['id_task']] = $row;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[task_review] forShopDate: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Activité d'évaluation par consultant sur une période — pour le suivi des
     * consultants (combien d'avis, quelle note moyenne, combien de refus).
     *
     * @return array<int, array{id_consultant:int, consultant_name:?string,
     *         reviews:int, shops:int, avg_rating:?float, refused:int, last_at:?string}>
     */
    public function statsByConsultant(string $from, string $to): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT id_consultant, MAX(consultant_name) AS consultant_name,'
                . ' COUNT(*) AS reviews, COUNT(DISTINCT id_shop) AS shops,'
                . ' AVG(rating) AS avg_rating,'
                . ' SUM(CASE WHEN is_accepted = 0 THEN 1 ELSE 0 END) AS refused,'
                . ' SUM(CASE WHEN owner_validated_at IS NOT NULL THEN 1 ELSE 0 END) AS validated,'
                . ' MAX(updated_at) AS last_at'
                . ' FROM mac_task_review WHERE review_date BETWEEN :f AND :t'
                . ' GROUP BY id_consultant ORDER BY reviews DESC'
            );
            $st->execute([':f' => $from, ':t' => $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[] = [
                    'id_consultant'   => (int)$row['id_consultant'],
                    'consultant_name' => $row['consultant_name'] !== null ? (string)$row['consultant_name'] : null,
                    'reviews'         => (int)$row['reviews'],
                    'shops'           => (int)$row['shops'],
                    'avg_rating'      => $row['avg_rating'] !== null ? round((float)$row['avg_rating'], 2) : null,
                    'refused'         => (int)$row['refused'],
                    'validated'       => (int)($row['validated'] ?? 0),
                    'last_at'         => $row['last_at'] !== null ? (string)$row['last_at'] : null,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[task_review] statsByConsultant: ' . $e->getMessage());
            return [];
        }
    }
}
