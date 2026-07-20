<?php
namespace App\Consultant\app\Repositories\Shop;

use App\Consultant\core\Db\Database;
use Throwable;

/**
 * Chiffres de vente par magasin, lus directement dans atelierby_db.transaction
 * (1 ligne = 1 ticket de caisse).
 */
class ShopSalesRepository
{
    /**
     * CA et nombre de tickets d'UN magasin sur une fenêtre [from, to).
     * Borne semi-ouverte sur insert_timestamp → exploite l'index (id_shop, insert_timestamp).
     *
     * @return array{tickets:int, ca:float}
     */
    public function getShopSummary(int $shopId, string $from, string $to): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return ['tickets' => 0, 'ca' => 0.0];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS tickets,
                        COALESCE(SUM(total_gross_amount_after_discount), 0) AS ca
                 FROM transaction
                 WHERE id_shop = :id
                   AND insert_timestamp >= :from
                   AND insert_timestamp <  :to'
            );
            $stmt->execute([':id' => $shopId, ':from' => $from, ':to' => $to]);
            $row = $stmt->fetch() ?: [];

            return [
                'tickets' => (int)($row['tickets'] ?? 0),
                'ca'      => (float)($row['ca'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('[db] getShopSummary échoué: ' . $e->getMessage());
            return ['tickets' => 0, 'ca' => 0.0];
        }
    }

    /**
     * Diagnostic : compare le comptage des tickets d'un magasin sur une fenêtre
     * de dates [fromDate, toDate] (inclusives) selon deux clés de datation —
     * insert_timestamp (potentiellement bruité) vs ticket_key (YYMMDDNNNN, la
     * date métier encodée dans les 6 premiers chiffres). Sert à identifier la
     * source correcte pour « tickets/jour ».
     *
     * @return array{tickets_ts:int, ca_ts:float, tickets_key:int, min_ts:?string, max_ts:?string, total_rows:int}
     */
    public function getWindowDebug(int $shopId, string $fromDate, string $toDate): array
    {
        $empty = ['tickets_ts' => 0, 'ca_ts' => 0.0, 'tickets_key' => 0, 'min_ts' => null, 'max_ts' => null, 'total_rows' => 0];
        $pdo = Database::pdo();
        if ($pdo === null) {
            return $empty;
        }

        // Bornes insert_timestamp : [from 00:00:00, (to+1j) 00:00:00).
        try {
            $fromDt = $fromDate . ' 00:00:00';
            $toExcl = (new \DateTimeImmutable($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');
            // Bornes ticket_key : YYMMDD * 10000 (+9999 pour inclure toute la journée).
            $fromKey = (int)substr(str_replace('-', '', $fromDate), 2) * 10000;
            $toKey   = (int)substr(str_replace('-', '', $toDate), 2) * 10000 + 9999;

            $stmt = $pdo->prepare(
                'SELECT
                    SUM(insert_timestamp >= :from AND insert_timestamp < :toExcl)                       AS tickets_ts,
                    COALESCE(SUM(CASE WHEN insert_timestamp >= :from2 AND insert_timestamp < :toExcl2
                                      THEN total_gross_amount_after_discount ELSE 0 END), 0)             AS ca_ts,
                    SUM(ticket_key BETWEEN :fromKey AND :toKey)                                          AS tickets_key,
                    MIN(insert_timestamp)                                                                AS min_ts,
                    MAX(insert_timestamp)                                                                AS max_ts,
                    COUNT(*)                                                                             AS total_rows
                 FROM transaction
                 WHERE id_shop = :id'
            );
            $stmt->execute([
                ':id' => $shopId,
                ':from' => $fromDt, ':toExcl' => $toExcl,
                ':from2' => $fromDt, ':toExcl2' => $toExcl,
                ':fromKey' => $fromKey, ':toKey' => $toKey,
            ]);
            $row = $stmt->fetch() ?: [];

            return [
                'tickets_ts'  => (int)($row['tickets_ts'] ?? 0),
                'ca_ts'       => (float)($row['ca_ts'] ?? 0),
                'tickets_key' => (int)($row['tickets_key'] ?? 0),
                'min_ts'      => $row['min_ts'] ?? null,
                'max_ts'      => $row['max_ts'] ?? null,
                'total_rows'  => (int)($row['total_rows'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('[db] getWindowDebug échoué: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * CA et nombre de tickets par magasin sur une fenêtre [from, to).
     *
     * @return array<int, array{tickets:int, ca:float}>  indexé par id_shop
     */
    public function getSummaries(string $from, string $to): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id_shop,
                        COUNT(*)                                  AS tickets,
                        COALESCE(SUM(total_gross_amount_after_discount), 0) AS ca
                 FROM transaction
                 WHERE insert_timestamp >= :from
                   AND insert_timestamp <  :to
                 GROUP BY id_shop'
            );
            $stmt->execute([':from' => $from, ':to' => $to]);

            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(int)$row['id_shop']] = [
                    'tickets' => (int)$row['tickets'],
                    'ca'      => (float)$row['ca'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] getSummaries échoué: ' . $e->getMessage());
            return [];
        }
    }
}
