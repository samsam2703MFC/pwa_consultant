<?php
declare(strict_types=1);

/**
 * Food Cost — calcul portable (PHP 8.1+, aucune dépendance).
 *
 * Port autonome de la chaîne de ce dépôt (ShopRepository::getMaterialCost +
 * ReportService::hexmMetrics). On injecte un `callable` HTTP ; la classe ne
 * connaît ni framework, ni base de données.
 *
 *   food cost %   = coût matière ÷ CA × 100
 *   marge brute % = 100 − food cost %
 *
 * Le coût matière vient, dans l'ordre :
 *   1. product-category-groups (grouping category → group → month)
 *   2. daily-summary
 *   3. résidu P&L F = T − L − OC − R
 *
 * Le champ `result` de l'API ne déduit pas toujours la matière : le résidu
 * n'est donc qu'un dernier repli, jamais la source primaire.
 */
final class FoodCost
{
    /** Clés de coût acceptées, par ordre de préférence. UNE SEULE par nœud. */
    private const COST_PREFS = [
        'material_cost', 'materials_cost', 'food_cost', 'goods_cost',
        'cost_of_goods', 'purchase_cost', 'total_cost', 'cost',
    ];

    /** Clés à ignorer : ratios, pourcentages, quantités. */
    private const SKIP_RE = '/(pct|percent|ratio|rate|delta|margin|qty|quantity|count)/i';

    /** Clés qui nomment un nœud « catégorie ». */
    private const NAME_KEYS = ['category_name', 'category', 'group_name', 'name', 'label'];

    /** Seuils de statut vs moyenne réseau, en % relatifs. */
    public const THRESHOLD_GOOD   = -5.0;
    public const THRESHOLD_DANGER = -15.0;

    /** @var callable(string, array): (array|null) */
    private $get;

    /** @var array<string, mixed> cache par shop|from|to (coût + clé '#src') */
    private array $cache = [];

    /**
     * @param callable(string $path, array $query): (array|null) $get
     *        Exécute un GET sur l'API métier et rend le tableau `data` décodé,
     *        ou null en cas d'échec (HTTP, timeout, payload illisible).
     */
    public function __construct(callable $get)
    {
        $this->get = $get;
    }

    // ───────────────────────────── API publique ─────────────────────────────

    /**
     * Food cost d'une fenêtre libre, CA connu de l'appelant.
     *
     * @param float|null $ca CA de la fenêtre ; null → seul `material` est rendu
     * @return array{material: ?float, ca: ?float, food_pct: ?float, gross_pct: ?float, source: string}
     */
    public function forWindow(int $shopId, string $from, string $to, ?float $ca = null): array
    {
        [$material, $source] = $this->materialCostWithSource($shopId, $from, $to);
        return $this->compose($material, $ca, $source);
    }

    /**
     * Food cost d'une période P&L ('day' | 'week' | 'month'). Le CA et la
     * fenêtre viennent du P&L lui-même ; le résidu sert de repli.
     *
     * @return array{material: ?float, ca: ?float, food_pct: ?float, gross_pct: ?float, source: string}
     */
    public function forPeriod(int $shopId, string $period = 'month'): array
    {
        $pnl = ($this->get)('/consultant/shops/' . $shopId . '/pnl', ['period' => $period]) ?? [];

        $ca = self::pnlValue($pnl['turnover'] ?? null);
        if ($ca === null || $ca <= 0) {
            return $this->compose(null, null, 'none');
        }

        $from = isset($pnl['date_from']) ? substr((string)$pnl['date_from'], 0, 10) : null;
        $to   = isset($pnl['date_to'])   ? substr((string)$pnl['date_to'], 0, 10)   : null;

        if ($from !== null && $to !== null) {
            [$material, $source] = $this->materialCostWithSource($shopId, $from, $to);
            if ($material !== null && $material > 0) {
                return $this->compose($material, $ca, $source);
            }
        }

        // Le P&L de la période porte parfois la matière directement.
        $material = self::pnlValue($pnl['material'] ?? null);
        if ($material !== null && $material > 0) {
            return $this->compose($material, $ca, 'pnl_material');
        }

        return $this->compose($this->residual($pnl), $ca, 'pnl_residual');
    }

