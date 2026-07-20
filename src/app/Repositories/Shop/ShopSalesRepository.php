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
