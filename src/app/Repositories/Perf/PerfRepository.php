<?php
namespace App\Consultant\app\Repositories\Perf;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Les temps de rendu, conservés (mac_consultant_perf).
 *
 * Tout ce qui a été dit jusqu'ici sur la vitesse de l'application — le cache,
 * le préchargement au login, le coût des écrans en éventail — repose sur des
 * ESTIMATIONS. Cette table les remplace par des mesures.
 *
 * Règle de conduite : mesurer ne doit jamais ralentir. L'écriture se fait
 * après la réponse (voir PerfRecorder), et toute panne de base est avalée en
 * silence — un incident de mesure ne casse pas un écran.
 */
class PerfRepository
{
    private bool $ready = false;

    /** Motif du dernier échec : une mesure absente doit pouvoir s'expliquer. */
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
            $this->lastError = Database::unavailableReason();
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_consultant_perf ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'rid CHAR(16) NOT NULL,'
                . 'ts DATETIME NOT NULL,'
                . 'snap_date DATE NOT NULL,'
                . 'hour_of_day TINYINT UNSIGNED NOT NULL,'
                . 'day_of_week TINYINT UNSIGNED NOT NULL,'
                . 'user_key VARCHAR(40) NOT NULL,'
                . 'route VARCHAR(160) NOT NULL,'
                . 'method VARCHAR(8) NOT NULL,'
                . 'server_ms MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'api_ms MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'api_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'api_cached SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'api_failed SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'client_ms MEDIUMINT UNSIGNED NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_rid (rid),'
                . 'KEY idx_date_hour (snap_date, hour_of_day),'
                . 'KEY idx_route_date (route, snap_date)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[perf] ensureSchema: ' . $e->getMessage());
        }
    }

    public function available(): bool
    {
        $this->ensureSchema();
        return $this->pdo() !== null && $this->lastError === null;
    }

    /**
     * Une mesure. Jamais bloquante : en cas d'échec, on renonce sans bruit
     * côté écran (le motif part dans le log d'erreurs).
     */
    public function record(array $m): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_consultant_perf
                    (rid, ts, snap_date, hour_of_day, day_of_week, user_key,
                     route, method, server_ms, api_ms, api_calls, api_cached, api_failed)
                 VALUES (:rid, :ts, :d, :h, :w, :u, :r, :m, :sm, :am, :ac, :ah, :af)'
            );
            return $st->execute([
                ':rid' => (string)$m['rid'],
                ':ts'  => (string)$m['ts'],
                ':d'   => (string)$m['date'],
                ':h'   => (int)$m['hour'],
                ':w'   => (int)$m['dow'],
                ':u'   => (string)$m['user_key'],
                ':r'   => (string)$m['route'],
                ':m'   => (string)$m['method'],
                ':sm'  => (int)$m['server_ms'],
                ':am'  => (int)$m['api_ms'],
                ':ac'  => (int)$m['api_calls'],
                ':ah'  => (int)$m['api_cached'],
                ':af'  => (int)$m['api_failed'],
            ]);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[perf] record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Le temps VÉCU, renvoyé par le navigateur après coup.
     *
     * On ne crée jamais de ligne ici : une balise sans mesure serveur
     * correspondante est soit un rejeu, soit une ligne déjà purgée. On
     * l'ignore plutôt que d'inventer un affichage.
     */
    public function completeClient(string $rid, int $clientMs): bool
    {
        if ($clientMs <= 0 || $clientMs > 300000) {
            return false;           // hors de toute plausibilité : onglet dormant
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'UPDATE mac_consultant_perf SET client_ms = :c
                  WHERE rid = :r AND client_ms IS NULL'
            );
            $st->execute([':c' => $clientMs, ':r' => $rid]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[perf] completeClient: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Les cases de la heatmap : écran × heure.
     *
     * `avg_ms` prend le temps VÉCU quand le navigateur l'a renvoyé, et retombe
     * sur le temps serveur sinon — sans quoi les écrans quittés trop vite
     * disparaîtraient de la carte, c'est-à-dire les plus lents.
     *
     * @return array<int, array>
     */
    public function heatmap(string $from, string $to): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT route, hour_of_day, day_of_week,
                        COUNT(*)                                   AS n,
                        ROUND(AVG(COALESCE(client_ms, server_ms))) AS avg_ms,
                        ROUND(AVG(server_ms))                      AS avg_server_ms,
                        ROUND(AVG(api_ms))                         AS avg_api_ms,
                        ROUND(AVG(api_calls), 1)                   AS avg_calls,
                        SUM(api_cached)                            AS cached,
                        SUM(api_calls)                             AS called,
                        SUM(api_failed)                            AS failed
                   FROM mac_consultant_perf
                  WHERE snap_date BETWEEN :a AND :b
                  GROUP BY route, hour_of_day, day_of_week'
            );
            $st->execute([':a' => $from, ':b' => $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = [
                    'route'         => (string)$r['route'],
                    'hour'          => (int)$r['hour_of_day'],
                    'dow'           => (int)$r['day_of_week'],
                    'n'             => (int)$r['n'],
                    'avg_ms'        => (int)$r['avg_ms'],
                    'avg_server_ms' => (int)$r['avg_server_ms'],
                    'avg_api_ms'    => (int)$r['avg_api_ms'],
                    'avg_calls'     => (float)$r['avg_calls'],
                    'cached'        => (int)$r['cached'],
                    'called'        => (int)$r['called'],
                    'failed'        => (int)$r['failed'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[perf] heatmap: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Les mesures brutes d'une fenêtre, pour calculer des MÉDIANES.
     *
     * La moyenne d'un temps de réponse ment : un seul affichage à 12 s tire
     * tout un écran vers le haut. Les centiles se calculent sur les valeurs,
     * pas sur des agrégats — d'où cette lecture, plafonnée pour ne jamais
     * ramener un an de mesures d'un coup.
     *
     * @return array<int, array{route:string, ms:int, server_ms:int, api_ms:int, api_calls:int, api_cached:int}>
     */
    public function samples(string $from, string $to, int $limit = 20000): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $limit = max(100, min(100000, $limit));
        try {
            $st = $pdo->prepare(
                'SELECT route, COALESCE(client_ms, server_ms) AS ms,
                        server_ms, api_ms, api_calls, api_cached
                   FROM mac_consultant_perf
                  WHERE snap_date BETWEEN :a AND :b
                  ORDER BY id DESC
                  LIMIT ' . $limit
            );
            $st->execute([':a' => $from, ':b' => $to]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = [
                    'route'      => (string)$r['route'],
                    'ms'         => (int)$r['ms'],
                    'server_ms'  => (int)$r['server_ms'],
                    'api_ms'     => (int)$r['api_ms'],
                    'api_calls'  => (int)$r['api_calls'],
                    'api_cached' => (int)$r['api_cached'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[perf] samples: ' . $e->getMessage());
            return [];
        }
    }

    /** Le nombre total de mesures conservées (pour dire « rien à montrer »). */
    public function count(): int
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        try {
            return (int)$pdo->query('SELECT COUNT(*) FROM mac_consultant_perf')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Purge au-delà de la rétention. Appelée de loin en loin, pas à chaque vue. */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        try {
            $st = $pdo->prepare(
                'DELETE FROM mac_consultant_perf
                  WHERE snap_date < (CURDATE() - INTERVAL :d DAY) LIMIT 5000'
            );
            $st->bindValue(':d', $days, PDO::PARAM_INT);
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('[perf] purge: ' . $e->getMessage());
            return 0;
        }
    }
}