    /**
     * Coût matière total d'une fenêtre. null si aucune source ne répond.
     */
    public function materialCost(int $shopId, string $from, string $to): ?float
    {
        return $this->materialCostWithSource($shopId, $from, $to)[0];
    }

    /**
     * Série MENSUELLE — de quoi remplir `mac_shop_monthly_pnl.material` et
     * tracer l'historique du food cost.
     *
     * @param string $from 'YYYY-MM'
     * @param string $to   'YYYY-MM'
     * @return array<string, array{turnover: ?float, material: ?float, labour: ?float, overhead: ?float, result: ?float, food_pct: ?float, gross_pct: ?float}>
     *         map 'YYYY-MM' => postes
     */
    public function monthlySeries(int $shopId, string $from, string $to): array
    {
        $d = ($this->get)('/consultant/shops/' . $shopId . '/pnl/monthly', ['from' => $from, 'to' => $to]);
        return $this->parseSeries($d, 'months', 'month', 7, ['turnover', 'revenue']);
    }

    /**
     * Série QUOTIDIENNE — c'est l'endpoint qui porte le plus fidèlement la
     * matière ; il sert à reconstituer un mois jour par jour quand le mensuel
     * omet `material`.
     *
     * @return array<string, array{turnover: ?float, material: ?float, labour: ?float, overhead: ?float, result: ?float, food_pct: ?float, gross_pct: ?float}>
     *         map 'Y-m-d' => postes
     */
    public function dailySeries(int $shopId, string $from, string $to): array
    {
        $d = ($this->get)('/consultant/shops/' . $shopId . '/pnl/daily', ['from' => $from, 'to' => $to]);
        return $this->parseSeries($d, 'days', 'date', 10, ['revenue', 'turnover']);
    }

    /**
     * Agrège une série (mensuelle ou quotidienne) en un total de fenêtre.
     * Les lignes sans matière sont signalées par `complete = false` : une somme
     * partielle présentée comme complète sous-évalue le food cost.
     *
     * @param array<string, array> $series
     * @return array{ca: float, material: ?float, food_pct: ?float, gross_pct: ?float, complete: bool, rows: int, rows_with_material: int}
     */
    public static function aggregate(array $series): array
    {
        $ca = 0.0; $mat = 0.0; $withMat = 0; $rows = 0;
        foreach ($series as $row) {
            $rows++;
            $ca += (float)($row['turnover'] ?? 0);
            if (($row['material'] ?? null) !== null) {
                $mat += abs((float)$row['material']);
                $withMat++;
            }
        }
        $material = $withMat > 0 ? $mat : null;
        $foodPct  = ($material !== null && $ca > 0) ? $material / $ca * 100 : null;
        return [
            'ca'                 => $ca,
            'material'           => $material,
            'food_pct'           => $foodPct,
            'gross_pct'          => $foodPct !== null ? 100 - $foodPct : null,
            'complete'           => $rows > 0 && $withMat === $rows,
            'rows'               => $rows,
            'rows_with_material' => $withMat,
        ];
    }

    /**
     * Résultat net RECALCULÉ : CA − matière − main d'œuvre − frais généraux.
     * On ne se fie pas au `result` de l'API tant que les trois postes sont là —
     * il ne déduit pas toujours la matière (et la marge sort alors vers 40 %).
     */
    public static function netResult(?float $ca, ?float $material, ?float $labour, ?float $overhead, ?float $apiResult = null): ?float
    {
        if ($ca !== null && $material !== null && $labour !== null && $overhead !== null) {
            return $ca - abs($material) - abs($labour) - abs($overhead);
        }
        return $apiResult;
    }

