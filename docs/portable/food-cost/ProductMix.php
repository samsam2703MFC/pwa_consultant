<?php
declare(strict_types=1);

/**
 * Mix produits — tableau « produits × boutiques » portable (PHP 8.1+, sans
 * dépendance). Compagnon de FoodCost.php : MÊME endpoint, même discipline
 * d'extraction (une seule clé de coût par nœud).
 *
 * Ce que ça produit — exactement le tableau décrit dans README-PRODUITS.md :
 *
 *   Produit                  | Halle | Corbais | … | Total réseau | Objectif | Progression
 *   Galette Frangipane       |     0 |   1 586 | … |        2 999 |    5 000 |       60 %
 *   Total période            |     0 |   1 586 | … |        3 583 |    5 000 |       72 %
 *
 * … et le même tableau agrégé à n'importe quel NIVEAU de la hiérarchie
 * produit : secteur → groupe → catégorie → produit.
 *
 * Source : GET /shops/{id}/statistics/sales/product-category-groups
 *          ?date_from&date_to&grouping=category|group|month
 * La forme exacte du payload varie (imbrication libre, clés changeantes) :
 * le parseur descend l'arbre et retient les nœuds nommés, en gardant le
 * chemin des ancêtres comme hiérarchie.
 */
final class ProductMix
{
    /** Niveaux de la hiérarchie, du plus large au plus fin. */
    public const LEVELS = ['sector', 'group', 'category', 'product'];

    /** Clés qui nomment explicitement un niveau (le positionnel n'est qu'un repli). */
    private const LEVEL_KEYS = [
        'sector'   => ['sector_name', 'sector', 'univers', 'universe', 'category_1', 'category1', 'level_1'],
        'group'    => ['group_name', 'group', 'family_name', 'family', 'category_2', 'category2', 'level_2'],
        'category' => ['category_name', 'category', 'sub_category', 'subcategory', 'category_3', 'category3', 'level_3'],
        'product'  => ['product_name', 'product', 'article_name', 'article', 'item_name', 'item'],
    ];

    private const ID_KEYS = [
        'sector'   => ['sector_id', 'category_1_id'],
        'group'    => ['group_id', 'family_id', 'category_2_id'],
        'category' => ['category_id', 'sub_category_id', 'category_3_id'],
        'product'  => ['product_id', 'article_id', 'item_id'],
    ];

    /** Nom générique d'un nœud, quand aucune clé de niveau ne le désigne. */
    private const NAME_KEYS = ['category_name', 'category', 'group_name', 'name', 'label', 'title'];

    /** Quantités vendues, par ordre de préférence. UNE SEULE par nœud. */
    private const QTY_PREFS = ['quantity', 'qty', 'pieces', 'units', 'sold_quantity', 'sold', 'nb', 'count', 'volume'];

    /** Chiffre d'affaires, par ordre de préférence. UNE SEULE par nœud. */
    private const CA_PREFS = ['turnover', 'revenue', 'sales', 'ca', 'net_sales', 'amount', 'total_amount', 'value', 'total'];

    /** Coût matière — identique à FoodCost, pour que les deux écrans concordent. */
    private const COST_PREFS = ['material_cost', 'materials_cost', 'food_cost', 'goods_cost', 'cost_of_goods', 'purchase_cost'];

    /** Clés à ignorer partout : ratios, pourcentages, moyennes. */
    private const SKIP_RE = '/(pct|percent|ratio|rate|delta|margin|avg|average|prev|n1|last_year)/i';

    /** @var callable(string, array): (array|null) */
    private $get;

    /**
     * @param callable(string $path, array $query): (array|null) $get
     *        Même contrat que FoodCost : rend le `data` décodé, ou null.
     */
    public function __construct(callable $get)
    {
        $this->get = $get;
    }

    // ───────────────────────────── Lecture API ─────────────────────────────

    /**
     * Lignes à plat d'UNE boutique sur une fenêtre. Essaie `grouping=category`,
     * puis `group`, puis `month` — la première réponse exploitable gagne.
     *
     * @return list<array{shop_id:int, sector_id:?string, sector_name:?string,
     *   group_id:?string, group_name:?string, category_id:?string, category_name:?string,
     *   product_id:?string, product_name:?string, is_pdm:?bool,
     *   qty:?float, ca:?float, material_cost:?float, path:list<string>}>
     */
    public function forShop(int $shopId, string $from, string $to): array
    {
        foreach (['category', 'group', 'month'] as $grouping) {
            $d = ($this->get)(
                '/shops/' . $shopId . '/statistics/sales/product-category-groups',
                ['date_from' => $from, 'date_to' => $to, 'grouping' => $grouping]
            );
            if (is_array($d)) {
                $rows = self::flatten($d, $shopId);
                if ($rows !== []) {
                    return $rows;
                }
            }
        }
        return [];
    }

