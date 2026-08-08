/**
 * Mix produits — tableau « produits × boutiques » portable (navigateur, sans
 * dépendance). Port de ProductMix.php : mêmes clés, mêmes règles, mêmes sorties.
 *
 * Hiérarchie : secteur → groupe → catégorie → produit.
 * Source : GET /shops/{id}/statistics/sales/product-category-groups
 *          ?date_from&date_to&grouping=category|group|month
 *
 *   const pm = new ProductMix(fetcher);            // même fetcher que FoodCost
 *   pm.forShops([1,2,3], '2026-01-01', '2026-01-31').then(rows => {
 *     const t = ProductMix.table(rows, { level: 'category', shops, tickets, targets });
 *   });
 */
(function (root, factory) {
    var ProductMix = factory();
    if (typeof module === 'object' && module.exports) module.exports = ProductMix;
    else root.ProductMix = ProductMix;
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var LEVELS = ['sector', 'group', 'category', 'product'];

    var LEVEL_KEYS = {
        sector:   ['sector_name', 'sector', 'univers', 'universe', 'category_1', 'category1', 'level_1'],
        group:    ['group_name', 'group', 'family_name', 'family', 'category_2', 'category2', 'level_2'],
        category: ['category_name', 'category', 'sub_category', 'subcategory', 'category_3', 'category3', 'level_3'],
        product:  ['product_name', 'product', 'article_name', 'article', 'item_name', 'item']
    };
    var ID_KEYS = {
        sector:   ['sector_id', 'category_1_id'],
        group:    ['group_id', 'family_id', 'category_2_id'],
        category: ['category_id', 'sub_category_id', 'category_3_id'],
        product:  ['product_id', 'article_id', 'item_id']
    };
    var NAME_KEYS = ['category_name', 'category', 'group_name', 'name', 'label', 'title'];
    var QTY_PREFS = ['quantity', 'qty', 'pieces', 'units', 'sold_quantity', 'sold', 'nb', 'count', 'volume'];
    var CA_PREFS  = ['turnover', 'revenue', 'sales', 'ca', 'net_sales', 'amount', 'total_amount', 'value', 'total'];
    var COST_PREFS = ['material_cost', 'materials_cost', 'food_cost', 'goods_cost', 'cost_of_goods', 'purchase_cost'];
    var SKIP_RE = /(pct|percent|ratio|rate|delta|margin|avg|average|prev|n1|last_year)/i;

    function numOf(v) {
        if (typeof v === 'number' && isFinite(v)) return v;
        if (typeof v === 'string' && v !== '' && isFinite(Number(v))) return Number(v);
        if (v && typeof v === 'object' && typeof v.value === 'number') return v.value;
        return null;
    }

    /** UNE seule valeur par nœud, par ordre de préférence, ratios exclus. */
    function pick(n, prefs, fallbackRe) {
        var k, p, x;
        for (p = 0; p < prefs.length; p++) {
            for (k in n) {
                if (!Object.prototype.hasOwnProperty.call(n, k) || SKIP_RE.test(k)) continue;
                if (k.toLowerCase() === prefs[p]) {
                    x = numOf(n[k]);
                    if (x !== null) return x;
                }
            }
        }
        if (fallbackRe) {
            for (k in n) {
                if (!Object.prototype.hasOwnProperty.call(n, k) || SKIP_RE.test(k)) continue;
                if (fallbackRe.test(k)) {
                    x = numOf(n[k]);
                    if (x !== null) return x;
                }
            }
        }
        return null;
    }

    function nodeName(n) {
        for (var i = 0; i < NAME_KEYS.length; i++) {
            var v = n[NAME_KEYS[i]];
            if (typeof v === 'string' && v.trim() !== '') return v.trim();
        }
        return null;
    }

    function boolOrNull(v) {
        if (typeof v === 'boolean') return v;
        if (typeof v === 'number') return v !== 0;
        if (typeof v === 'string' && ['0', '1', 'true', 'false'].indexOf(v.toLowerCase()) !== -1) {
            return v.toLowerCase() === '1' || v.toLowerCase() === 'true';
        }
        return null;
    }

    function isProductNode(n) {
        var i, k;
        for (i = 0; i < ID_KEYS.product.length; i++) if (n[ID_KEYS.product[i]] !== undefined) return true;
        for (i = 0; i < LEVEL_KEYS.product.length; i++) {
            var v = n[LEVEL_KEYS.product[i]];
            if (typeof v === 'string' && v.trim() !== '') return true;
        }
        var hasChildArray = false;
        for (k in n) {
            if (!Object.prototype.hasOwnProperty.call(n, k)) continue;
            var c = n[k];
            if (c && typeof c === 'object' && (Array.isArray(c) || nodeName(c) !== null)) { hasChildArray = true; break; }
        }
        return !hasChildArray && nodeName(n) !== null && pick(n, QTY_PREFS) !== null;
    }

    /**
     * Aplatit un payload imbriqué en lignes « une par produit ». La hiérarchie
     * vient du chemin des nœuds nommés ; les clés explicites priment sur la
     * position. Les nœuds intermédiaires n'émettent PAS de ligne — sommer une
     * catégorie ET ses produits doublerait le tableau.
     */
    function flatten(payload, shopId) {
        var rows = [];
        shopId = shopId || 0;

        (function walk(n, path, named) {
            if (Array.isArray(n)) { n.forEach(function (x) { walk(x, path, named); }); return; }
            if (!n || typeof n !== 'object') return;

            var next = {}; for (var kk in named) next[kk] = named[kk];

            LEVELS.forEach(function (lvl) {
                LEVEL_KEYS[lvl].some(function (k) {
                    if (typeof n[k] === 'string' && n[k].trim() !== '') { next[lvl] = n[k].trim(); return true; }
                    return false;
                });
                ID_KEYS[lvl].forEach(function (k) {
                    if (typeof n[k] === 'string' || typeof n[k] === 'number') next[lvl + '_id'] = String(n[k]);
                });
            });

            var label = nodeName(n);

            if (isProductNode(n)) {
                var qty  = pick(n, QTY_PREFS);
                var ca   = pick(n, CA_PREFS);
                var cost = pick(n, COST_PREFS, /cost/i);
                var name = next.product || label;
                if (name !== null || qty !== null || ca !== null) {
                    rows.push({
                        shop_id:       shopId,
                        sector_id:     next.sector_id   || null,
                        sector_name:   next.sector      || null,
                        group_id:      next.group_id    || null,
                        group_name:    next.group       || (path[0] || null),
                        category_id:   next.category_id || null,
                        category_name: next.category    || (path.length > 1 ? path[path.length - 1] : (path[0] || null)),
                        product_id:    next.product_id  || null,
                        product_name:  name,
                        is_pdm:        boolOrNull(n.is_pdm),
                        qty:           qty,
                        ca:            ca,
                        material_cost: cost,
                        path:          path.slice()
                    });
                }
                return;   // un produit n'a pas d'enfant utile
            }

            var nextPath = label ? path.concat([label]) : path;
            for (var k in n) {
                if (Object.prototype.hasOwnProperty.call(n, k) && n[k] && typeof n[k] === 'object') walk(n[k], nextPath, next);
            }
        }(payload, [], {}));

        return rows;
    }

    function pathOf(r, level) {
        var path = {};
        for (var i = 0; i < LEVELS.length; i++) {
            if (LEVELS[i] === level) break;
            if (r[LEVELS[i] + '_name']) path[LEVELS[i]] = r[LEVELS[i] + '_name'];
        }
        return path;
    }

    function labelOf(r, level) {
        var l = r[level + '_name'];
        return (l === null || l === undefined || l === '') ? '(' + level + ' non renseigné)' : l;
    }

    /** Regroupe des lignes à un niveau de la hiérarchie. */
    function rollup(rows, level) {
        level = level || 'product';
        if (LEVELS.indexOf(level) === -1) throw new Error('niveau inconnu : ' + level);

        var acc = {}, order = [];
        rows.forEach(function (r) {
            var label = labelOf(r, level);
            var id = r[level + '_id'];
            var key = (id !== null && id !== undefined && id !== '')
                ? level + ':' + id : level + '#' + String(label).toLowerCase();

            if (!acc[key]) {
                acc[key] = { key: key, label: label, level: level, path: pathOf(r, level),
                             by_shop: {}, qty: 0, ca: 0, material_cost: null, _hasCost: false };
                order.push(key);
            }
            var a = acc[key], sid = r.shop_id || 0;
            if (!a.by_shop[sid]) a.by_shop[sid] = { qty: 0, ca: 0, material_cost: null };
            a.by_shop[sid].qty += Number(r.qty) || 0;
            a.by_shop[sid].ca  += Number(r.ca) || 0;
            a.qty += Number(r.qty) || 0;
            a.ca  += Number(r.ca) || 0;
            if (r.material_cost !== null && r.material_cost !== undefined) {
                a.material_cost = (a.material_cost || 0) + Math.abs(Number(r.material_cost));
                a.by_shop[sid].material_cost = (a.by_shop[sid].material_cost || 0) + Math.abs(Number(r.material_cost));
                a._hasCost = true;
            }
        });

        return order.map(function (k) {
            var a = acc[k];
            a.material_cost = a._hasCost ? a.material_cost : null;
            a.food_pct = (a.material_cost !== null && a.ca > 0) ? a.material_cost / a.ca * 100 : null;
            delete a._hasCost;
            return a;
        }).sort(function (x, y) { return (y.qty - x.qty) || x.label.localeCompare(y.label); });
    }

    /** Arbre secteur → groupe → catégorie → produit, pour un tableau dépliable. */
    function tree(rows, levels) {
        levels = (levels || LEVELS).filter(function (l) { return LEVELS.indexOf(l) !== -1; });
        if (!levels.length) return [];
        return (function at(rs, depth) {
            if (depth >= levels.length) return [];
            var level = levels[depth];
            return rollup(rs, level).map(function (g) {
                var sub = rs.filter(function (r) { return labelOf(r, level) === g.label; });
                return { key: g.key, label: g.label, level: level, qty: g.qty, ca: g.ca,
                         food_pct: g.food_pct, by_shop: g.by_shop, children: at(sub, depth + 1) };
            });
        }(rows, 0));
    }

    /**
     * Le tableau complet : lignes = niveau choisi, colonnes = boutiques,
     * + Total réseau, Total période, Objectif (pièces) et Progression.
     *
     * opts = { level, shops:{id:name}, tickets:{id:n}, targets:{key:{id:n}},
     *          total_targets:{id:n}, pct_mode:'penetration'|'mix'|'share'|'none' }
     */
    function table(rows, opts) {
        opts = opts || {};
        var level   = opts.level || 'product';
        var pctMode = opts.pct_mode || 'penetration';
        var tickets = opts.tickets || {};
        var targets = opts.targets || {};

        var shops = opts.shops;
        if (!shops) {
            shops = {};
            rows.forEach(function (r) { var s = r.shop_id || 0; if (!shops[s]) shops[s] = '#' + s; });
        }
        var shopIds = Object.keys(shops).map(Number).sort(function (a, b) { return a - b; });

        var grouped = rollup(rows, level);

        var colQty = {}, colCa = {};
        shopIds.forEach(function (s) { colQty[s] = 0; colCa[s] = 0; });
        grouped.forEach(function (g) {
            shopIds.forEach(function (s) {
                colQty[s] += (g.by_shop[s] && g.by_shop[s].qty) || 0;
                colCa[s]  += (g.by_shop[s] && g.by_shop[s].ca) || 0;
            });
        });
        var netQty = shopIds.reduce(function (a, s) { return a + colQty[s]; }, 0);
        var netTickets = shopIds.reduce(function (a, s) { return a + (Number(tickets[s]) || 0); }, 0);

        function pct(qty, sid) {
            if (pctMode === 'penetration') { var t = Number(tickets[sid]) || 0; return t > 0 ? qty / t * 100 : null; }
            if (pctMode === 'mix')   { var c = colQty[sid] || 0; return c > 0 ? qty / c * 100 : null; }
            if (pctMode === 'share') { return netQty > 0 ? qty / netQty * 100 : null; }
            return null;
        }
        function pctTotal(qty) {
            if (pctMode === 'penetration') return netTickets > 0 ? qty / netTickets * 100 : null;
            if (pctMode === 'mix' || pctMode === 'share') return netQty > 0 ? qty / netQty * 100 : null;
            return null;
        }

        var outRows = grouped.map(function (g) {
            var cells = {};
            shopIds.forEach(function (sid) {
                var q = (g.by_shop[sid] && g.by_shop[sid].qty) || 0;
                var tg = (targets[g.key] && targets[g.key][sid] !== undefined) ? Number(targets[g.key][sid]) : null;
                cells[sid] = {
                    qty: q,
                    ca: (g.by_shop[sid] && g.by_shop[sid].ca) || 0,
                    pct: pct(q, sid),
                    target: tg,
                    progression: (tg !== null && tg > 0) ? q / tg * 100 : null
                };
            });
            var rowTarget = null;
            if (targets[g.key]) {
                rowTarget = Object.keys(targets[g.key]).reduce(function (a, k) { return a + Number(targets[g.key][k] || 0); }, 0);
            }
            return {
                key: g.key, label: g.label, level: level, path: g.path, cells: cells,
                total: { qty: g.qty, ca: g.ca, pct: pctTotal(g.qty) },
                material_cost: g.material_cost, food_pct: g.food_pct,
                target: rowTarget,
                progression: (rowTarget !== null && rowTarget > 0) ? g.qty / rowTarget * 100 : null
            };
        });

        var totTargets = opts.total_targets || null;
        var totCells = {};
        shopIds.forEach(function (sid) {
            var tg = (totTargets && totTargets[sid] !== undefined) ? Number(totTargets[sid]) : null;
            totCells[sid] = {
                qty: colQty[sid], ca: colCa[sid], pct: pct(colQty[sid], sid),
                target: tg,
                progression: (tg !== null && tg > 0) ? colQty[sid] / tg * 100 : null
            };
        });
        var totTarget = totTargets
            ? Object.keys(totTargets).reduce(function (a, k) { return a + Number(totTargets[k] || 0); }, 0) : null;

        return {
            level: level,
            pct_mode: pctMode,
            columns: shopIds.map(function (sid) {
                return { shop_id: sid, name: shops[sid], tickets: tickets[sid] !== undefined ? Number(tickets[sid]) : null };
            }),
            rows: outRows,
            total: {
                label: 'Total période',
                cells: totCells,
                total: { qty: netQty, ca: shopIds.reduce(function (a, s) { return a + colCa[s]; }, 0), pct: pctTotal(netQty) },
                target: totTarget,
                progression: (totTarget !== null && totTarget > 0) ? netQty / totTarget * 100 : null,
                tickets: netTickets || null
            }
        };
    }

    function ProductMix(fetcher) {
        if (!(this instanceof ProductMix)) return new ProductMix(fetcher);
        this._get = fetcher;
    }

    /** Lignes à plat d'une boutique — grouping category → group → month. */
    ProductMix.prototype.forShop = function (shopId, from, to) {
        var self = this, groupings = ['category', 'group', 'month'];
        return (function attempt(i) {
            if (i >= groupings.length) return Promise.resolve([]);
            return Promise.resolve(self._get('/shops/' + shopId + '/statistics/sales/product-category-groups',
                                             { date_from: from, date_to: to, grouping: groupings[i] }))
                .catch(function () { return null; })
                .then(function (d) {
                    var rows = d ? flatten(d, shopId) : [];
                    return rows.length ? rows : attempt(i + 1);
                });
        }(0));
    };

    /** Lignes de plusieurs boutiques, en parallèle, concaténées. */
    ProductMix.prototype.forShops = function (shopIds, from, to) {
        var self = this;
        return Promise.all(shopIds.map(function (id) { return self.forShop(id, from, to); }))
            .then(function (lists) { return [].concat.apply([], lists); });
    };

    /** Liste des secteurs (jamais codée en dur côté client). */
    ProductMix.prototype.sectors = function () {
        return Promise.resolve(this._get('/consultant/product-sectors', {}))
            .catch(function () { return null; })
            .then(function (d) {
                if (!d) return [];
                ['sectors', 'items', 'list', 'data'].some(function (k) {
                    if (d && d[k] && typeof d[k] === 'object') { d = d[k]; return true; }
                    return false;
                });
                if (!Array.isArray(d)) return [];
                return d.map(function (s) {
                    var name = null;
                    ['name', 'label', 'sector_name', 'title'].some(function (k) {
                        if (typeof s[k] === 'string' && s[k].trim() !== '') { name = s[k].trim(); return true; }
                        return false;
                    });
                    return name ? { id: s.id !== undefined ? s.id : (s.sector_id !== undefined ? s.sector_id : null), name: name } : null;
                }).filter(Boolean);
            });
    };

    ProductMix.LEVELS  = LEVELS;
    ProductMix.flatten = flatten;
    ProductMix.rollup  = rollup;
    ProductMix.tree    = tree;
    ProductMix.table   = table;

    return ProductMix;
}));
