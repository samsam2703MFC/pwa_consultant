# Food Cost — module portable (tables + API + calcul)

Tout ce qu'il faut pour recalculer le **food cost** (coût matière) et la **marge
brute** d'une boutique dans un autre projet, sans dépendre du framework de ce
dépôt. Trois fichiers à copier :

| Fichier | Rôle |
|---|---|
| `food_cost_tables.sql` | les tables SQL (seuils de couleur, snapshot P&L mensuel, casse/invendus) |
| `FoodCost.php` | le calcul côté serveur (PHP 8.1+, zéro dépendance — on lui injecte un `callable` HTTP) |
| `food-cost.js` | le même calcul côté navigateur (ES5/ES6, zéro dépendance) |

> **Point important avant de coller quoi que ce soit :** le food cost n'est
> **pas stocké** dans ce projet. Il est *dérivé* à chaque affichage depuis
> l'API métier. Les tables ci-dessous ne servent qu'à l'habillage (seuils de
> couleur) et à l'historique mensuel. Si le projet cible veut un food cost
> historisé, la colonne `material` de `mac_shop_monthly_pnl` est prévue pour
> ça (voir §4).

---

## 1. La formule

```
food cost %   = coût matière ÷ CA × 100
marge brute % = 100 − food cost %
```

Le coût matière se récupère par **trois sources, dans cet ordre**. La première
qui rend un total > 0 gagne — c'est ce qui garantit le même chiffre sur tous
les écrans.

| # | Source | Endpoint | Remarque |
|---|---|---|---|
| 1 | Coûts matière par **catégorie de produits** | `GET /shops/{id}/statistics/sales/product-category-groups?date_from&date_to&grouping=` | essayé avec `grouping=category`, puis `group`, puis `month` |
| 2 | **Résumé quotidien** | `GET /shops/{id}/statistics/daily-summary?date_from&date_to` | repli |
| 3 | **Résidu P&L** `F = T − L − OC − R` | `GET /consultant/shops/{id}/pnl?period=day\|week\|month` | dernier repli, uniquement si 1 et 2 sont muettes |

Le résidu n'est un repli que parce que le champ `result` de l'API ne déduit pas
toujours la matière ; quand il ne la déduit pas, la marge sort autour de 40 %,
ce qu'aucune boulangerie ne fait. Les sources 1 et 2 donnent la matière
directement, sans cette hypothèse.

**Le piège du double comptage.** Les payloads des sources 1 et 2 exposent
souvent plusieurs clés de coût redondantes sur le même nœud (`material_cost`
*et* `total_cost`, par exemple) et des clés de ratio (`material_pct`). On prend
donc **une seule clé de coût par nœud nommé**, par ordre de préférence, en
sautant tout ce qui ressemble à un pourcentage ou à une quantité :

```
préférences : material_cost > materials_cost > food_cost > goods_cost
              > cost_of_goods > purchase_cost > total_cost > cost
ignorées    : *pct* *percent* *ratio* *rate* *delta* *margin* *qty* *quantity* *count*
```

C'est exactement ce qu'implémentent `FoodCost::sumMaterialCost()` et
`extractFoodTotal()` en JS. Ne pas simplifier cette partie : additionner deux
clés du même nœud double le food cost.

## 2. Les payloads attendus

**P&L d'une période** — `GET /consultant/shops/{id}/pnl?period=month`

```json
{
  "turnover": { "value": 42000.00 },
  "material": { "value": 13500.00 },
  "labour":   { "value": 11800.00 },
  "overhead": { "value":  9200.00 },
  "result":   { "value":  7500.00 },
  "date_from": "2026-07-01",
  "date_to":   "2026-07-31"
}
```

Chaque poste est toléré sous trois formes : `{"value": 123}`, `123`, ou
`"123"` (`FoodCost::pnlValue()` / `pnlValue()` en JS les couvrent toutes).

**P&L mensuel** — `GET /consultant/shops/{id}/pnl/monthly?from=2025-08&to=2026-07`

```json
{ "months": [ { "month": "2026-07", "turnover": 42000, "material": 13500,
                "labour": 11800, "overhead": 9200, "result": 7500 } ] }
```

**P&L quotidien** — `GET /consultant/shops/{id}/pnl/daily?from&to`

```json
{ "days": [ { "date": "2026-07-14", "revenue": 1580.20, "material": 505.10,
              "labour": 430.00, "overhead": 300.00, "result": 345.10 } ] }
```

`material` est **obligatoire** sur ces deux endpoints. `null` est une réponse
honnête quand la ligne n'existe pas pour le mois ; une ligne **silencieusement
absente** à côté d'un `result` d'apparence complète est ce qui casse le calcul.
C'est le contrat décrit dans `docs/BACKEND_SPEC.md` (§ T5) de ce dépôt.

**Catégories** — `GET /shops/{id}/statistics/sales/product-category-groups`

Structure libre, imbriquée. Le parseur descend l'arbre, retient les nœuds qui
portent un nom (`category_name`, `category`, `group_name`, `name`, `label`) et
somme une clé de coût par nœud.

## 3. Statut vs moyenne réseau

Le food cost est un KPI « plus bas = mieux » (`dir = -1`) :