    /**
     * Statut d'un KPI face à la moyenne réseau.
     *
     * @param int $dir +1 = plus haut est mieux (marge brute) ; -1 = plus bas
     *                 est mieux (food cost, labour)
     * @return string 'good' | 'warn' | 'danger' | 'na'
     */
    public static function status(?float $value, ?float $networkAvg, int $dir = -1): string
    {
        if ($value === null || $networkAvg === null || abs($networkAvg) < 1e-9) {
            return 'na';
        }
        $score = $dir * ($value - $networkAvg) / abs($networkAvg) * 100;
        if ($score >= self::THRESHOLD_GOOD)   return 'good';
        if ($score >= self::THRESHOLD_DANGER) return 'warn';
        return 'danger';
    }

    /**
     * Couleur d'une valeur d'après les bandes de `mac_kpi_threshold` : la bande
     * retenue est celle avec le plus grand `min_pct` <= valeur.
     *
     * @param array<array{min_pct: ?float, color: string, label?: ?string}> $bands
     * @return array{color: string, label: ?string}|null
     */
    public static function band(?float $value, array $bands): ?array
    {
        if ($value === null || $bands === []) {
            return null;
        }
        $best = null;
        foreach ($bands as $b) {
            $min = $b['min_pct'] ?? null;
            if ($min !== null && (float)$min > $value) {
                continue;
            }
            if ($best === null
                || ($best['min_pct'] ?? null) === null
                || ($min !== null && (float)$min > (float)$best['min_pct'])) {
                $best = $b;
            }
        }
        return $best === null ? null
            : ['color' => (string)$best['color'], 'label' => $best['label'] ?? null];
    }

    /** Valeur d'un poste P&L : {"value": n} | n | "n" → float, sinon null. */
    public static function pnlValue($node): ?float
    {
        if (is_int($node) || is_float($node)) {
            return (float)$node;
        }
        if (is_string($node) && $node !== '' && is_numeric($node)) {
            return (float)$node;
        }
        if (is_array($node) && isset($node['value']) && is_numeric($node['value'])) {
            return (float)$node['value'];
        }
        return null;
    }

    /**
     * Somme le coût matière d'un payload imbriqué (catégories ou résumé
     * quotidien) : pour chaque nœud NOMMÉ, UNE SEULE clé de coût, la plus
     * prioritaire, en sautant ratios et quantités. Additionner deux clés du
     * même nœud (material_cost + total_cost) doublerait le food cost.
     *
     * @return float|null null si aucun coût n'a été trouvé (≠ 0.0, qui veut
     *                    dire « trouvé, et il vaut zéro »)
     */
    public static function sumMaterialCost($data): ?float
    {
        $total = 0.0;
        $found = false;

        $numOf = static function ($v): ?float {
            if (is_int($v) || is_float($v)) return (float)$v;
            if (is_string($v) && $v !== '' && is_numeric($v)) return (float)$v;
            if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) return (float)$v['value'];
            return null;
        };
        $skip = static fn($k) => (bool)preg_match(self::SKIP_RE, (string)$k);

        $walk = static function ($n) use (&$walk, &$total, &$found, $numOf, $skip): void {
            if (!is_array($n)) {
                return;
            }
            if (array_is_list($n)) {
                foreach ($n as $x) {
                    $walk($x);
                }
                return;
            }

            $name = null;
            foreach (self::NAME_KEYS as $k) {
                if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                    $name = trim($n[$k]);
                    break;
                }
            }

            if ($name !== null) {
                $cost = null;
                foreach (self::COST_PREFS as $pref) {
                    foreach ($n as $k => $v) {
                        if ($skip($k)) continue;
                        if (strtolower((string)$k) === $pref) {
                            $x = $numOf($v);
                            if ($x !== null) { $cost = $x; break 2; }
                        }
                    }
                }
                if ($cost === null) {
                    foreach ($n as $k => $v) {
                        if ($skip($k)) continue;
                        if (preg_match('/cost/i', (string)$k)) {
                            $x = $numOf($v);
                            if ($x !== null) { $cost = $x; break; }
                        }
                    }
                }
                if ($cost !== null) {
                    $total += $cost;
                    $found = true;
                }
            }

            foreach ($n as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };

