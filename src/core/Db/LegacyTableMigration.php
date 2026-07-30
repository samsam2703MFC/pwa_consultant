<?php
namespace App\Consultant\core\Db;

use PDO;
use Throwable;

/**
 * Migration douce d'une ancienne table vers son équivalent préfixé (mac_).
 *
 * Copie par INTERSECTION DES COLONNES, jamais `SELECT *` : les deux tables
 * peuvent différer (colonne ajoutée après coup, ordre différent) — un
 * `SELECT *` échoue alors silencieusement et les données restent invisibles
 * pour l'application. La copie n'a lieu que si la table cible est VIDE ;
 * l'ancienne table est conservée.
 */
trait LegacyTableMigration
{
    /**
     * @return int nombre de lignes copiées (0 si rien à faire ou en cas d'échec)
     */
    protected function migrateLegacyTable(PDO $pdo, string $legacy, string $target): int
    {
        try {
            // Cible vide seulement (idempotent, jamais de doublon).
            if ((int)$pdo->query('SELECT COUNT(*) FROM `' . $target . '`')->fetchColumn() > 0) {
                return 0;
            }
            $cols = array_values(array_intersect(
                $this->tableColumns($pdo, $legacy),
                $this->tableColumns($pdo, $target)
            ));
            if ($cols === []) {
                return 0;   // ancienne table absente ou aucune colonne commune
            }
            $list = '`' . implode('`, `', $cols) . '`';
            $pdo->exec("INSERT INTO `$target` ($list) SELECT $list FROM `$legacy`");
            return (int)$pdo->query('SELECT COUNT(*) FROM `' . $target . '`')->fetchColumn();
        } catch (Throwable $e) {
            error_log("[migration] $legacy → $target : " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Colonnes d'une table (MySQL ou SQLite), [] si la table n'existe pas.
     *
     * @return string[]
     */
    protected function tableColumns(PDO $pdo, string $table): array
    {
        // MySQL : SHOW COLUMNS ; SQLite (tests) : PRAGMA table_info.
        try {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r) => (string)($r['Field'] ?? ''), $rows);
        } catch (Throwable $e) {
            try {
                $rows = $pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
                return array_map(fn($r) => (string)($r['name'] ?? ''), $rows);
            } catch (Throwable $e2) {
                return [];
            }
        }
    }
}