```
score = dir × (valeur − moyenne_réseau) ÷ |moyenne_réseau| × 100
✓ bon      : score ≥ −5
⚠ attention: −15 ≤ score < −5
● danger   : score < −15
```

La marge brute utilise la même fonction avec `dir = +1`. Les deux seuils sont
des constantes (`THRESHOLD_GOOD` / `THRESHOLD_DANGER`) — dans ce dépôt elles
vivent dans `LeversController`.

Pour la **couleur** d'une marge brute, ne rien coder en dur : les bandes sont
en base (`mac_kpi_threshold`, métrique `gross_margin`). La bande retenue est
celle qui a le plus grand `min_pct ≤ valeur`.

## 4. Les tables

`food_cost_tables.sql` contient :

- **`mac_kpi_threshold`** — bandes de couleur `gross_margin` (et `net_margin`,
  fournie parce que les deux échelles vivent dans la même table). Modifiable
  sans redéploiement.
- **`mac_shop_monthly_pnl`** — snapshot mensuel par boutique. **Avec la colonne
  `material`**, absente de la version de ce dépôt : c'est elle qui permet
  d'historiser le food cost mois par mois (`material ÷ ca`) au lieu de le
  recalculer via l'API à chaque affichage. L'API n'expose le détail que pour le
  mois courant — sans snapshot, pas d'historique.
- **`waste_entry`** — casse et invendus (€/jour/boutique). Saisie boutique, pas
  encore branchée ici ; c'est le troisième KPI du levier Food Cost. Le food
  cost « corrigé » se calcule alors `(matière + casse) ÷ CA`.

Le préfixe `mac_` est celui du projet ; renommer librement dans le projet cible
(les deux fichiers de code ne touchent à aucune table — ils ne font que du
calcul et des appels HTTP).

## 5. Brancher `FoodCost.php`

Le constructeur prend un `callable` qui exécute un GET sur l'API métier et
rend le tableau `data` décodé (ou `null`). Aucune autre dépendance.

```php
require 'FoodCost.php';

$http = function (string $path, array $query = []): ?array {
    $url = 'https://api.exemple.tld' . $path . ($query ? '?' . http_build_query($query) : '');
    $ctx = stream_context_create(['http' => ['header' => "Authorization: Bearer $jwt\r\n", 'timeout' => 10]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j['data'] ?? null) ? $j['data'] : (is_array($j) ? $j : null);
};

$fc = new FoodCost($http);

// Sur une fenêtre libre, avec le CA déjà connu :
$r = $fc->forWindow(12, '2026-07-01', '2026-07-31', 42000.0);
// => ['material' => 13500.0, 'ca' => 42000.0, 'food_pct' => 32.14,
//     'gross_pct' => 67.86, 'source' => 'category']

// Sur une période P&L (le CA et la fenêtre viennent du P&L lui-même) :
$r = $fc->forPeriod(12, 'month');

// Historique mensuel (pour remplir mac_shop_monthly_pnl.material) :
foreach ($fc->monthlySeries(12, '2025-08', '2026-07') as $ym => $m) {
    // $m = ['turnover','material','labour','overhead','result','food_pct','gross_pct']
}

// Statut vs moyenne réseau :
FoodCost::status($r['food_pct'], $networkAvgFoodPct, -1);   // 'good' | 'warn' | 'danger'
```

`source` dit toujours d'où vient le chiffre : `category`, `daily_summary`,
`pnl_residual` ou `none`. À afficher en tooltip — c'est ce qui évite les
« pourquoi ce chiffre a changé ? ».

## 6. Brancher `food-cost.js`

```html
<script src="food-cost.js"></script>
<script>
  // fetcher(path, params) -> Promise<objet data | null>
  const fc = new FoodCost((path, params) =>
      fetch('/api-proxy?endpoint=' + encodeURIComponent(path) + '&' + new URLSearchParams(params),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(j => (j && j.data) || null).catch(() => null));

  fc.forPeriod(12, 'month').then(r => {
      console.log(r.food_pct, r.gross_pct, r.source);
  });
</script>
```

Le cache par fenêtre (`shopId|from|to`) est intégré : deux écrans qui demandent
la même fenêtre ne déclenchent qu'un appel.

## 7. Où ça vit dans ce dépôt (pour retrouver l'original)

| Élément | Fichier |
|---|---|
| Somme du coût matière (PHP) | `src/app/Repositories/Shop/ShopRepository.php` → `getMaterialCost()`, `sumMaterialCost()` |
| P&L quotidien / mensuel | même fichier → `getDailyPnl()`, `getMonthlyPnl()`, `parseDailyPnl()`, `parseMonthlyPnl()` |
| food_pct / gross_pct du rapport | `src/app/Services/Report/ReportService.php` → `hexmMetrics()` |
| Marge nette recalculée (CA − matière − MO − frais) | `src/app/Services/Valuation/ValuationService.php` → `netResult()` |
| Seuils de statut | `src/app/Http/Controllers/Levers/LeversController.php` |
| Calcul côté client | `src/app/Views/levers/index.twig` → `extractFoodTotal()`, `fetchCatFoodTotal()`, `loadShop()` |
| Contrat backend (`material` obligatoire) | `docs/BACKEND_SPEC.md`, `docs/BACKEND_NEW_ENDPOINTS.md` |
| Plan de données des 6 leviers | `docs/SIXL_DATA.md` |
