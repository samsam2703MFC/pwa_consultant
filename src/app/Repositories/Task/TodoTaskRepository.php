<?php
namespace App\Consultant\app\Repositories\Task;

use App\Consultant\core\Db\Database;
use Throwable;

/**
 * Tâches prédéfinies de boutique, lues dans la table `todo_task` de la base
 * locale (data-driven : ajouter/modifier une tâche ne demande aucun code).
 * Les colonnes sont détectées dynamiquement pour tolérer les variations de
 * schéma ; table ou colonne nom introuvable → liste vide (le formulaire
 * retombe alors sur la note libre).
 */
class TodoTaskRepository
{
    /**
     * @return array<int, array{id:int, name:string}>  triées par nom
     */
    public function getTasks(): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_task'"
            );
            $names = array_map(fn($r) => (string)$r['COLUMN_NAME'], $stmt->fetchAll());
            if ($names === []) {
                return [];
            }

            $nameCol = null;
            foreach (['name', 'title', 'label', 'task_name', 'task'] as $cand) {
                foreach ($names as $n) {
                    if (strcasecmp($n, $cand) === 0) { $nameCol = $n; break 2; }
                }
            }
            if ($nameCol === null) {
                foreach ($names as $n) {
                    if (stripos($n, 'name') !== false) { $nameCol = $n; break; }
                }
            }
            if ($nameCol === null) {
                return [];
            }

            $idCol = in_array('id', array_map('strtolower', $names), true) ? 'id' : $names[0];

            $activeCol = null;
            foreach (['active', 'is_active', 'enabled'] as $cand) {
                foreach ($names as $n) {
                    if (strcasecmp($n, $cand) === 0) { $activeCol = $n; break 2; }
                }
            }

            $sql = "SELECT `$idCol` AS id, `$nameCol` AS name FROM todo_task"
                 . ($activeCol !== null ? " WHERE `$activeCol` = 1" : '')
                 . " ORDER BY `$nameCol` LIMIT 300";

            $out = [];
            foreach ($pdo->query($sql)->fetchAll() as $row) {
                $label = trim((string)$row['name']);
                if ($label !== '') {
                    $out[] = ['id' => (int)$row['id'], 'name' => $label];
                }
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] TodoTaskRepository::getTasks échoué: ' . $e->getMessage());
            return [];
        }
    }
}