    /**
     * Lignes de PLUSIEURS boutiques, concaténées (chaque ligne porte son
     * `shop_id`). C'est l'entrée de `table()`.
     *
     * @param int[] $shopIds
     * @return list<array>
     */
    public function forShops(array $shopIds, string $from, string $to): array
    {
        $rows = [];
        foreach ($shopIds as $id) {
            foreach ($this->forShop((int)$id, $from, $to) as $r) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /**
     * Liste des secteurs, telle que le back-office la connaît. À ne PAS coder
     * en dur : un secteur renommé côté back-office dériverait en silence.
     *
     * @return list<array{id: mixed, name: string}>
     */
    public function sectors(): array
    {
        $d = ($this->get)('/consultant/product-sectors', []);
        if (!is_array($d)) {
            return [];
        }
        foreach (['sectors', 'items', 'list', 'data'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) { $d = $d[$k]; break; }
        }
        $out = [];
        foreach ($d as $s) {
            if (!is_array($s)) continue;
            $name = null;
            foreach (['name', 'label', 'sector_name', 'title'] as $k) {
                if (isset($s[$k]) && is_string($s[$k]) && trim($s[$k]) !== '') { $name = trim($s[$k]); break; }
            }
            if ($name !== null) {
                $out[] = ['id' => $s['id'] ?? $s['sector_id'] ?? null, 'name' => $name];
            }
        }
        return $out;
    }

    // ───────────────────────────── Mise à plat ─────────────────────────────

    /**
     * Aplatit un payload imbriqué en lignes « une par produit ».
     *
     * La hiérarchie vient du CHEMIN des nœuds nommés traversés : le premier
     * ancêtre nommé est le niveau le plus large, le dernier le plus fin. Quand
     * un nœud porte une clé explicite (`sector_name`, `group_name`,
     * `category_name`), elle prime sur la position — c'est ce qui rend le
     * parseur robuste à une profondeur qui change entre deux `grouping`.
     *
     * Les nœuds intermédiaires (catégories) ne produisent PAS de ligne : leurs
     * totaux se recalculent par `rollup()`. Sommer à la fois une catégorie et
     * ses produits doublerait le tableau.
     *
     * @return list<array>
     */
    public static function flatten($payload, int $shopId = 0): array
    {
        $rows = [];

        $walk = static function ($n, array $path, array $named) use (&$walk, &$rows, $shopId): void {
            if (!is_array($n)) {
                return;
            }
            if (array_is_list($n)) {
                foreach ($n as $x) {
                    $walk($x, $path, $named);
                }
                return;
            }

            // Niveaux explicitement nommés sur ce nœud.
            foreach (self::LEVEL_KEYS as $lvl => $keys) {
                foreach ($keys as $k) {
                    if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                        $named[$lvl] = trim($n[$k]);
                        break;
                    }
                }
                foreach (self::ID_KEYS[$lvl] as $k) {
                    if (isset($n[$k]) && (is_string($n[$k]) || is_int($n[$k]))) {
                        $named[$lvl . '_id'] = (string)$n[$k];
                    }
                }
            }

            $label = self::nodeName($n);
            $isProduct = self::isProductNode($n, $named);

            if ($isProduct) {
                $qty  = self::pick($n, self::QTY_PREFS);
                $ca   = self::pick($n, self::CA_PREFS);
                $cost = self::pick($n, self::COST_PREFS, '/cost/i');
                $name = $named['product'] ?? $label;

                if ($name !== null || $qty !== null || $ca !== null) {
                    $rows[] = [
                        'shop_id'       => $shopId,
                        'sector_id'     => $named['sector_id']   ?? null,
                        'sector_name'   => $named['sector']      ?? null,
                        'group_id'      => $named['group_id']    ?? null,
                        'group_name'    => $named['group']       ?? ($path[0] ?? null),
                        'category_id'   => $named['category_id'] ?? null,
                        'category_name' => $named['category']    ?? (count($path) > 1 ? $path[count($path) - 1] : ($path[0] ?? null)),
                        'product_id'    => $named['product_id']  ?? null,
                        'product_name'  => $name,
                        'is_pdm'        => self::boolOrNull($n['is_pdm'] ?? null),
                        'qty'           => $qty,
                        'ca'            => $ca,
                        'material_cost' => $cost,
                        'path'          => $path,
                    ];
                }
                // Un produit n'a pas d'enfant utile : on ne descend pas plus.
                return;
            }

            if ($label !== null) {
                $path[] = $label;
            }
            foreach ($n as $v) {
                if (is_array($v)) {
                    $walk($v, $path, $named);
                }
            }
        };

        $walk($payload, [], []);
        return $rows;
    }

    // ───────────────────────────── Agrégations ─────────────────────────────

    /**
     * Regroupe des lignes à un NIVEAU de la hiérarchie.
     *
     * @param list<array> $rows lignes de `flatten()` / `forShops()`
     * @param string $level 'sector' | 'group' | 'category' | 'product'
     * @return list<array{key:string, label:string, level:string, path:array,
     *   by_shop:array<int, array{qty:float, ca:float, material_cost:?float}>,
     *   qty:float, ca:float, material_cost:?float, food_pct:?float}>
     */
    public static function rollup(array $rows, string $level = 'product'): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('niveau inconnu : ' . $level);
        }

