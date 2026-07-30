<?php
namespace App\Consultant\app\Repositories\Agenda;

use App\Consultant\core\Db\Database;
use App\Consultant\core\Db\LegacyTableMigration;
use PDO;
use Throwable;

/**
 * Accès direct (atelierby_db) aux visites consultants et aux actions par levier.
 *
 * Deux tables : mac_consultant_visit et mac_consultant_lever_action (cf.
 * database/agenda_tables.sql). L'accès dégrade proprement si la base est
 * indisponible ou si les tables n'existent pas encore : chaque méthode de
 * lecture renvoie [] et les écritures renvoient false/0 plutôt que d'échouer.
 */
class AgendaRepository
{
    use LegacyTableMigration;

    private bool $schemaReady = false;

    /** Colonnes de mac_consultant_visit (cache par requête). */
    private ?array $visitCols = null;

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
                'CREATE TABLE IF NOT EXISTS mac_consultant_visit ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_consultant BIGINT UNSIGNED NOT NULL,'
                . 'consultant_name VARCHAR(190) NULL,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'shop_name VARCHAR(190) NULL,'
                . 'scheduled_at DATETIME NOT NULL,'
                . 'duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,'
                . "type VARCHAR(20) NOT NULL DEFAULT 'development',"
                . 'goal TEXT NULL,'
                . "status VARCHAR(20) NOT NULL DEFAULT 'planned',"
                . 'report_ref VARCHAR(255) NULL,'
                . 'id_checklist BIGINT UNSIGNED NULL,'
                . 'checklist_name VARCHAR(190) NULL,'
                . 'lever_period CHAR(7) NULL,'
                . 'shared TINYINT(1) NOT NULL DEFAULT 0,'
                . 'created_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'KEY idx_cons_time (id_consultant, scheduled_at),'
                . 'KEY idx_shop_time (id_shop, scheduled_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Migration douce : colonnes ajoutées après coup (ignore si présentes).
            foreach ([
                "type VARCHAR(20) NOT NULL DEFAULT 'development'",
                'id_checklist BIGINT UNSIGNED NULL',
                'checklist_name VARCHAR(190) NULL',
                'lever_period CHAR(7) NULL',
            ] as $col) {
                try {
                    $pdo->exec('ALTER TABLE mac_consultant_visit ADD COLUMN ' . $col);
                } catch (Throwable $e) {
                    // colonne déjà présente → rien à faire
                }
            }
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_consultant_lever_action ('
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
            // Migration douce depuis les anciennes tables : copie par INTERSECTION
            // des colonnes (un SELECT * échoue si les schémas diffèrent — ex.
            // `type` ajouté après coup — et les visites resteraient invisibles).
            // Ids conservés → id_visit reste cohérent ; anciennes tables gardées.
            $this->migrateLegacyTable($pdo, 'consultant_visit', 'mac_consultant_visit');
            $this->migrateLegacyTable($pdo, 'consultant_lever_action', 'mac_consultant_lever_action');
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
        // Colonnes réellement présentes : si un ALTER n'a pas pu s'exécuter
        // (droits insuffisants), on insère sans les colonnes récentes plutôt
        // que de faire échouer toute la création.
        $data = $this->onlyExistingColumns([
            'id_consultant'   => (int)$v['id_consultant'],
            'consultant_name' => $v['consultant_name'] ?? null,
            'id_shop'         => (int)$v['id_shop'],
            'shop_name'       => $v['shop_name'] ?? null,
            'scheduled_at'    => $v['scheduled_at'],
            'duration_min'    => (int)($v['duration_min'] ?? 60),
            'type'            => $v['type'] ?? 'development',
            'goal'            => $v['goal'] ?? null,
            'status'          => $v['status'] ?? 'planned',
            'report_ref'      => $v['report_ref'] ?? null,
            'id_checklist'    => !empty($v['id_checklist']) ? (int)$v['id_checklist'] : null,
            'checklist_name'  => $v['checklist_name'] ?? null,
            'lever_period'    => $v['lever_period'] ?? null,
            'shared'          => !empty($v['shared']) ? 1 : 0,
            'created_at'      => $v['created_at'],
        ]);
        if ($data === []) {
            return 0;
        }
        try {
            $cols   = array_keys($data);
            $params = [];
            foreach ($data as $k => $val) {
                $params[':' . $k] = $val;
            }
            $st = $pdo->prepare(
                'INSERT INTO mac_consultant_visit (`' . implode('`, `', $cols) . '`) '
                . 'VALUES (:' . implode(', :', $cols) . ')'
            );
            $st->execute($params);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[agenda] createVisit: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Filtre un jeu de données sur les colonnes réellement présentes dans
     * mac_consultant_visit (cache par requête) — les colonnes récentes (type,
     * id_checklist, checklist_name, lever_period) peuvent manquer si l'ALTER
     * n'a pas pu s'exécuter. Introspection impossible → données inchangées.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function onlyExistingColumns(array $data): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        if ($this->visitCols === null) {
            $this->visitCols = $this->tableColumns($pdo, 'mac_consultant_visit');
        }
        if ($this->visitCols === []) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->visitCols));
    }

    /** Visites d'un consultant sur [from, to] (dates 'Y-m-d'), ordre chronologique. */
    public function visitsForConsultant(int $consultantId, string $from, string $to): array
    {
        return $this->select(
            'SELECT * FROM mac_consultant_visit WHERE id_consultant = :c '
            . 'AND scheduled_at >= :from AND scheduled_at < :to ORDER BY scheduled_at ASC',
            [':c' => $consultantId, ':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59']
        );
    }

    /** Toutes les visites d'une boutique (tous consultants) sur [from, to]. */
    public function visitsForShop(int $shopId, string $from, string $to): array
    {
        return $this->select(
            'SELECT * FROM mac_consultant_visit WHERE id_shop = :s '
            . 'AND scheduled_at >= :from AND scheduled_at < :to ORDER BY scheduled_at ASC',
            [':s' => $shopId, ':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59']
        );
    }

    public function getVisit(int $id): ?array
    {
        $rows = $this->select('SELECT * FROM mac_consultant_visit WHERE id = :id', [':id' => $id]);
        return $rows[0] ?? null;
    }

    public function updateVisitStatus(int $id, string $status): bool
    {
        return $this->exec(
            'UPDATE mac_consultant_visit SET status = :st, updated_at = :now WHERE id = :id',
            [':st' => $status, ':now' => $this->now(), ':id' => $id]
        );
    }

    /** Met à jour les champs éditables d'une visite. */
    public function updateVisit(int $id, array $v): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $data = [
            'id_shop'        => (int)$v['id_shop'],
            'shop_name'      => $v['shop_name'] ?? null,
            'scheduled_at'   => $v['scheduled_at'],
            'duration_min'   => (int)($v['duration_min'] ?? 60),
            'type'           => $v['type'] ?? 'development',
            'goal'           => $v['goal'] ?? null,
            'report_ref'     => $v['report_ref'] ?? null,
            'id_checklist'   => !empty($v['id_checklist']) ? (int)$v['id_checklist'] : null,
            'checklist_name' => $v['checklist_name'] ?? null,
            'lever_period'   => $v['lever_period'] ?? null,
            'shared'         => !empty($v['shared']) ? 1 : 0,
            'updated_at'     => $this->now(),
        ];
        $data = $this->onlyExistingColumns($data);
        if ($data === []) {
            return false;
        }
        $sets   = [];
        $params = [':id' => $id];
        foreach ($data as $k => $val) {
            $sets[] = '`' . $k . '` = :' . $k;
            $params[':' . $k] = $val;
        }
        return $this->exec(
            'UPDATE mac_consultant_visit SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }

    /** Supprime les actions par levier rattachées à une visite (avant réécriture). */
    public function deleteLeverActionsForVisit(int $visitId): bool
    {
        return $this->exec('DELETE FROM mac_consultant_lever_action WHERE id_visit = :v', [':v' => $visitId]);
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
                'INSERT INTO mac_consultant_lever_action '
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
            'SELECT * FROM mac_consultant_lever_action WHERE id_shop = :s ORDER BY lever ASC, id ASC',
            [':s' => $shopId]
        );
    }

    public function leverActionsForVisit(int $visitId): array
    {
        return $this->select(
            'SELECT * FROM mac_consultant_lever_action WHERE id_visit = :v ORDER BY lever ASC, id ASC',
            [':v' => $visitId]
        );
    }

    public function updateLeverActionStatus(int $id, string $status): bool
    {
        return $this->exec(
            'UPDATE mac_consultant_lever_action SET status = :st, updated_at = :now WHERE id = :id',
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
