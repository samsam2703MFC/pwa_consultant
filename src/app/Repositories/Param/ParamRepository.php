<?php
namespace App\Consultant\app\Repositories\Param;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Paramètres configurables (table consultant_param) — clé/valeur.
 *
 * Objectif : AUCUNE constante métier codée en dur. Les valeurs (ex. multiple
 * de valorisation, marge cible) vivent en base et sont modifiables sans
 * redéploiement. Dégradation propre si la base est indisponible.
 *
 * Les valeurs par défaut ci-dessous sont des SEEDS (insérés une seule fois si
 * absents), pas des constantes de calcul : le code lit toujours la base.
 */
class ParamRepository
{
    /** Paramètres connus : clé => [valeur initiale, libellé]. Sert au seed. */
    public const DEFAULTS = [
        'valuation_multiple'              => ['4.5', 'Multiple de valorisation (× résultat net)'],
        'valuation_target_net_margin_pct' => ['15',  'Marge nette cible (%) — valorisation à l\'objectif'],
    ];

    private bool $ready = false;

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
                'CREATE TABLE IF NOT EXISTS consultant_param ('
                . 'param_key VARCHAR(64) NOT NULL,'
                . 'param_value VARCHAR(255) NOT NULL,'
                . 'label VARCHAR(190) NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (param_key)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $st = $pdo->prepare('INSERT IGNORE INTO consultant_param (param_key, param_value, label) VALUES (:k, :v, :l)');
            foreach (self::DEFAULTS as $key => [$val, $label]) {
                $st->execute([':k' => $key, ':v' => $val, ':l' => $label]);
            }
        } catch (Throwable $e) {
            error_log('[param] ensureSchema: ' . $e->getMessage());
        }
    }

    /** @return array<string,string> map clé => valeur (fusionne les défauts). */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => [$v]) {
            $out[$k] = $v;
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $out;
        }
        try {
            $rows = $pdo->query('SELECT param_key, param_value FROM consultant_param')->fetchAll();
            foreach ($rows as $r) {
                $out[(string)$r['param_key']] = (string)$r['param_value'];
            }
        } catch (Throwable $e) {
            error_log('[param] all: ' . $e->getMessage());
        }
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        $fallback = $default ?? (self::DEFAULTS[$key][0] ?? null);
        if ($pdo === null) {
            return $fallback;
        }
        try {
            $st = $pdo->prepare('SELECT param_value FROM consultant_param WHERE param_key = :k');
            $st->execute([':k' => $key]);
            $v = $st->fetchColumn();
            return $v !== false ? (string)$v : $fallback;
        } catch (Throwable $e) {
            error_log('[param] get: ' . $e->getMessage());
            return $fallback;
        }
    }

    public function set(string $key, string $value, ?string $label = null): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO consultant_param (param_key, param_value, label, updated_at) '
                . 'VALUES (:k, :v, :l, :now) '
                . 'ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_at = VALUES(updated_at)'
            );
            return $st->execute([
                ':k' => $key, ':v' => $value,
                ':l' => $label ?? (self::DEFAULTS[$key][1] ?? null),
                ':now' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[param] set: ' . $e->getMessage());
            return false;
        }
    }
}
