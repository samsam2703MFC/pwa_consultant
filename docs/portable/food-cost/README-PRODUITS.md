# Mix produits & catégories — module portable

Le tableau « produits × boutiques » : une ligne par produit, une colonne par
boutique, `Total réseau`, `Total période`, `Objectif (pièces)` et `Progression`
— **et les mêmes lignes agrégées à n'importe quel niveau de catégorie**.

| Fichier | Rôle |
|---|---|
| `ProductMix.php` | lecture API, mise à plat, hiérarchie, pivot, objectifs (PHP 8.1+, zéro dépendance) |
| `product-mix.js` | le même calcul côté navigateur |
| `product_tables.sql` | secteurs, rattachement local, objectifs en pièces, historique |

Compagnon de `README.md` (food cost) : **même endpoint**, même fetcher injecté,
même discipline d'extraction — une seule clé de valeur par nœud.

---

## 1. Les niveaux de catégorie

Quatre niveaux, du plus large au plus fin :

```
sector  (catégorie 1)  →  group  (catégorie 2)  →  category  (catégorie 3)  →  product
Boulangerie            →  Pains                 →  Pains spéciaux           →  Pain aux céréales
Traiteur               →  Froid                 →  Salades                  →  Salade César
```

Le parseur reconnaît chaque niveau **par clé explicite** d'abord :

| Niveau | Clés reconnues | Id |
|---|---|---|
| `sector` | `sector_name`, `sector`, `univers`, `universe`, `category_1`, `category1`, `level_1` | `sector_id`, `category_1_id` |
| `group` | `group_name`, `group`, `family_name`, `family`, `category_2`, `category2`, `level_2` | `group_id`, `family_id`, `category_2_id` |
| `category` | `category_name`, `category`, `sub_category`, `subcategory`, `category_3`, `category3`, `level_3` | `category_id`, `sub_category_id`, `category_3_id` |
| `product` | `product_name`, `product`, `article_name`, `article`, `item_name`, `item` | `product_id`, `article_id`, `item_id` |

À défaut de clé explicite, il retombe sur la **position** : le chemin des nœuds
nommés traversés fait la hiérarchie. C'est ce qui le rend robuste à une
profondeur qui change d'un `grouping` à l'autre. Si le payload cible nomme ses
niveaux autrement, une ligne à ajouter dans `LEVEL_KEYS` suffit — même tableau
en PHP et en JS.

**Un niveau vide n'est jamais escamoté** : un produit sans secteur atterrit
sous `(sector non renseigné)`. Le faire disparaître d'une ventilation est la
seule issue à éviter — le total ne se recompose plus et personne ne le voit.

**Les nœuds intermédiaires n'émettent pas de ligne.** Une catégorie porte
souvent ses propres totaux à côté de ses produits ; compter les deux doublerait
le tableau. `flatten()` n'émet que les feuilles produit, et `rollup()`
reconstruit les totaux de catégorie par somme.

## 2. Le tableau, en trois appels

```php
require 'ProductMix.php';

$pm   = new ProductMix($http);                 // même $http que FoodCost
$rows = $pm->forShops([1, 2, 3, 4, 5], '2026-01-01', '2026-01-31');

$table = ProductMix::table($rows, [
    'level'   => 'product',                    // ou 'category' | 'group' | 'sector'
    'shops'   => [1 => 'Halle', 2 => 'Corbais', 3 => 'Gembloux',
                  4 => 'Sombreffe', 5 => 'Gosselies'],
    'tickets' => [1 => 19774, 2 => 100760, 3 => 0, 4 => 51034, 5 => 45846],
    'targets' => [                             // objectifs par ligne et par boutique
        'product:1043' => [1 => 500, 2 => 2000, 4 => 1000, 5 => 1500],
    ],
    'total_targets' => [1 => 500, 2 => 2000, 3 => 0, 4 => 1000, 5 => 1500],
    'pct_mode' => 'penetration',
]);
```

`$table` :

```php
[
  'level' => 'product', 'pct_mode' => 'penetration',
  'columns' => [ ['shop_id'=>1,'name'=>'Halle','tickets'=>19774], … ],
  'rows' => [
    [ 'key' => 'product:1043', 'label' => 'Galette Frangipane',
      'path' => ['sector'=>'Boulangerie','group'=>'Pains','category'=>'Galettes'],
      'cells' => [ 2 => ['qty'=>1586,'ca'=>…,'pct'=>1.57,'target'=>2000,'progression'=>79.3], … ],
      'total' => ['qty'=>2999,'ca'=>…,'pct'=>1.38],
      'material_cost' => …, 'food_pct' => …,     // le food cost de la ligne, gratuit
      'target' => 5000, 'progression' => 60.0 ],
    …
  ],
  'total' => [ 'label' => 'Total période', 'cells' => […], 'total' => […],
               'target' => 5000, 'progression' => 71.7, 'tickets' => 217414 ],
]
```

Changer `level` suffit à passer de « une ligne par produit » à « une ligne par
catégorie / groupe / secteur » : mêmes colonnes, mêmes totaux, mêmes objectifs.
Pour un tableau **dépliable**, `ProductMix::tree($rows)` rend l'arbre complet,
chaque nœud portant ses totaux et son détail par boutique.

