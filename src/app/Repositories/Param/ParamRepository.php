<?php
namespace App\Consultant\app\Repositories\Param;

use App\Consultant\core\Db\Database;
use App\Consultant\core\Db\LegacyTableMigration;
use PDO;
use Throwable;

/**
 * Paramètres configurables (table mac_consultant_param) — clé/valeur.
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
    use LegacyTableMigration;

    /** Paramètres connus : clé => [valeur initiale, libellé]. Sert au seed. */
    public const DEFAULTS = [
        'valuation_multiple'              => ['4.5', 'Multiple de valorisation (× résultat net)'],
        'valuation_target_net_margin_pct' => ['15',  'Marge nette cible (%) — valorisation à l\'objectif'],
        // Créneaux de la heatmap de rentabilité : bornes INCLUSES, exprimées en
        // tranches horaires (la borne 10 couvre 10:00 → 10:59). Les heures hors
        // de ces trois plages ne sont comptées dans aucun créneau.
        'daypart_morning_from'            => ['6',  'Heatmap : début du créneau « matin » (heure incluse)'],
        'daypart_morning_to'              => ['10', 'Heatmap : fin du créneau « matin » (heure incluse)'],
        'daypart_midday_from'             => ['11', 'Heatmap : début du créneau « midi » (heure incluse)'],
        'daypart_midday_to'               => ['14', 'Heatmap : fin du créneau « midi » (heure incluse)'],
        'daypart_afternoon_from'          => ['15', 'Heatmap : début du créneau « après-midi » (heure incluse)'],
        'daypart_afternoon_to'            => ['19', 'Heatmap : fin du créneau « après-midi » (heure incluse)'],
        'trends_budget_seconds'           => ['30', 'Tendances : budget de temps (s) — au-delà, le CA est rendu sans les objectifs'],
    ];

    /**
     * Clés retirées : l'application ne les lit plus (les créneaux ont des
     * bornes début/fin explicites depuis daypart_*_from/_to). Les lignes
     * restent en base — on les masque simplement pour que l'écran de
     * configuration ne propose pas de réglages sans effet. Le DBA peut les
     * supprimer avec le DELETE commenté dans database/mac_consultant_param.sql.
     */
    public const RETIRED = ['daypart_morning_until', 'daypart_midday_until'];

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
                'CREATE TABLE IF NOT EXISTS mac_consultant_param ('
                . 'param_key VARCHAR(64) NOT NULL,'
                . 'param_value VARCHAR(255) NOT NULL,'
                . 'label VARCHAR(190) NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (param_key)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Migration douce AVANT les seeds (conserve les valeurs perso).
            $this->migrateLegacyTable($pdo, 'consultant_param', 'mac_consultant_param');
            $st = $pdo->prepare('INSERT IGNORE INTO mac_consultant_param (param_key, param_value, label) VALUES (:k, :v, :l)');
            foreach (self::DEFAULTS as $key => [$val, $label]) {
                $st->execute([':k' => $key, ':v' => $val, ':l' => $label]);
            }
        } catch (Throwable $e) {
            error_log('[param] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Lignes complètes (clé, valeur, libellé) — pour l'endpoint de
     * configuration. Fusionne les défauts avec ce qui est en base.
     *
     * @return array<int, array{key: string, value: string, label: ?string}>
     */
    public function rows(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => [$v, $label]) {
            $out[$k] = ['key' => $k, 'value' => $v, 'label' => $label];
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo !== null) {
            try {
                foreach ($pdo->query('SELECT param_key, param_value, label FROM mac_consultant_param') as $r) {
                    if (in_array((string)$r['param_key'], self::RETIRED, true)) {
                        continue;
                    }
                    $out[(string)$r['param_key']] = [
                        'key'   => (string)$r['param_key'],
                        'value' => (string)$r['param_value'],
                        'label' => $r['label'] !== null ? (string)$r['label'] : (self::DEFAULTS[(string)$r['param_key']][1] ?? null),
                    ];
                }
            } catch (Throwable $e) {
                error_log('[param] rows: ' . $e->getMessage());
            }
        }
        return array_values($out);
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
            $rows = $pdo->query('SELECT param_key, param_value FROM mac_consultant_param')->fetchAll();
            foreach ($rows as $r) {
                if (in_array((string)$r['param_key'], self::RETIRED, true)) {
                    continue;
                }
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
            $st = $pdo->prepare('SELECT param_value FROM mac_consultant_param WHERE param_key = :k');
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
                'INSERT INTO mac_consultant_param (param_key, param_value, label, updated_at) '
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
