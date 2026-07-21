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
     * LES 3 KPI de vente d'UN magasin — tickets vendus, panier moyen,
     * produits/ticket — en UNE SEULE requête agrégée, sur une fenêtre de
     * dates métier [fromDate, toDate] (inclusives, Y-m-d). C'est LA méthode
     * de référence, utilisée partout (Boutiques, accueil, day-sales) : même
     * périmètre de tickets pour les trois chiffres, par construction.
     *
     * Périmètre : tickets de VENTE RÉELLE uniquement (montant > 0) — les
     * lignes à 0 € (tickets pas encore enrichis, opérations de caisse) et
     * négatives (annulations) sont exclues, sinon panier écrasé et
     * tickets surévalués. Fenêtre bornée sur insert_timestamp, semi-ouverte
     * [from 00:00, to+1j 00:00). tickets = COUNT(DISTINCT ticket_key) — le
     * numéro de ticket est unique par ticket émis AU SEIN d'un magasin, et
     * la requête filtre déjà sur id_shop (clé effective : id_shop +
     * ticket_key) ; produits = sous-requête agrégée sur transaction_product
     * (colonnes FK/quantité détectées ; table absente → 0).
     *
     * NB : la base locale peut être PARTIELLE (CA_DB < CA_API). Les RATIOS
     * (panier, produits/ticket) restent justes ; seul le VOLUME de tickets
     * doit être redressé par l'appelant au prorata du CA API si besoin.
     *
     * @return array{tickets:int, ca:float, products:int, avg_basket:?float, products_per_ticket:?float}
     */
    public function getSalesKpis(int $shopId, string $fromDate, string $toDate): array
    {
        $empty = ['tickets' => 0, 'ca' => 0.0, 'products' => 0, 'avg_basket' => null, 'products_per_ticket' => null];
        $pdo = Database::pdo();
        if ($pdo === null) {
            return $empty;
        }

        try {
            $from   = $fromDate . ' 00:00:00';
            $toExcl = (new \DateTimeImmutable($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');

            $params = [':id' => $shopId, ':from' => $from, ':toExcl' => $toExcl];

            // Produits : sous-requête scalaire sur transaction_product, MÊME
            // périmètre de tickets (fenêtre + montant > 0) que le comptage.
            $productsExpr = '0';
            $cols = $this->transactionProductCols($pdo);
            if ($cols !== null) {
                [$fk, $qty] = $cols;
                $inner = $qty !== null ? 'COALESCE(SUM(tp.`' . $qty . '`), 0)' : 'COUNT(*)';
                $productsExpr =
                    '(SELECT ' . $inner . '
                      FROM transaction_product tp
                      INNER JOIN transaction t2 ON t2.id = tp.`' . $fk . '`
                      WHERE t2.id_shop = :id2
                        AND t2.insert_timestamp >= :from2
                        AND t2.insert_timestamp <  :toExcl2
                        AND t2.total_gross_amount_after_discount > 0)';
                $params += [':id2' => $shopId, ':from2' => $from, ':toExcl2' => $toExcl];
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT t.ticket_key)                              AS tickets,
                        COALESCE(SUM(t.total_gross_amount_after_discount), 0)     AS ca,
                        ' . $productsExpr . '                                     AS products
                 FROM transaction t
                 WHERE t.id_shop = :id
                   AND t.insert_timestamp >= :from
                   AND t.insert_timestamp <  :toExcl
                   AND t.total_gross_amount_after_discount > 0'
            );
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];

            $tickets  = (int)($row['tickets'] ?? 0);
            $ca       = (float)($row['ca'] ?? 0);
            $products = (int)round((float)($row['products'] ?? 0));

            return [
                'tickets'             => $tickets,
                'ca'                  => $ca,
                'products'            => $products,
                'avg_basket'          => $tickets > 0 ? $ca / $tickets : null,
                'products_per_ticket' => ($tickets > 0 && $products > 0) ? $products / $tickets : null,
            ];
        } catch (Throwable $e) {
            error_log('[db] getSalesKpis échoué: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Fenêtre de dates métier [from, to] (inclusives, Y-m-d) pour une période
     * standard : day = aujourd'hui · week = lundi → aujourd'hui ·
     * month = 1er du mois → aujourd'hui.
     *
     * @return array{0:string, 1:string}
     */
    public static function periodWindow(string $period): array
    {
        $today = new \DateTimeImmutable('today');
        return match ($period) {
            'week'  => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => [$today->format('Y-m-01'), $today->format('Y-m-d')],
            default => [$today->format('Y-m-d'), $today->format('Y-m-d')],
        };
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