En JS, à l'identique :

```js
const pm = new ProductMix(fetcher);
pm.forShops([1,2,3,4,5], from, to)
  .then(rows => ProductMix.table(rows, { level: 'category', shops, tickets, targets }));
```

## 3. Le « % » sous chaque quantité — à choisir

`pct_mode` décide de ce que le pourcentage mesure. Quatre valeurs :

| `pct_mode` | Formule | Ce que ça dit |
|---|---|---|
| `penetration` *(défaut)* | pièces ÷ **tickets de la boutique** × 100 | part des tickets qui emportent l'article |
| `mix` | pièces ÷ **total pièces de la boutique** × 100 | poids de l'article dans le mix de cette boutique |
| `share` | pièces ÷ **total pièces réseau** × 100 | part de la boutique dans le réseau |
| `none` | — | pas de pourcentage |

> **À vérifier sur votre tableau.** Dans l'extrait que vous m'avez passé, les
> pourcentages ne se recomposent avec aucun dénominateur unique : Corbais
> affiche 1 586 pièces → 1,2 % alors que 1 586 ÷ 100 760 tickets = 1,57 %, et
> deux lignes de la même colonne (Gosselies : 570 → 0,4 % et 746 → 1,4 %)
> impliquent des dénominateurs différents. Deux causes possibles : le compteur
> de tickets de l'en-tête couvre une **fenêtre plus large** que les lignes
> (année glissante contre période de campagne), ou le pourcentage n'est pas une
> pénétration. Dites-moi lequel des quatre modes est le bon — c'est un mot à
> changer.
>
> C'est aussi pour ça que `product_target_snapshot.tickets` existe : un taux de
> pénétration n'a de sens que si les pièces et les tickets couvrent la **même**
> fenêtre.

## 4. Les objectifs (`Objectif (pièces)` / `Progression`)

Un objectif se pose à n'importe quel niveau — un produit, une catégorie, un
secteur, ou le total de la période. D'où `product_target(level, ref_key)` plutôt
qu'une colonne `product_id` : sinon il faut une table par niveau.

`ref_key` est **exactement** la clé rendue par `rollup()` / `table()` :
`product:1043` quand l'API donne un id, `product#galette frangipane` sinon. Le
rapprochement objectif ↔ ligne est donc direct :

```sql
SELECT ref_key, id_shop, qty_target
  FROM product_target
 WHERE level = 'product' AND period_from = ? AND period_to = ?;
```

→ `$targets[ref_key][id_shop]`, la forme attendue par `table()`.

`progression = pièces ÷ objectif × 100`, calculée par cellule, par ligne (somme
des objectifs boutique) et sur le `Total période`. **Ne pas stocker un objectif
réseau** à côté des objectifs boutique : les deux divergent au premier ajout de
boutique, et plus rien ne dit lequel fait foi — le réseau se somme.

## 5. Le secteur, et pourquoi il n'est pas dans les payloads

Le back-office expose aujourd'hui la catégorie, pas le secteur. La catégorie est
le bon niveau pour un rayon, trop fin pour une conversation avec un franchisé :
« votre traiteur est en baisse de 8 % » est actionnable, « votre catégorie 47 est
en baisse de 8 % » ne l'est pas.

Trois choses à demander au back-office (c'est le ticket T10 de `BACKEND_SPEC.md`) :

1. **La liste des secteurs comme donnée** — `GET /consultant/product-sectors`,
   pas seulement une liste déroulante dans le formulaire produit. Codée en dur
   côté client, elle dérive au premier renommage. → `ProductMix::sectors()`.
2. **Le secteur qui voyage avec le produit**, sur tous les payloads de vente,
   exactement comme la catégorie aujourd'hui.
3. **Une décision sur le catalogue existant** : backfill, ou `sector_id` nullable
   assumé et affiché « secteur non renseigné ».

En attendant, `product_hierarchy_override` rattache localement un produit ou une
catégorie à un secteur. `ProductMix` lit le secteur du payload en priorité ;
l'override ne sert qu'aux produits qui n'en portent pas.

Même logique pour `is_pdm` : jamais `null`, jamais absent — un produit est PDM
ou il ne l'est pas. Filtrer le champ hors du payload pour économiser des octets
transforme « pas PDM » et « inconnu » en la même chose. `flatten()` rend
`is_pdm: null` quand la clé manque, précisément pour que ça se voie.

## 6. Ce que ça partage avec le food cost

Le même endpoint sert les deux : `ProductMix` en tire les quantités **et** le
coût matière, donc chaque ligne et chaque catégorie porte son `food_pct` sans
un appel de plus. Un tableau « catégories × boutiques » avec la marge brute par
catégorie ne coûte rien de plus que le tableau des ventes.

La règle d'extraction est commune aux deux fichiers : **une seule clé par nœud**,
par ordre de préférence, ratios et quantités exclus. `material_cost` et
`total_cost` cohabitent souvent sur le même nœud — les additionner double le
food cost.