        $walk($data);
        return $found ? $total : null;
    }

    // ───────────────────────────── Interne ─────────────────────────────

    /** @return array{0: ?float, 1: string} [coût matière, source] */
    private function materialCostWithSource(int $shopId, string $from, string $to): array
    {
        $ck = $shopId . '|' . $from . '|' . $to;
        if (array_key_exists($ck, $this->cache)) {
            return [$this->cache[$ck], $this->cache[$ck . '#src'] ?? 'cache'];
        }

        $remember = function (?float $v, string $src) use ($ck): array {
            $this->cache[$ck]          = $v;
            $this->cache[$ck . '#src'] = $src;
            return [$v, $src];
        };

        // 1. Coûts matière par catégorie de produits.
        foreach (['category', 'group', 'month'] as $grouping) {
            $d = ($this->get)(
                '/shops/' . $shopId . '/statistics/sales/product-category-groups',
                ['date_from' => $from, 'date_to' => $to, 'grouping' => $grouping]
            );
            if (is_array($d)) {
                $total = self::sumMaterialCost($d);
                if ($total !== null && $total > 0) {
                    return $remember($total, 'category');
                }
            }
        }

        // 2. Repli : résumé quotidien.
        $d = ($this->get)(
            '/shops/' . $shopId . '/statistics/daily-summary',
            ['date_from' => $from, 'date_to' => $to, 'from' => $from, 'to' => $to]
        );
        if (is_array($d)) {
            $total = self::sumMaterialCost($d);
            if ($total !== null && $total > 0) {
                return $remember($total, 'daily_summary');
            }
        }

        return $remember(null, 'none');
    }

    /** Résidu P&L : F = T − L − OC − R. Dernier repli seulement. */
    private function residual(array $pnl): ?float
    {
        $t = self::pnlValue($pnl['turnover'] ?? null);
        if ($t === null || $t <= 0) {
            return null;
        }
        $l  = self::pnlValue($pnl['labour'] ?? null)   ?? 0.0;
        $oc = self::pnlValue($pnl['overhead'] ?? null) ?? 0.0;
        $r  = self::pnlValue($pnl['result'] ?? null)   ?? 0.0;
        $f  = $t - abs($l) - abs($oc) - $r;
        return $f > 0 ? $f : null;
    }

    /** @return array{material: ?float, ca: ?float, food_pct: ?float, gross_pct: ?float, source: string} */
    private function compose(?float $material, ?float $ca, string $source): array
    {
        $foodPct = ($material !== null && $ca !== null && $ca > 0) ? $material / $ca * 100 : null;
        return [
            'material'  => $material,
            'ca'        => $ca,
            'food_pct'  => $foodPct,
            'gross_pct' => $foodPct !== null ? 100 - $foodPct : null,
            'source'    => $material === null ? 'none' : $source,
        ];
    }

    /**
     * Parseur commun des séries P&L (mensuelle / quotidienne).
     *
     * @param string[] $caKeys clés acceptées pour le CA, par ordre
     * @return array<string, array>
     */
    private function parseSeries($d, string $listKey, string $keyField, int $keyLen, array $caKeys): array
    {
        if (!is_array($d)) {
            return [];
        }
        $num = static fn($v) => is_numeric($v) ? (float)$v : null;
        $out = [];
        foreach (($d[$listKey] ?? $d) as $row) {
            if (!is_array($row) || empty($row[$keyField])) {
                continue;
            }
            $ca = null;
            foreach ($caKeys as $k) {
                if (($ca = $num($row[$k] ?? null)) !== null) {
                    break;
                }
            }
            $material = $num($row['material'] ?? null);
            $foodPct  = ($material !== null && $ca !== null && $ca > 0) ? abs($material) / $ca * 100 : null;

            $out[substr((string)$row[$keyField], 0, $keyLen)] = [
                'turnover'  => $ca,
                'material'  => $material,
                'labour'    => $num($row['labour'] ?? null),
                'overhead'  => $num($row['overhead'] ?? null),
                'result'    => $num($row['result'] ?? null),
                'food_pct'  => $foodPct,
                'gross_pct' => $foodPct !== null ? 100 - $foodPct : null,
            ];
        }
        return $out;
    }
}
