# Champ « adresse Google » sur la table shop commune

## Objectif
Fiabiliser la recherche de la fiche Google de chaque magasin (KPI « Note
Google » du levier Trafic, page HEXm). Par défaut la PWA cherche la fiche
par nom + ville, ce qui peut tomber sur la mauvaise fiche. Un champ dédié
sur la table `shops` lève toute ambiguïté.

## Migration à exécuter côté back-office (Szymon)
La table `shops` est la table COMMUNE, propriété du back-office — la PWA la
lit via l'API `GET /consultant/shops`. La migration doit donc être faite
côté back-office, puis les deux colonnes exposées dans la réponse de cet
endpoint.

```sql
ALTER TABLE shops
    ADD COLUMN google_address  VARCHAR(255) NULL COMMENT 'Adresse exacte de la fiche Google Business',
    ADD COLUMN google_place_id VARCHAR(128) NULL COMMENT 'Place ID Google (optionnel, le plus fiable)';
```

- `google_address` : l'adresse telle qu'elle apparaît sur Google Maps
  (ex. « Chaussée de Bruxelles 123, 1470 Genappe »). Suffit dans 99 % des cas.
- `google_place_id` : optionnel ; si renseigné, court-circuite toute
  recherche (identifiant unique de la fiche, ex. `ChIJ...`).

## Côté PWA : déjà prêt
`ShopController::googleRatingEndpoint` lit ces champs dans la réponse
`/consultant/shops` de façon tolérante (clés `google_address` / `address` /
`formatted_address` et `google_place_id` / `place_id`). Dès que le
back-office renvoie ces colonnes, la note Google s'appuie dessus
automatiquement — aucun changement supplémentaire côté PWA.

Priorité de résolution de la fiche :
1. `place_ids[id]` de `config/google.local.php` (override manuel)
2. `google_place_id` du magasin
3. `google_address` du magasin
4. nom + ville (repli actuel)

## Solution immédiate sans attendre le back-office
En attendant la migration, on peut forcer chaque fiche dans
`config/google.local.php` sur le serveur :

```php
return [
    'places_key' => '…',
    'place_ids'  => [ 2 => 'ChIJ…', 3 => 'ChIJ…', 5 => 'ChIJ…' ],
];
```
