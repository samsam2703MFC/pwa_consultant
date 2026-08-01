<?php
namespace App\Consultant\app\Repositories\Report;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Liens de partage d'un rapport mensuel (mac_report_share).
 *
 * Le rapport est FIGÉ au moment du partage, pas recalculé à l'ouverture : le
 * jeton d'API vit dans le cookie du consultant, et une page publique n'en a
 * pas. Ce n'est pas un pis-aller — c'est aussi la bonne sémantique. Le
 * destinataire voit exactement ce qui lui a été envoyé, et le rapport d'un mois
 * clos ne doit plus bouger.
 *
 * Ce que la table doit permettre de répondre, le jour où on le demandera : qui
 * a partagé quoi, quand, jusqu'à quand, et qui l'a ouvert.
 */
class ReportShareRepository
{
    private bool $ready = false;

    /** Motif du dernier échec d'écriture — un partage muet doit s'expliquer. */
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
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_report_share ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'token VARCHAR(64) NOT NULL,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'ym CHAR(7) NOT NULL,'
                . 'label VARCHAR(190) NOT NULL,'
                // Le rendu figé, comprimé : une centaine de kilo-octets de HTML
                // en fait une trentaine une fois gzippée.
                . 'html MEDIUMBLOB NULL,'
                . 'id_consultant BIGINT UNSIGNED NOT NULL,'
                . 'consultant_name VARCHAR(190) NULL,'
                . 'created_at DATETIME NOT NULL,'
                . 'expires_at DATETIME NOT NULL,'
                . 'revoked_at DATETIME NULL,'
                . 'opens INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'last_opened_at DATETIME NULL,'
                . 'last_ip VARCHAR(45) NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_token (token),'
                . 'KEY idx_consultant (id_consultant, created_at),'
                . 'KEY idx_expiry (expires_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[report_share] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Crée un lien et renvoie son jeton, ou null si la base n'a pas voulu.
     *
     * @param array{id_shop:int, ym:string, label:string, html:string,
     *              id_consultant:int, consultant_name:?string, days:int} $r
     */
    public function create(array $r): ?string
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            $this->lastError = 'Base de données indisponible.';
            return null;
        }
        // 32 octets d'aléa : un jeton devinable serait une porte ouverte sur le
        // P&L d'une boutique. `random_bytes` échoue plutôt que de rendre du
        // pseudo-aléa — c'est ce qu'on veut ici.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $jours = max(1, (int)$r['days']);
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_report_share
                 (token, id_shop, ym, label, html, id_consultant, consultant_name,
                  created_at, expires_at)
                 VALUES (:t, :s, :ym, :l, :h, :c, :cn, NOW(), DATE_ADD(NOW(), INTERVAL :d DAY))'
            );
            $st->bindValue(':t', $token);
            $st->bindValue(':s', (int)$r['id_shop'], PDO::PARAM_INT);
            $st->bindValue(':ym', $r['ym']);
            $st->bindValue(':l', mb_substr((string)$r['label'], 0, 190));
            $st->bindValue(':h', gzencode((string)$r['html'], 6), PDO::PARAM_LOB);
            $st->bindValue(':c', (int)$r['id_consultant'], PDO::PARAM_INT);
            $st->bindValue(':cn', $r['consultant_name'] ?: null);
            $st->bindValue(':d', $jours, PDO::PARAM_INT);
            $st->execute();
            return $token;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[report_share] create: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Le lien, s'il est VALIDE — ni expiré ni révoqué. Un lien mort et un lien
     * inexistant donnent la même réponse : rien. Distinguer les deux dirait à
     * qui essaie des jetons au hasard lesquels ont existé.
     */
    public function findValid(string $token): ?array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null || $token === '') {
            return null;
        }
        try {
            $st = $pdo->prepare(
                'SELECT * FROM mac_report_share
                 WHERE token = :t AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $st->execute([':t' => $token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $row['html'] = $row['html'] !== null ? (string)gzdecode((string)$row['html']) : '';
            return $row;
        } catch (Throwable $e) {
            error_log('[report_share] findValid: ' . $e->getMessage());
            return null;
        }
    }

    /** Une ouverture de plus. Le jour où on demande qui a vu quoi, il faut répondre. */
    public function touch(string $token, ?string $ip): void
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        try {
            $st = $pdo->prepare(
                'UPDATE mac_report_share
                 SET opens = opens + 1, last_opened_at = NOW(), last_ip = :ip
                 WHERE token = :t'
            );
            $st->execute([':t' => $token, ':ip' => $ip ? mb_substr($ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('[report_share] touch: ' . $e->getMessage());
        }
    }

    /** Révocation immédiate — par son auteur seulement. */
    public function revoke(string $token, int $consultantId): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'UPDATE mac_report_share SET revoked_at = NOW()
                 WHERE token = :t AND id_consultant = :c AND revoked_at IS NULL'
            );
            $st->execute([':t' => $token, ':c' => $consultantId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[report_share] revoke: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Les liens d'un consultant, le plus récent d'abord — sans le HTML, qui
     * pèse et dont la liste n'a que faire.
     *
     * @return array<int, array>
     */
    public function forConsultant(int $consultantId, int $limit = 30): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT id, token, id_shop, ym, label, created_at, expires_at, revoked_at,
                        opens, last_opened_at
                 FROM mac_report_share WHERE id_consultant = :c
                 ORDER BY created_at DESC LIMIT ' . max(1, (int)$limit)
            );
            $st->execute([':c' => $consultantId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[report_share] forConsultant: ' . $e->getMessage());
            return [];
        }
    }
}
