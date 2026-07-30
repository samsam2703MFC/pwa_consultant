<?php
namespace App\Consultant\app\Repositories\Kpi;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Seuils de mise en forme conditionnelle des KPI (table mac_kpi_threshold) —
 * bandes « borne basse (%) → couleur » par métrique (marge brute, marge
 * nette). AUCUNE couleur codée en dur dans les vues : tout vient d'ici.
 * Dégradation propre : sans base, les DEFAULTS (mêmes valeurs que les seeds)
 * s'appliquent.
 */
class KpiThresholdRepository
{
    /** Bandes par défaut = seeds SQL (échelles historiques de l'écran Boutiques). */
    public const DEFAULTS = [
        'net_margin' => [
            ['min' => null, 'color' => '#8B0000', 'label' => 'Perte'],
            ['min' => 0.0,  'color' => '#dc3545', 'label' => '0–5 %'],
            ['min' => 5.0,  'color' => '#e67e22', 'label' => '5–10 %'],
            ['min' => 10.0, 'color' => '#8FA31E', 'label' => '10–15 %'],
            ['min' => 15.0, 'color' => '#27ae60', 'label' => '15–25 %'],
            ['min' => 25.0, 'color' => '#C9A227', 'label' => '> 25 %'],
        ],
        'gross_margin' => [
            ['min' => null, 'color' => '#8B0000', 'label' => 'Perte'],
            ['min' => 0.0,  'color' => '#dc3545', 'label' => '< 40 %'],
            ['min' => 40.0, 'color' => '#e67e22', 'label' => '40–50 %'],
            ['min' => 50.0, 'color' => '#8FA31E', 'label' => '50–60 %'],
            ['min' => 60.0, 'color' => '#27ae60', 'label' => '60–70 %'],
            ['min' => 70.0, 'color' => '#C9A227', 'label' => '> 70 %'],
        ],
    ];

    private bool $ready = false;
    private ?array $cache = null;

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
                'CREATE TABLE IF NOT EXISTS mac_kpi_threshold ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'metric VARCHAR(32) NOT NULL,'
                . 'sort SMALLINT NOT NULL,'
                . 'min_pct DECIMAL(6,2) NULL,'
                . 'color CHAR(7) NOT NULL,'
                . 'label VARCHAR(64) NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_metric_sort (metric, sort)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Migration douce depuis l'ancienne table kpi_threshold (avant les
            // seeds, pour conserver d'éventuels seuils personnalisés).
            try {
                if ((int)$pdo->query('SELECT COUNT(*) FROM mac_kpi_threshold')->fetchColumn() === 0) {
                    $pdo->exec('INSERT IGNORE INTO mac_kpi_threshold SELECT * FROM kpi_threshold');
                }
            } catch (Throwable $e) {
                // ancienne table absente — rien à migrer
            }
            $st = $pdo->prepare(
                'INSERT IGNORE INTO mac_kpi_threshold (metric, sort, min_pct, color, label) VALUES (:me, :so, :mi, :co, :la)'
            );
            foreach (self::DEFAULTS as $metric => $bands) {
                foreach ($bands as $i => $b) {
                    $st->execute([':me' => $metric, ':so' => $i, ':mi' => $b['min'], ':co' => $b['color'], ':la' => $b['label']]);
                }
            }
        } catch (Throwable $e) {
            error_log('[kpi] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Remplace TOUTES les bandes d'une métrique (endpoint de configuration).
     * $bands : liste de ['min' => ?float, 'color' => '#rrggbb', 'label' => string],
     * triée par l'appelant. Transactionnel ; invalide le cache par requête.
     */
    public function replaceBands(string $metric, array $bands): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            $del = $pdo->prepare('DELETE FROM mac_kpi_threshold WHERE metric = :m');
            $del->execute([':m' => $metric]);
            $ins = $pdo->prepare(
                'INSERT INTO mac_kpi_threshold (metric, sort, min_pct, color, label) VALUES (:me, :so, :mi, :co, :la)'
            );
            foreach (array_values($bands) as $i => $b) {
                $ins->execute([
                    ':me' => $metric,
                    ':so' => $i,
                    ':mi' => $b['min'],
                    ':co' => $b['color'],
                    ':la' => $b['label'],
                ]);
            }
            $pdo->commit();
            $this->cache = null;
            return true;
        } catch (Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (Throwable $e2) {
            }
            error_log('[kpi] replaceBands: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Toutes les bandes, par métrique, triées de la plus basse à la plus haute.
     *
     * @return array<string, array<int, array{min: ?float, color: string, label: string}>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = self::DEFAULTS;
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $this->cache = $out;
        }
        try {
            $rows = $pdo->query('SELECT metric, min_pct, color, label FROM mac_kpi_threshold ORDER BY metric, sort')->fetchAll();
            $db = [];
            foreach ($rows as $r) {
                $db[(string)$r['metric']][] = [
                    'min'   => $r['min_pct'] !== null ? (float)$r['min_pct'] : null,
                    'color' => (string)$r['color'],
                    'label' => (string)($r['label'] ?? ''),
                ];
            }
            foreach ($db as $metric => $bands) {
                $out[$metric] = $bands;
            }
        } catch (Throwable $e) {
            error_log('[kpi] all: ' . $e->getMessage());
        }
        return $this->cache = $out;
    }
}