        $acc = [];
        foreach ($rows as $r) {
            $label = $r[$level . '_name'] ?? null;
            if ($label === null || $label === '') {
                $label = '(' . $level . ' non renseigné)';   // jamais silencieusement ignoré
            }
            $key = $r[$level . '_id'] ?? null;
            $key = ($key !== null && $key !== '') ? $level . ':' . $key : $level . '#' . mb_strtolower($label);

            if (!isset($acc[$key])) {
                $acc[$key] = [
                    'key' => $key, 'label' => $label, 'level' => $level,
                    'path' => self::pathOf($r, $level),
                    'by_shop' => [], 'qty' => 0.0, 'ca' => 0.0,
                    'material_cost' => null, '_has_cost' => false,
                ];
            }
            $a   = &$acc[$key];
            $sid = (int)($r['shop_id'] ?? 0);
            if (!isset($a['by_shop'][$sid])) {
                $a['by_shop'][$sid] = ['qty' => 0.0, 'ca' => 0.0, 'material_cost' => null];
            }
            $a['by_shop'][$sid]['qty'] += (float)($r['qty'] ?? 0);
            $a['by_shop'][$sid]['ca']  += (float)($r['ca'] ?? 0);
            $a['qty'] += (float)($r['qty'] ?? 0);
            $a['ca']  += (float)($r['ca'] ?? 0);
            if (($r['material_cost'] ?? null) !== null) {
                $a['material_cost'] = (float)$a['material_cost'] + abs((float)$r['material_cost']);
                $a['by_shop'][$sid]['material_cost'] = (float)($a['by_shop'][$sid]['material_cost'] ?? 0) + abs((float)$r['material_cost']);
                $a['_has_cost'] = true;
            }
            unset($a);
        }

