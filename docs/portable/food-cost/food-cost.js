/**
 * Food Cost — calcul portable côté navigateur (aucune dépendance).
 *
 * Même chaîne que FoodCost.php, à coller tel quel dans un autre projet :
 *   food cost %   = coût matière ÷ CA × 100
 *   marge brute % = 100 − food cost %
 *
 * Sources du coût matière, dans l'ordre :
 *   1. product-category-groups (grouping category → group → month)
 *   2. daily-summary
 *   3. résidu P&L F = T − L − OC − R   (dernier repli : `result` ne déduit
 *      pas toujours la matière)
 *
 * Usage :
 *   const fc = new FoodCost((path, params) => fetch(...).then(r => r.json()).then(j => j.data));
 *   fc.forPeriod(12, 'month').then(r => r.food_pct);
 *
 * Exposé en global (window.FoodCost), en CommonJS et en ESM.
 */
(function (root, factory) {
    var FoodCost = factory();
    if (typeof module === 'object' && module.exports) module.exports = FoodCost;
    else root.FoodCost = FoodCost;
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Clés de coût acceptées, par ordre de préférence. UNE SEULE par nœud :
    // additionner material_cost ET total_cost doublerait le food cost.
    var COST_PREFS = ['material_cost', 'materials_cost', 'food_cost', 'goods_cost',
                      'cost_of_goods', 'purchase_cost', 'total_cost', 'cost'];
    var SKIP_RE  = /(pct|percent|ratio|rate|delta|margin|qty|quantity|count)/i;
    var NAME_KEYS = ['category_name', 'category', 'group_name', 'name', 'label'];

    var THRESHOLD_GOOD   = -5.0;
    var THRESHOLD_DANGER = -15.0;

    function numOf(v) {
        if (typeof v === 'number' && isFinite(v)) return v;
        if (typeof v === 'string' && v !== '' && isFinite(Number(v))) return Number(v);
        if (v && typeof v === 'object' && typeof v.value === 'number') return v.value;
        if (v && typeof v === 'object' && typeof v.value === 'string'
            && v.value !== '' && isFinite(Number(v.value))) return Number(v.value);
        return null;
    }

    /** Valeur d'un poste P&L : {value: n} | n | "n" → number, sinon null. */
    function pnlValue(node) { return numOf(node); }

    /**
     * Somme le coût matière d'un payload imbriqué : pour chaque nœud NOMMÉ,
     * une seule clé de coût, la plus prioritaire, ratios et quantités ignorés.
     * null si aucun coût trouvé (≠ 0, qui veut dire « trouvé, et il vaut 0 »).
     */
    function sumMaterialCost(node) {
        var total = 0, found = false;
        var skip = function (k) { return SKIP_RE.test(k); };

        (function walk(n) {
            if (Array.isArray(n)) { n.forEach(walk); return; }
            if (!n || typeof n !== 'object') return;

            var name = null, k, p, x;
            for (var i = 0; i < NAME_KEYS.length; i++) {
                var nk = n[NAME_KEYS[i]];
                if (typeof nk === 'string' && nk.trim() !== '') { name = nk.trim(); break; }
            }

            if (name) {
                var cost = null;
                for (p = 0; p < COST_PREFS.length && cost === null; p++) {
                    for (k in n) {
                        if (!Object.prototype.hasOwnProperty.call(n, k) || skip(k)) continue;
                        if (k.toLowerCase() === COST_PREFS[p]) {
                            x = numOf(n[k]);
                            if (x !== null) { cost = x; break; }
                        }
                    }
                }
                if (cost === null) {
                    for (k in n) {
                        if (!Object.prototype.hasOwnProperty.call(n, k) || skip(k)) continue;
                        if (/cost/i.test(k)) {
                            x = numOf(n[k]);
                            if (x !== null) { cost = x; break; }
                        }
                    }
                }
                if (cost !== null) { total += cost; found = true; }
            }

            for (k in n) {
                if (Object.prototype.hasOwnProperty.call(n, k) && n[k] && typeof n[k] === 'object') walk(n[k]);
            }
        }(node));

        return found ? total : null;
    }

    /**
     * Statut vs moyenne réseau.
     *   score = dir × (valeur − moyenne) ÷ |moyenne| × 100
     * dir = -1 : plus bas est mieux (food cost) ; +1 : plus haut est mieux.
     */
    function status(value, networkAvg, dir) {
        if (value === null || value === undefined || networkAvg === null
            || networkAvg === undefined || Math.abs(networkAvg) < 1e-9) return 'na';
        var score = (dir || -1) * (value - networkAvg) / Math.abs(networkAvg) * 100;
        if (score >= THRESHOLD_GOOD)   return 'good';
        if (score >= THRESHOLD_DANGER) return 'warn';
        return 'danger';
    }

    /** Bande de couleur (mac_kpi_threshold) : plus grand min_pct <= valeur. */
    function band(value, bands) {
        if (value === null || value === undefined || !bands || !bands.length) return null;
        var best = null;
        bands.forEach(function (b) {
            var min = (b.min_pct === null || b.min_pct === undefined) ? null : Number(b.min_pct);
            if (min !== null && min > value) return;
            if (best === null || best.min_pct === null || best.min_pct === undefined
                || (min !== null && min > Number(best.min_pct))) best = b;
        });
        return best ? { color: best.color, label: best.label || null } : null;
    }

    /** Résultat net RECALCULÉ : CA − matière − MO − frais. */
    function netResult(ca, material, labour, overhead, apiResult) {
        var ok = function (v) { return v !== null && v !== undefined; };
        if (ok(ca) && ok(material) && ok(labour) && ok(overhead)) {
            return ca - Math.abs(material) - Math.abs(labour) - Math.abs(overhead);
        }
        return ok(apiResult) ? apiResult : null;
    }

    function compose(material, ca, source) {
        var foodPct = (material !== null && ca !== null && ca > 0) ? material / ca * 100 : null;
        return {
            material:  material,
            ca:        ca,
            food_pct:  foodPct,
            gross_pct: foodPct !== null ? 100 - foodPct : null,
            source:    material === null ? 'none' : source
        };
    }

    /**
     * @param {function(string, object): Promise<object|null>} fetcher
     *        Exécute un GET sur l'API métier et résout le payload `data`
     *        (ou null en cas d'échec — ne JAMAIS rejeter).
     */
    function FoodCost(fetcher) {
        if (!(this instanceof FoodCost)) return new FoodCost(fetcher);
        this._get   = fetcher;
        this._cache = {};   // 'shopId|from|to' => { material, source }
    }

    /** Coût matière d'une fenêtre → Promise<{material, source}>. */
    FoodCost.prototype._materialCost = function (shopId, from, to) {
        var self = this, ck = shopId + '|' + from + '|' + to;
        if (ck in this._cache) return Promise.resolve(this._cache[ck]);

        var remember = function (material, source) {
            var r = { material: material, source: material === null ? 'none' : source };
            self._cache[ck] = r;
            return r;
        };
        var safe = function (path, params) {
            return Promise.resolve(self._get(path, params)).catch(function () { return null; });
        };

        var groupings = ['category', 'group', 'month'];

        function attempt(i) {
            if (i >= groupings.length) {
                // Repli : résumé quotidien.
                return safe('/shops/' + shopId + '/statistics/daily-summary',
                            { date_from: from, date_to: to, from: from, to: to })
                    .then(function (d) {
                        var c = d ? sumMaterialCost(d) : null;
                        return remember(c !== null && c > 0 ? c : null, 'daily_summary');
                    });
            }
            return safe('/shops/' + shopId + '/statistics/sales/product-category-groups',
                        { date_from: from, date_to: to, grouping: groupings[i] })
                .then(function (d) {
                    var t = d ? sumMaterialCost(d) : null;
                    return (t !== null && t > 0) ? remember(t, 'category') : attempt(i + 1);
                });
        }
        return attempt(0);
    };

    /**
     * Food cost d'une fenêtre libre, CA connu de l'appelant.
     * → Promise<{material, ca, food_pct, gross_pct, source}>
     */
    FoodCost.prototype.forWindow = function (shopId, from, to, ca) {
        return this._materialCost(shopId, from, to).then(function (r) {
            return compose(r.material, (ca === undefined ? null : ca), r.source);
        });
    };

    /**
     * Food cost d'une période P&L ('day' | 'week' | 'month'). Le CA et la
     * fenêtre viennent du P&L ; le résidu T−L−OC−R sert de dernier repli.
     */
    FoodCost.prototype.forPeriod = function (shopId, period) {
        var self = this;
        return Promise.resolve(this._get('/consultant/shops/' + shopId + '/pnl',
                                         { period: period || 'month' }))
            .catch(function () { return null; })
            .then(function (pnl) {
                if (!pnl) return compose(null, null, 'none');

                var T = pnlValue(pnl.turnover);
                if (T === null || T <= 0) return compose(null, null, 'none');

                var residual = function () {
                    var L  = pnlValue(pnl.labour)   || 0,
                        OC = pnlValue(pnl.overhead) || 0,
                        R  = pnlValue(pnl.result)   || 0,
                        F  = T - Math.abs(L) - Math.abs(OC) - R;
                    return compose(F > 0 ? F : null, T, 'pnl_residual');
                };

                var from = pnl.date_from ? String(pnl.date_from).slice(0, 10) : null;
                var to   = pnl.date_to   ? String(pnl.date_to).slice(0, 10)   : null;
                if (!from || !to) {
                    var direct = pnlValue(pnl.material);
                    return (direct !== null && direct > 0)
                        ? compose(direct, T, 'pnl_material') : residual();
                }

                return self._materialCost(shopId, from, to).then(function (r) {
                    if (r.material !== null && r.material > 0) return compose(r.material, T, r.source);
                    var direct = pnlValue(pnl.material);
                    if (direct !== null && direct > 0) return compose(direct, T, 'pnl_material');
                    return residual();
                });
            });
    };

    FoodCost.pnlValue        = pnlValue;
    FoodCost.sumMaterialCost = sumMaterialCost;
    FoodCost.status          = status;
    FoodCost.band            = band;
    FoodCost.netResult       = netResult;
    FoodCost.THRESHOLD_GOOD   = THRESHOLD_GOOD;
    FoodCost.THRESHOLD_DANGER = THRESHOLD_DANGER;

    return FoodCost;
}));
