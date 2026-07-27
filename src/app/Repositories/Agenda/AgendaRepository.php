<?php
namespace App\Consultant\app\Repositories\Agenda;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Accès direct (atelierby_db) aux visites consultants et aux actions par levier.
 *
 * Deux tables : consultant_visit et consultant_lever_action (cf.
 * database/agenda_tables.sql). L'accès dégrade proprement si la base est
 * indisponible ou si les tables n'existent pas encore : chaque méthode de
 * lecture renvoie [] et les écritures renvoient false/0 plutôt que d'échouer.
 */
class AgendaRepository
{
    private bool $schemaReady = false;

    /** Surchargeable en test (PDO SQLite). */
    protected function pdo(): ?PDO
    {
        return Database::pdo();
    }

    /**
     * Crée les tables si le compte applicatif a le privilège CREATE. Idempotent
     * (IF NOT EXISTS). En cas d'échec (droits insuffisants), on ne bloque pas :
     * les tables doivent alors être créées via database/agenda_tables.sql.
     */
    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $this->schemaReady = true;
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS consultant_visit ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_consultant BIGINT UNSIGNED NOT NULL,'
                . 'consultant_name VARCHAR(190) NULL,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'shop_name VARCHAR(190) NULL,'
                . 'scheduled_at DATETIME NOT NULL,'
                . 'duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,'
                . 'goal TEXT NULL,'
                . "status VARCHAR(20) NOT NULL DEFAULT 'planned',"
                . 'report_ref VARCHAR(255) NULL,'
                . 'shared TINYINT(1) NOT NULL DEFAULT 0,'
                . 'created_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'KEY idx_cons_time (id_consultant, scheduled_at),'
                . 'KEY idx_shop_time (id_shop, scheduled_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS consultant_lever_action ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'id_visit BIGINT UNSIGNED NULL,'
                . 'id_consultant BIGINT UNSIGNED NOT NULL,'
                . 'lever VARCHAR(20) NOT NULL,'
                . 'action TEXT NOT NULL,'
                . "status VARCHAR(20) NOT NULL DEFAULT 'todo',"
                . 'created_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'KEY idx_shop_lever (id_shop, lever),'
                . 'KEY idx_visit (id_visit)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            error_log('[agenda] ensureSchema: ' . $e->getMessage());
        }
    }

    // ── Visites ────────────────────────────────────────────────────────────

    /** @return int id de la visite créée, ou 0 en cas d'échec. */
    public function createVisit(array $v): int
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO consultant_visit '
                . '(id_consultant, consultant_name, id_shop, shop_name, scheduled_at, duration_min, goal, status, report_ref, shared, created_at) '
                . 'VALUES (:c, :cn, :s, :sn, :at, :dur, :goal, :status, :ref, :shared, :now)'
            );
            $st->execute([
                ':c'      => (int)$v['id_consultant'],
                ':cn'     => $v['consultant_name'] ?? null,
                ':s'      => (int)$v['id_shop'],
                ':sn'     => $v['shop_name'] ?? null,
                ':at'     => $v['scheduled_at'],
                ':dur'    => (int)($v['duration_min'] ?? 60),
                ':goal'   => $v['goal'] ?? null,
                ':status' => $v['status'] ?? 'planned',
                ':ref'    => $v['report_ref'] ?? null,
                ':shared' => !empty($v['shared']) ? 1 : 0,
                ':now'    => $v['created_at'],
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[agenda] createVisit: ' . $e->getMessage());
            return 0;
        }
    }

    /** Visites d'un consultant sur [from, to] (dates 'Y-m-d'), ordre chronologique. */
    public function visitsForConsultant(int $consultantId, string $from, string $to): array
    {
        return $this->select(
            'SELECT * FROM consultant_visit WHERE id_consultant = :c '
            . 'AND scheduled_at >= :from AND scheduled_at < :to ORDER BY scheduled_at ASC',
            [':c' => $consultantId, ':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59']
        );
    }

    /** Toutes les visites d'une boutique (tous consultants) sur [from, to]. */
    public function visitsForShop(int $shopId, string $from, string $to): array
    {
        return $this->select(
            'SELECT * FROM consultant_visit WHERE id_shop = :s '
            . 'AND scheduled_at >= :from AND scheduled_at < :to ORDER BY scheduled_at ASC',
            [':s' => $shopId, ':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59']
        );
    }

    public function getVisit(int $id): ?array
    {
        $rows = $this->select('SELECT * FROM consultant_visit WHERE id = :id', [':id' => $id]);
        return $rows[0] ?? null;
    }

    public function updateVisitStatus(int $id, string $status): bool
    {
        return $this->exec(
            'UPDATE consultant_visit SET status = :st, updated_at = :now WHERE id = :id',
            [':st' => $status, ':now' => $this->now(), ':id' => $id]
        );
    }

    // ── Actions par levier ───────────────────────────────────────────────────

    public function addLeverAction(array $a): int
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO consultant_lever_action '
                . '(id_shop, id_visit, id_consultant, lever, action, status, created_at) '
                . 'VALUES (:s, :v, :c, :lev, :act, :status, :now)'
            );
            $st->execute([
                ':s'      => (int)$a['id_shop'],
                ':v'      => isset($a['id_visit']) ? (int)$a['id_visit'] : null,
                ':c'      => (int)$a['id_consultant'],
                ':lev'    => (string)$a['lever'],
                ':act'    => (string)$a['action'],
                ':status' => $a['status'] ?? 'todo',
                ':now'    => $a['created_at'] ?? $this->now(),
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[agenda] addLeverAction: ' . $e->getMessage());
            return 0;
        }
    }

    public function leverActionsForShop(int $shopId): array
    {
        return $this->select(
            'SELECT * FROM consultant_lever_action WHERE id_shop = :s ORDER BY lever ASC, id ASC',
            [':s' => $shopId]
        );
    }

    public function leverActionsForVisit(int $visitId): array
    {
        return $this->select(
            'SELECT * FROM consultant_lever_action WHERE id_visit = :v ORDER BY lever ASC, id ASC',
            [':v' => $visitId]
        );
    }

    public function updateLeverActionStatus(int $id, string $status): bool
    {
        return $this->exec(
            'UPDATE consultant_lever_action SET status = :st, updated_at = :now WHERE id = :id',
            [':st' => $status, ':now' => $this->now(), ':id' => $id]
        );
    }

    // ── Bas niveau ───────────────────────────────────────────────────────────

    private function select(string $sql, array $params): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        } catch (Throwable $e) {
            error_log('[agenda] select: ' . $e->getMessage());
            return [];
        }
    }

    private function exec(string $sql, array $params): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare($sql);
            return $st->execute($params);
        } catch (Throwable $e) {
            error_log('[agenda] exec: ' . $e->getMessage());
            return false;
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
