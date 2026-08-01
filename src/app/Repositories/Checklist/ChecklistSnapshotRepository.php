<?php
namespace App\Consultant\app\Repositories\Checklist;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Photo journalière des checklists d'une boutique (mac_checklist_day_snapshot).
 *
 * L'API ne répond que jour par jour : aucun endpoint ne couvre une plage. Sans
 * cette table, un rapport mensuel relirait ~26 jours à chaque ouverture. Un
 * jour CLOS ne changeant plus, on le fige une fois.
 *
 * Dégradation propre : si la base est indisponible, le rapport se construit
 * quand même — il paie simplement les allers-retours à chaque génération.
 */
class ChecklistSnapshotRepository
{
    private bool $ready = false;

    /** Motif du dernier échec : un rapport lent sans raison visible est pire. */
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
            $this->lastError = 'base injoignable';
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_checklist_day_snapshot ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'snap_date DATE NOT NULL,'
                . 'planned SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'done SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'reviewed SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'conform SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'nc_major SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'nc_minor SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'blocking_missed SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'rating_sum SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'rating_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'nc_json MEDIUMTEXT NULL,'
                . 'checklists_json TEXT NULL,'
                . 'conform_ids_json TEXT NULL,'
                . 'is_final TINYINT(1) NOT NULL DEFAULT 0,'
                . 'created_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_shop_day (id_shop, snap_date),'
                . 'KEY idx_day (snap_date)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[snapshot] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Jours figés d'une boutique sur une plage.
     *
     * Un jour NON figé (is_final = 0) n'est pas renvoyé : il sera relu à
     * l'API. Rendre un jour ouvert comme s'il était clos gèlerait un rapport
     * sur un état partiel — le pire des deux mondes.
     *
     * @return array<string, array> map 'Y-m-d' => jour
     */
    public function range(int $shopId, string $from, string $to): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                // `planned > 0` : un jour figé à ZÉRO est écarté et sera relu.
                // Une version antérieure gravait les jours vides — y compris
                // ceux vidés par une panne d'endpoint — et le rapport restait
                // bloqué à 0/0 sans jamais réinterroger. Ce filtre répare ces
                // lignes-là sans migration : elles redeviennent « manquantes ».
                'SELECT * FROM mac_checklist_day_snapshot
                  WHERE id_shop = :s AND snap_date BETWEEN :a AND :b
                    AND is_final = 1 AND planned > 0
                  ORDER BY snap_date'
            );
            $st->execute([':s' => $shopId, ':a' => $from, ':b' => $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(string)$r['snap_date']] = $this->hydrate($r);
            }
            return $out;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[snapshot] range: ' . $e->getMessage());
            return [];
        }
    }

    /** Décode les colonnes JSON une bonne fois, pour que l'appelant n'y pense pas. */
    private function hydrate(array $r): array
    {
        $json = function ($raw) {
            if (!is_string($raw) || $raw === '') {
                return [];
            }
            $v = json_decode($raw, true);
            return is_array($v) ? $v : [];
        };
        return [
            'date'            => (string)$r['snap_date'],
            'planned'         => (int)$r['planned'],
            'done'            => (int)$r['done'],
            'reviewed'        => (int)$r['reviewed'],
            'conform'         => (int)$r['conform'],
            'nc_major'        => (int)$r['nc_major'],
            'nc_minor'        => (int)$r['nc_minor'],
            'blocking_missed' => (int)$r['blocking_missed'],
            'rating_sum'      => (int)$r['rating_sum'],
            'rating_count'    => (int)$r['rating_count'],
            'nc'              => $json($r['nc_json'] ?? null),
            'checklists'      => $json($r['checklists_json'] ?? null),
            // Tâches repassées conformes ce jour-là : sans elles, « NC levée »
            // ne serait qu'une déduction par absence après relecture du cache.
            'conform_ids'     => $json($r['conform_ids_json'] ?? null),
        ];
    }

    /**
     * Écrit (ou met à jour) un jour. `is_final` reste à l'appelant : lui seul
     * sait si la journée est close.
     */
    public function put(int $shopId, array $day, bool $final): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_checklist_day_snapshot
                    (id_shop, snap_date, planned, done, reviewed, conform, nc_major, nc_minor,
                     blocking_missed, rating_sum, rating_count, nc_json, checklists_json,
                     conform_ids_json, is_final, created_at)
                 VALUES (:s, :d, :pl, :dn, :rv, :cf, :ma, :mi, :bm, :rs, :rc, :nj, :cj, :oj, :fi, NOW())
                 ON DUPLICATE KEY UPDATE
                    planned = VALUES(planned), done = VALUES(done), reviewed = VALUES(reviewed),
                    conform = VALUES(conform), nc_major = VALUES(nc_major), nc_minor = VALUES(nc_minor),
                    blocking_missed = VALUES(blocking_missed), rating_sum = VALUES(rating_sum),
                    rating_count = VALUES(rating_count), nc_json = VALUES(nc_json),
                    checklists_json = VALUES(checklists_json),
                    conform_ids_json = VALUES(conform_ids_json), is_final = VALUES(is_final),
                    updated_at = NOW()'
            );
            $enc = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
            return $st->execute([
                ':s'  => $shopId,
                ':d'  => $day['date'],
                ':pl' => (int)$day['planned'],
                ':dn' => (int)$day['done'],
                ':rv' => (int)$day['reviewed'],
                ':cf' => (int)$day['conform'],
                ':ma' => (int)$day['nc_major'],
                ':mi' => (int)$day['nc_minor'],
                ':bm' => (int)$day['blocking_missed'],
                ':rs' => (int)$day['rating_sum'],
                ':rc' => (int)$day['rating_count'],
                ':nj' => $enc($day['nc'] ?? []),
                ':cj' => $enc($day['checklists'] ?? []),
                ':oj' => $enc($day['conform_ids'] ?? []),
                ':fi' => $final ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[snapshot] put: ' . $e->getMessage());
            return false;
        }
    }
}
