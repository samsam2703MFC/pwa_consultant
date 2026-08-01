<?php
namespace App\Consultant\app\Repositories\Checklist;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Le classement réseau d'une journée, conservé (mac_network_day).
 *
 * L'endpoint `/consultant/network/tasks/ranking` ne répond que jour par jour.
 * Sans cette table, l'accueil relirait tout le mois à chaque ouverture — une
 * trentaine d'appels à chaque fois, sur le PREMIER écran d'après connexion.
 *
 * Une journée PASSÉE ne change plus : on l'écrit une fois. La journée en cours
 * n'est jamais écrite — la figer à l'heure du café donnerait un mois faux
 * jusqu'au lendemain.
 *
 * Dégradation propre : base indisponible → l'accueil se rabat sur une fenêtre
 * courte et le DIT. Il ne se tait pas, et il n'invente pas le reste du mois.
 */
class NetworkDayRepository
{
    private bool $ready = false;

    /** Motif du dernier échec : un accueil bridé sans raison visible est pire. */
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
                'CREATE TABLE IF NOT EXISTS mac_network_day ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'snap_date DATE NOT NULL,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'shop_name VARCHAR(190) NULL,'
                . 'shop_city VARCHAR(190) NULL,'
                . 'planned SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'done SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'mandatory_missed SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'tasks_failed SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'created_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_day_shop (snap_date, id_shop),'
                . 'KEY idx_day (snap_date)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[network_day] ensureSchema: ' . $e->getMessage());
        }
    }

    /** La base répond-elle ? L'accueil doit savoir s'il peut compter sur elle. */
    public function available(): bool
    {
        $this->ensureSchema();
        return $this->pdo() !== null && $this->lastError === null;
    }

    /**
     * Les journées conservées sur une plage.
     *
     * Une journée à zéro tâche prévue partout est écartée : elle vient d'un
     * endpoint muet bien plus souvent que d'un réseau réellement à l'arrêt, et
     * la garder gèlerait un jour blanc dans le mois. Elle sera relue.
     *
     * @return array<string, array<int, array>> map 'Y-m-d' => [id_shop => ligne]
     */
    public function range(string $from, string $to): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT * FROM mac_network_day
                  WHERE snap_date BETWEEN :a AND :b
                  ORDER BY snap_date, id_shop'
            );
            $st->execute([':a' => $from, ':b' => $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(string)$r['snap_date']][(int)$r['id_shop']] = [
                    'shop_id'          => (int)$r['id_shop'],
                    'shop_name'        => (string)($r['shop_name'] ?? ''),
                    'shop_city'        => (string)($r['shop_city'] ?? ''),
                    'planned'          => (int)$r['planned'],
                    'done'             => (int)$r['done'],
                    'mandatory_missed' => (int)$r['mandatory_missed'],
                    'tasks_failed'     => (int)$r['tasks_failed'],
                ];
            }
            // Un jour entièrement à zéro n'apprend rien et cache peut-être une
            // panne : on le rend « manquant » plutôt que de le graver.
            foreach ($out as $d => $lignes) {
                $planne = 0;
                foreach ($lignes as $l) {
                    $planne += $l['planned'];
                }
                if ($planne === 0) {
                    unset($out[$d]);
                }
            }
            return $out;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[network_day] range: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Écrit une journée entière — toutes ses boutiques d'un coup.
     *
     * @param array<int, array> $shops lignes normalisées (voir range())
     */
    public function putDay(string $date, array $shops): bool
    {
        if ($shops === []) {
            return false;
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_network_day
                    (snap_date, id_shop, shop_name, shop_city, planned, done,
                     mandatory_missed, tasks_failed, created_at)
                 VALUES (:d, :s, :n, :c, :pl, :dn, :mm, :tf, NOW())
                 ON DUPLICATE KEY UPDATE
                    shop_name = VALUES(shop_name), shop_city = VALUES(shop_city),
                    planned = VALUES(planned), done = VALUES(done),
                    mandatory_missed = VALUES(mandatory_missed),
                    tasks_failed = VALUES(tasks_failed), updated_at = NOW()'
            );
            foreach ($shops as $s) {
                $st->execute([
                    ':d'  => $date,
                    ':s'  => (int)$s['shop_id'],
                    ':n'  => $s['shop_name'] ?: null,
                    ':c'  => $s['shop_city'] ?: null,
                    ':pl' => (int)$s['planned'],
                    ':dn' => (int)$s['done'],
                    ':mm' => (int)$s['mandatory_missed'],
                    ':tf' => (int)$s['tasks_failed'],
                ]);
            }
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[network_day] putDay: ' . $e->getMessage());
            return false;
        }
    }
}
