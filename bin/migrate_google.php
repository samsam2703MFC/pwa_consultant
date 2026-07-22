<?php
/**
 * Migration idempotente exécutée SUR LE SERVEUR au déploiement (SSH → php).
 * Le sandbox de dev n'a pas d'accès réseau à la base ; le serveur, lui,
 * parle à la base locale (127.0.0.1). Ce script :
 *   1. ajoute les colonnes google_place_id / google_address à `shops`
 *      si elles manquent,
 *   2. amorce google_place_id depuis config/google.places.php (seed
 *      committé) SANS écraser une valeur déjà saisie en base.
 *
 * Rejouable sans risque : détecte l'existant, ne remplit que les NULL.
 * Base absente (db.local.php non généré) → sort proprement, code 0.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Consultant\core\Db\Database;

$pdo = Database::pdo();
if ($pdo === null) {
    fwrite(STDERR, "[migrate_google] base indisponible (config/db.local.php absent ?) — ignoré.\n");
    exit(0);
}

try {
    // Colonnes existantes de `shops`.
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `shops`")->fetchAll() as $r) {
        $cols[strtolower((string)$r['Field'])] = true;
    }

    if (!isset($cols['google_place_id'])) {
        $pdo->exec("ALTER TABLE `shops` ADD COLUMN `google_place_id` VARCHAR(128) NULL "
                 . "COMMENT 'Place ID Google Business (note + avis)'");
        echo "[migrate_google] colonne google_place_id ajoutée\n";
    }
    if (!isset($cols['google_address'])) {
        $pdo->exec("ALTER TABLE `shops` ADD COLUMN `google_address` VARCHAR(255) NULL "
                 . "COMMENT 'Adresse exacte de la fiche Google Business'");
        echo "[migrate_google] colonne google_address ajoutée\n";
    }

    // Seed des Place IDs (committé). N'écrase PAS une valeur déjà en base :
    // la base reste la source de vérité une fois renseignée.
    $seedFile = __DIR__ . '/../config/google.places.php';
    $ids = is_file($seedFile) ? ((require $seedFile)['place_ids'] ?? []) : [];
    if (is_array($ids) && $ids !== []) {
        $st = $pdo->prepare(
            "UPDATE `shops` SET `google_place_id` = :pid "
          . "WHERE `id` = :id AND (`google_place_id` IS NULL OR `google_place_id` = '')"
        );
        foreach ($ids as $id => $pid) {
            $st->execute([':pid' => (string)$pid, ':id' => (int)$id]);
            if ($st->rowCount() > 0) {
                echo "[migrate_google] shop $id ← $pid\n";
            }
        }
    }

    echo "[migrate_google] OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate_google] échec: " . $e->getMessage() . "\n");
    exit(0);   // ne bloque jamais le déploiement
}