        $out = [];
        foreach ($acc as $a) {
            $a['material_cost'] = $a['_has_cost'] ? $a['material_cost'] : null;
            $a['food_pct'] = ($a['material_cost'] !== null && $a['ca'] > 0)
                ? $a['material_cost'] / $a['ca'] * 100 : null;
            unset($a['_has_cost']);
            $out[] = $a;
        }
        usort($out, fn($x, $y) => $y['qty'] <=> $x['qty'] ?: strcmp($x['label'], $y['label']));
        return $out;
    }

    /**
     * Arbre secteur → groupe → catégorie → produit, pour un tableau dépliable.
     * Chaque nœud porte ses totaux ET ceux par boutique.
     *
     * @param list<array> $rows
     * @return list<array{key:string, label:string, level:string, qty:float, ca:float,
     *   by_shop:array, children:list<array>}>
     */
    public static function tree(array $rows, array $levels = self::LEVELS): array
    {
        $levels = array_values(array_filter($levels, fn($l) => in_array($l, self::LEVELS, true)));
        if ($levels === []) {
            return [];
        }
        return self::treeAt($rows, $levels, 0);
    }

    /**
     * Le TABLEAU complet : lignes = niveau choisi, colonnes = boutiques,
     * plus « Total réseau », « Total période », « Objectif » et « Progression ».
     *
     * @param list<array> $rows lignes de `forShops()`
     * @param array{
     *   level?: string,
     *   shops?: array<int, string>,        map shopId => nom (ordre des colonnes)
     *   tickets?: array<int, int>,         tickets de LA MÊME fenêtre, par boutique
     *   targets?: array<string, array<int, float>>,  objectifs : clé de ligne => (shopId => pièces)
     *   total_targets?: array<int, float>, objectif « Total période » par boutique
     *   pct_mode?: string                  'penetration' | 'mix' | 'share' | 'none'
     * } $opts
     * @return array{level:string, pct_mode:string, columns:list<array>, rows:list<array>, total:array}
     */
    public static function table(array $rows, array $opts = []): array
    {
        $level   = $opts['level'] ?? 'product';
        $pctMode = $opts['pct_mode'] ?? 'penetration';
        $tickets = $opts['tickets'] ?? [];
        $targets = $opts['targets'] ?? [];

        $shops = $opts['shops'] ?? [];
        if ($shops === []) {
            foreach ($rows as $r) {
                $sid = (int)($r['shop_id'] ?? 0);
                $shops[$sid] = $shops[$sid] ?? ('#' . $sid);
            }
            ksort($shops);
        }
        $shopIds = array_keys($shops);

        $grouped = self::rollup($rows, $level);

        // Totaux de colonne : la somme des lignes du niveau, jamais une somme
        // de niveaux différents (ce serait du double comptage).
        $colQty = array_fill_keys($shopIds, 0.0);
        $colCa  = array_fill_keys($shopIds, 0.0);
        foreach ($grouped as $g) {
            foreach ($shopIds as $sid) {
                $colQty[$sid] += (float)($g['by_shop'][$sid]['qty'] ?? 0);
                $colCa[$sid]  += (float)($g['by_shop'][$sid]['ca'] ?? 0);
            }
        }
        $netQty = array_sum($colQty);

        $pct = static function (float $qty, int $sid) use ($pctMode, $tickets, $colQty, $netQty): ?float {
            switch ($pctMode) {
                case 'penetration':                       // % de tickets portant l'article
                    $t = (float)($tickets[$sid] ?? 0);
                    return $t > 0 ? $qty / $t * 100 : null;
                case 'mix':                               // part de la boutique dans son propre total
                    $c = (float)($colQty[$sid] ?? 0);
                    return $c > 0 ? $qty / $c * 100 : null;
                case 'share':                             // part de la boutique dans le total réseau
                    return $netQty > 0 ? $qty / $netQty * 100 : null;
                default:
                    return null;
            }
        };
        $pctTotal = static function (float $qty) use ($pctMode, $tickets, $netQty): ?float {
            if ($pctMode === 'penetration') {
                $t = array_sum($tickets);
                return $t > 0 ? $qty / $t * 100 : null;
            }
            if ($pctMode === 'mix' || $pctMode === 'share') {
                return $netQty > 0 ? $qty / $netQty * 100 : null;
            }
            return null;
        };

        $outRows = [];
        foreach ($grouped as $g) {
            $cells = [];
            foreach ($shopIds as $sid) {
                $q = (float)($g['by_shop'][$sid]['qty'] ?? 0);
                $tg = $targets[$g['key']][$sid] ?? null;
                $cells[$sid] = [
                    'qty'         => $q,
                    'ca'          => (float)($g['by_shop'][$sid]['ca'] ?? 0),
                    'pct'         => $pct($q, $sid),
                    'target'      => $tg !== null ? (float)$tg : null,
                    'progression' => ($tg !== null && (float)$tg > 0) ? $q / (float)$tg * 100 : null,
                ];
            }
            $rowTarget = null;
            if (isset($targets[$g['key']])) {
                $rowTarget = array_sum(array_map('floatval', $targets[$g['key']]));
            }
            $outRows[] = [
                'key'           => $g['key'],
                'label'         => $g['label'],
                'level'         => $level,
                'path'          => $g['path'],
                'cells'         => $cells,
                'total'         => [
                    'qty' => $g['qty'], 'ca' => $g['ca'],
                    'pct' => $pctTotal($g['qty']),
                ],
                'material_cost' => $g['material_cost'],
                'food_pct'      => $g['food_pct'],
                'target'        => $rowTarget,
                'progression'   => ($rowTarget !== null && $rowTarget > 0) ? $g['qty'] / $rowTarget * 100 : null,
            ];
        }

        // Ligne « Total période » + ligne « Objectif (pièces) ».
        $totTargets = $opts['total_targets'] ?? null;
        $totCells   = [];
        foreach ($shopIds as $sid) {
            $tg = $totTargets[$sid] ?? null;
            $totCells[$sid] = [
                'qty'         => $colQty[$sid],
                'ca'          => $colCa[$sid],
                'pct'         => $pct($colQty[$sid], $sid),
                'target'      => $tg !== null ? (float)$tg : null,
                'progression' => ($tg !== null && (float)$tg > 0) ? $colQty[$sid] / (float)$tg * 100 : null,
            ];
        }
        $totTarget = $totTargets !== null ? array_sum(array_map('floatval', $totTargets)) : null;

        $columns = [];
        foreach ($shops as $sid => $name) {
            $columns[] = [
                'shop_id' => $sid,
                'name'    => $name,
                'tickets' => isset($tickets[$sid]) ? (int)$tickets[$sid] : null,
            ];
        }

        return [
            'level'    => $level,
            'pct_mode' => $pctMode,
            'columns'  => $columns,
            'rows'     => $outRows,
            'total'    => [
                'label'       => 'Total période',
                'cells'       => $totCells,
                'total'       => ['qty' => $netQty, 'ca' => array_sum($colCa), 'pct' => $pctTotal($netQty)],
                'target'      => $totTarget,
                'progression' => ($totTarget !== null && $totTarget > 0) ? $netQty / $totTarget * 100 : null,
                'tickets'     => array_sum($tickets) ?: null,
            ],
        ];
    }

    // ───────────────────────────── Interne ─────────────────────────────

    /** @return list<array> */
    private static function treeAt(array $rows, array $levels, int $depth): array
    {
        if ($depth >= count($levels)) {
            return [];
        }
        $level = $levels[$depth];
        $out   = [];
        foreach (self::rollup($rows, $level) as $g) {
            $sub = array_values(array_filter($rows, static function ($r) use ($level, $g) {
                $label = $r[$level . '_name'] ?? null;
                $label = ($label === null || $label === '') ? '(' . $level . ' non renseigné)' : $label;
                return $label === $g['label'];
            }));
            $out[] = [
                'key'      => $g['key'],
                'label'    => $g['label'],
                'level'    => $level,
                'qty'      => $g['qty'],
                'ca'       => $g['ca'],
                'food_pct' => $g['food_pct'],
                'by_shop'  => $g['by_shop'],
                'children' => self::treeAt($sub, $levels, $depth + 1),
            ];
        }
        return $out;
    }

    /** Chemin hiérarchique d'une ligne au-dessus du niveau demandé. */
    private static function pathOf(array $r, string $level): array
    {
        $path = [];
        foreach (self::LEVELS as $l) {
            if ($l === $level) break;
            if (($r[$l . '_name'] ?? null) !== null) {
                $path[$l] = $r[$l . '_name'];
            }
        }
        return $path;
    }

    /** Un nœud est un PRODUIT s'il porte un id/nom de produit, ou s'il est feuille et quantifié. */
    private static function isProductNode(array $n, array $named): bool
    {
        foreach (self::ID_KEYS['product'] as $k) {
            if (isset($n[$k])) return true;
        }
        foreach (self::LEVEL_KEYS['product'] as $k) {
            if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') return true;
        }
        $hasChildArray = false;
        foreach ($n as $v) {
            if (is_array($v) && ($v === [] || array_is_list($v) || self::nodeName($v) !== null)) {
                $hasChildArray = true;
                break;
            }
        }
        return !$hasChildArray
            && self::nodeName($n) !== null
            && self::pick($n, self::QTY_PREFS) !== null;
    }

    private static function nodeName(array $n): ?string
    {
        foreach (self::NAME_KEYS as $k) {
            if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                return trim($n[$k]);
            }
        }
        return null;
    }

    /**
     * UNE seule valeur par nœud, par ordre de préférence, ratios exclus.
     * Même règle que FoodCost : additionner deux clés du même nœud double tout.
     */
    private static function pick(array $n, array $prefs, ?string $fallbackRe = null): ?float
    {
        foreach ($prefs as $pref) {
            foreach ($n as $k => $v) {
                if (preg_match(self::SKIP_RE, (string)$k)) continue;
                if (strtolower((string)$k) === $pref) {
                    $x = self::numOf($v);
                    if ($x !== null) return $x;
                }
            }
        }
        if ($fallbackRe !== null) {
            foreach ($n as $k => $v) {
                if (preg_match(self::SKIP_RE, (string)$k)) continue;
                if (preg_match($fallbackRe, (string)$k)) {
                    $x = self::numOf($v);
                    if ($x !== null) return $x;
                }
            }
        }
        return null;
    }

    private static function numOf($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (is_string($v) && $v !== '' && is_numeric($v)) return (float)$v;
        if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) return (float)$v['value'];
        return null;
    }

    private static function boolOrNull($v): ?bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v !== 0;
        if (is_string($v) && in_array(strtolower($v), ['0', '1', 'true', 'false'], true)) {
            return in_array(strtolower($v), ['1', 'true'], true);
        }
        return null;
    }
}
