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
    /** Colonnes détectées de transaction_product (cache par requête). */
    private ?array $tpCols = null;

    /**
     * Indicateurs de vente d'UN magasin sur une fenêtre de dates métier
     * [fromDate, toDate] (inclusives, format Y-m-d).
     *
     * Sources :
     *   - `transaction` (1 ligne = 1 ticket) :
     *       tickets = COUNT(DISTINCT id_device, ticket_key)
     *       CA      = SUM(total_gross_amount_after_discount)
     *   - `transaction_product` (1 ligne = 1 ligne de ticket) :
     *       produits = SUM(quantité) si une colonne quantité existe,
     *                  sinon COUNT(lignes). 0 si la table est absente.
     *
     * Fenêtre bornée sur insert_timestamp — seule datation cohérente sur tous
     * les magasins (les ticket_key ont des encodages différents par magasin).
     *
     * NB : cette base locale peut être PARTIELLE (CA_DB < CA_API). L'appelant
     * redresse alors les comptages au prorata du CA de l'API (cf. ShopController).
     *
     * @return array{tickets:int, products:int, ca:float}
     */
    public function getShopSummary(int $shopId, string $fromDate, string $toDate): array
    {
        $empty = ['tickets' => 0, 'products' => 0, 'ca' => 0.0];
        $pdo = Database::pdo();
        if ($pdo === null) {
            return $empty;
        }

        try {
            // Fenêtre semi-ouverte [from 00:00:00, (to+1j) 00:00:00).
            $from   = $fromDate . ' 00:00:00';
            $toExcl = (new \DateTimeImmutable($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');

            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT id_device, ticket_key)                 AS tickets,
                        COALESCE(SUM(total_gross_amount_after_discount), 0)   AS ca
                 FROM transaction
                 WHERE id_shop = :id
                   AND insert_timestamp >= :from
                   AND insert_timestamp <  :toExcl'
            );
            $stmt->execute([':id' => $shopId, ':from' => $from, ':toExcl' => $toExcl]);
            $row = $stmt->fetch() ?: [];

            return [
                'tickets'  => (int)($row['tickets'] ?? 0),
                'products' => $this->countProducts($pdo, $shopId, $from, $toExcl),
                'ca'       => (float)($row['ca'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('[db] getShopSummary échoué: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Nombre de produits vendus sur la fenêtre, depuis transaction_product
     * (lignes de ticket). Colonnes détectées dynamiquement : la FK vers
     * `transaction` (nom contenant « transaction ») et une éventuelle colonne
     * quantité (quantity/qty/amount) → SUM(quantité), sinon COUNT(lignes).
     */
    private function countProducts(\PDO $pdo, int $shopId, string $from, string $toExcl): int
    {
        $cols = $this->transactionProductCols($pdo);
        if ($cols === null) {
            return 0; // table absente → l'appelant affichera « — »
        }
        [$fk, $qty] = $cols;

        $expr = $qty !== null
            ? 'COALESCE(SUM(tp.`' . $qty . '`), 0)'
            : 'COUNT(*)';

        try {
            $stmt = $pdo->prepare(
                'SELECT ' . $expr . ' AS products
                 FROM transaction_product tp
                 INNER JOIN transaction t ON t.id = tp.`' . $fk . '`
                 WHERE t.id_shop = :id
                   AND t.insert_timestamp >= :from
                   AND t.insert_timestamp <  :toExcl'
            );
            $stmt->execute([':id' => $shopId, ':from' => $from, ':toExcl' => $toExcl]);

            return (int)round((float)($stmt->fetchColumn() ?: 0));
        } catch (Throwable $e) {
            error_log('[db] countProducts échoué: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Détecte [fk, quantité|null] dans transaction_product, null si table/FK
     * introuvable. La FK est la colonne id* contenant « transaction ».
     */
    private function transactionProductCols(\PDO $pdo): ?array
    {
        if ($this->tpCols !== null) {
            return $this->tpCols === [] ? null : $this->tpCols;
        }

        try {
            $stmt = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_product'"
            );
            $names = array_map(fn($r) => (string)$r['COLUMN_NAME'], $stmt->fetchAll());
        } catch (Throwable $e) {
            error_log('[db] transactionProductCols échoué: ' . $e->getMessage());
            $names = [];
        }

        $fk = null;
        foreach ($names as $n) {
            if (preg_match('/^(id_transaction|transaction_id)$/i', $n)) { $fk = $n; break; }
        }
        if ($fk === null) {
            foreach ($names as $n) {
                if (stripos($n, 'transaction') !== false && stripos($n, 'id') !== false) { $fk = $n; break; }
            }
        }
        if ($fk === null) {
            $this->tpCols = [];
            return null;
        }

        $qty = null;
        foreach (['quantity', 'qty', 'amount', 'count'] as $cand) {
            foreach ($names as $n) {
                if (strcasecmp($n, $cand) === 0) { $qty = $n; break 2; }
            }
        }

        $this->tpCols = [$fk, $qty];
        return $this->tpCols;
    }

    /**
     * CA d'UN magasin sur trois paires de fenêtres N vs N-1 :
     * année à date, mois à date, semaine (lundi → aujourd'hui, N-1 = -364 j
     * pour rester aligné sur les jours de semaine). Une seule requête
     * (agrégation conditionnelle sur insert_timestamp).
     *
     * @param array<string, array{0:string,1:string}> $windows  clé => [from, toExcl] (datetime)
     * @return array<string, float>  clé => CA
     */
    public function getWindowSums(int $shopId, array $windows): array
    {
        $empty = array_fill_keys(array_keys($windows), 0.0);
        $pdo = Database::pdo();
        if ($pdo === null || $windows === []) {
            return $empty;
        }

        try {
            $selects = [];
            $params  = [':id' => $shopId];
            $i = 0;
            foreach ($windows as $key => [$from, $toExcl]) {
                $selects[] = "COALESCE(SUM(CASE WHEN insert_timestamp >= :f$i AND insert_timestamp < :t$i
                                   THEN total_gross_amount_after_discount ELSE 0 END), 0) AS `$key`";
                $params[":f$i"] = $from;
                $params[":t$i"] = $toExcl;
                $i++;
            }
            $stmt = $pdo->prepare('SELECT ' . implode(', ', $selects) . ' FROM transaction WHERE id_shop = :id');
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];

            $out = [];
            foreach ($windows as $key => $_) {
                $out[$key] = (float)($row[$key] ?? 0);
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] getWindowSums échoué: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Drapeaux « actif » des magasins depuis la table locale `shops`.
     * La colonne est détectée (active / is_active / enabled / shop_active…) ;
     * renvoie null si table ou colonne introuvable → l'appelant ne filtre pas.
     *
     * @return array<int,bool>|null  id magasin => actif
     */
    public function getActiveShopIds(): ?array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return null;
        }

        try {
            $stmt = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops'"
            );
            $names = array_map(fn($r) => (string)$r['COLUMN_NAME'], $stmt->fetchAll());

            $col = null;
            foreach (['active', 'is_active', 'enabled', 'is_enabled', 'shop_active', 'status'] as $cand) {
                foreach ($names as $n) {
                    if (strcasecmp($n, $cand) === 0) { $col = $n; break 2; }
                }
            }
            if ($col === null) {
                return null;
            }

            $stmt = $pdo->query("SELECT id, `$col` AS flag FROM shops");
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                // 1 / true / 'active' → actif ; 0 / null / autre → inactif.
                $v = $row['flag'];
                $out[(int)$row['id']] = is_numeric($v) ? ((int)$v === 1) : (strcasecmp((string)$v, 'active') === 0);
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] getActiveShopIds échoué: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Diagnostic : liste les tables de la base avec leur nombre de lignes
     * (approx. information_schema). Sert à repérer une éventuelle table de
     * LIGNES de ticket (détail produits) pour un vrai « produits / client ».
     *
     * @return array<string,int>  table => lignes
     */
    public function listTables(): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->query(
                'SELECT TABLE_NAME AS t, TABLE_ROWS AS r
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 ORDER BY TABLE_NAME'
            );
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string)$row['t']] = (int)$row['r'];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] listTables échoué: ' . $e->getMessage());
            return [];
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
