<?php
namespace App\Consultant\app\Repositories\Valuation;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Snapshots mensuels du P&L par boutique (table shop_monthly_pnl). Alimente la
 * valorisation : moyenne de marge nette 12 mois + série d'évolution. Dégradation
 * propre si la base ou la table est indisponible.
 */
class PnlSnapshotRepository
{
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
                'CREATE TABLE IF NOT EXISTS shop_monthly_pnl ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'id_shop BIGINT UNSIGNED NOT NULL,'
                . 'year SMALLINT UNSIGNED NOT NULL,'
                . 'month TINYINT UNSIGNED NOT NULL,'
                . 'ca DECIMAL(14,2) NULL,'
                . 'net_margin_pct DECIMAL(7,3) NULL,'
                . 'net_result DECIMAL(14,2) NULL,'
                . 'captured_at DATETIME NOT NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY uq_shop_month (id_shop, year, month)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            error_log('[valuation] ensureSchema: ' . $e->getMessage());
        }
    }

    /** Insère ou met à jour le snapshot d'un mois pour une boutique. */
    public function upsertMonth(int $shopId, int $year, int $month, ?float $ca, ?float $marginPct, ?float $result): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO shop_monthly_pnl (id_shop, year, month, ca, net_margin_pct, net_result, captured_at) '
                . 'VALUES (:s, :y, :m, :ca, :mg, :res, :now) '
                . 'ON DUPLICATE KEY UPDATE ca = VALUES(ca), net_margin_pct = VALUES(net_margin_pct), '
                . 'net_result = VALUES(net_result), updated_at = VALUES(captured_at)'
            );
            return $st->execute([
                ':s' => $shopId, ':y' => $year, ':m' => $month,
                ':ca' => $ca, ':mg' => $marginPct, ':res' => $result,
                ':now' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[valuation] upsertMonth: ' . $e->getMessage());
            return false;
        }
    }

    /** Snapshots d'une boutique depuis (year,month) inclus, ordre chronologique. */
    public function forShopSince(int $shopId, int $year, int $month): array
    {
        return $this->select(
            'SELECT * FROM shop_monthly_pnl WHERE id_shop = :s AND (year > :y OR (year = :y AND month >= :m)) '
            . 'ORDER BY year ASC, month ASC',
            [':s' => $shopId, ':y' => $year, ':m' => $month]
        );
    }

    /** Tous les snapshots depuis (year,month) pour un ensemble de boutiques. */
    public function forShopsSince(array $shopIds, int $year, int $month): array
    {
        $ids = array_values(array_filter(array_map('intval', $shopIds), fn($i) => $i > 0));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        return $this->select(
            "SELECT * FROM shop_monthly_pnl WHERE id_shop IN ($in) AND (year > :y OR (year = :y AND month >= :m)) "
            . 'ORDER BY year ASC, month ASC',
            [':y' => $year, ':m' => $month]
        );
    }

    private function select(string $sql, array $params): array
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        } catch (Throwable $e) {
            error_log('[valuation] select: ' . $e->getMessage());
            return [];
        }
    }
}
