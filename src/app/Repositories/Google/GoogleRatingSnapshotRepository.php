<?php
namespace App\Consultant\app\Repositories\Google;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Relevé mensuel de la note Google d'un magasin (mac_google_rating_snapshot).
 *
 * POURQUOI CETTE TABLE EXISTE. La note Google se lit en direct chez Google et
 * ne vit ensuite que dans un cache de douze heures : rien ne la garde. Google
 * ne rend que le présent — on ne peut donc PAS reconstruire un historique après
 * coup, contrairement au P&L ou aux ventes, qui dorment en base et se
 * rattrapent quand on en a besoin.
 *
 * Chaque mois sans relevé est donc un mois de comparaison perdu pour toujours.
 * C'est la seule raison d'écrire ici : rendre possible, dans un an, le « même
 * mois l'an dernier » du levier Expérience Client.
 *
 * Une ligne par magasin et par mois, mise à jour à chaque lecture : à la fin du
 * mois, la ligne porte donc la dernière note connue de ce mois.
 *
 * Écriture silencieuse et sans effet de bord : si la base est absente ou le
 * privilège manquant, la note s'affiche quand même — on perd le relevé, pas
 * l'écran.
 */
class GoogleRatingSnapshotRepository
{
    private bool $ready = false;

    /** Motif du dernier échec — sans lui, un relevé muet ne s'explique pas. */
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
            $this->lastError = 'base locale indisponible';
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_google_rating_snapshot ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                // Le mois du relevé, pas la date : c'est la maille de comparaison.
                . 'snap_month CHAR(7) NOT NULL,'
                . 'rating DECIMAL(3,2) NOT NULL,'
                . 'reviews INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'captured_at DATETIME NOT NULL,'
                . 'PRIMARY KEY (id),'
                // Un seul relevé par magasin et par mois : une seconde lecture
                // met à jour, elle n'empile pas.
                . 'UNIQUE KEY uq_shop_month (id_shop, snap_month),'
                . 'KEY idx_month (snap_month)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[google-snapshot] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Consigne la note du mois en cours.
     *
     * Rend true si la ligne a été écrite. Une note nulle ou hors échelle n'est
     * pas consignée : mieux vaut un trou dans la série qu'une valeur fausse
     * qu'on comparera l'an prochain sans savoir qu'elle l'était.
     */
    public function record(int $shopId, ?float $rating, int $reviews, ?string $month = null): bool
    {
        if ($shopId <= 0 || $rating === null || $rating <= 0 || $rating > 5) {
            return false;
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_google_rating_snapshot (id_shop, snap_month, rating, reviews, captured_at)'
                . ' VALUES (:shop, :mois, :note, :avis, :quand)'
                . ' ON DUPLICATE KEY UPDATE rating = VALUES(rating), reviews = VALUES(reviews),'
                . ' captured_at = VALUES(captured_at)'
            );
            $st->execute([
                ':shop'  => $shopId,
                ':mois'  => $month ?? date('Y-m'),
                ':note'  => round($rating, 2),
                ':avis'  => max(0, $reviews),
                ':quand' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[google-snapshot] record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * La série d'un magasin, du plus ancien au plus récent.
     *
     * @return array<int, array{mois:string, note:float, avis:int}>
     */
    public function series(int $shopId, int $depuisMois = 24): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null || $shopId <= 0) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT snap_month, rating, reviews FROM mac_google_rating_snapshot'
                . ' WHERE id_shop = ? ORDER BY snap_month DESC LIMIT ?'
            );
            $st->bindValue(1, $shopId, PDO::PARAM_INT);
            $st->bindValue(2, max(1, min($depuisMois, 120)), PDO::PARAM_INT);
            $st->execute();
            $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            return array_map(static fn ($r) => [
                'mois' => (string)$r['snap_month'],
                'note' => (float)$r['rating'],
                'avis' => (int)$r['reviews'],
            ], $rows);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /**
     * La note d'un magasin pour un mois donné — c'est ce que le « même mois
     * l'an dernier » de l'écran des leviers ira chercher.
     *
     * @return array{note:float, avis:int}|null
     */
    public function forMonth(int $shopId, string $month): ?array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null || $shopId <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare('SELECT rating, reviews FROM mac_google_rating_snapshot'
                . ' WHERE id_shop = ? AND snap_month = ?');
            $st->execute([$shopId, $month]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r === false ? null : ['note' => (float)$r['rating'], 'avis' => (int)$r['reviews']];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /** Combien de mois sont déjà consignés, et depuis quand. */
    public function couverture(): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return ['mois' => 0, 'depuis' => null, 'magasins' => 0, 'lignes' => 0];
        }
        try {
            $r = $pdo->query('SELECT COUNT(*) AS lignes, COUNT(DISTINCT snap_month) AS mois,'
                . ' COUNT(DISTINCT id_shop) AS magasins, MIN(snap_month) AS depuis'
                . ' FROM mac_google_rating_snapshot')->fetch(PDO::FETCH_ASSOC);
            return [
                'lignes'   => (int)($r['lignes'] ?? 0),
                'mois'     => (int)($r['mois'] ?? 0),
                'magasins' => (int)($r['magasins'] ?? 0),
                'depuis'   => $r['depuis'] ?? null,
            ];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['mois' => 0, 'depuis' => null, 'magasins' => 0, 'lignes' => 0];
        }
    }
}
