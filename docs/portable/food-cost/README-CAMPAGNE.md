# Nouvelle campagne — Identité & période, avec illustration

Le bloc « Identité & période » du formulaire de création de campagne, augmenté
d'une **photo ou illustration** : champ, stockage, validation, affichage.

| Fichier | Rôle |
|---|---|
| `campaign_tables.sql` | `campaign` (identité, période, illustration), `campaign_shop`, `campaign_asset` |
| `CampaignImage.php` | validation et stockage serveur (PHP 8.1+, GD facultatif) |
| `campaign-illustration.js` | le champ du formulaire : aperçu, glisser-déposer, redimensionnement, point d'intérêt |

Relié aux deux autres modules : une campagne regroupe les objectifs de
`product_target` (`README-PRODUITS.md`), et son tableau de suivi est celui de
`ProductMix::table()`.

---

## 1. Les champs

| Bloc | Champ | Colonne | Obligatoire |
|---|---|---|---|
| Identité | Nom | `name` | oui |
| | Code / slug | `code` | oui — stable, sert de clé externe |
| | Accroche | `subtitle` | non |
| | Description | `description` | non |
| Période | Début | `starts_on` | oui |
| | Fin | `ends_on` | oui — `CHECK (ends_on >= starts_on)` |
| | Statut | `status` | `draft` par défaut |
| Illustration | Fichier | `illustration_path` **ou** `illustration_attachment_id` | non |
| | Texte alternatif | `illustration_alt` | oui **dès qu'une image est là** |
| | Point d'intérêt | `illustration_focus_x/y` | par défaut 0,5 / 0,5 |

Deux choix qui méritent d'être conscients :

**Le `code` n'est pas le `name`.** Renommer « Galette 2026 » en « Galette des
Rois 2026 » ne doit pas casser les objectifs, les exports ni les liens déjà
partagés. Le code est saisi une fois, puis figé (ou dérivé du nom à la création
seulement).

**Le texte alternatif est obligatoire avec l'image, pas optionnel.** Une
illustration sans alternative est invisible pour un lecteur d'écran et pour
Google, et le champ ne sera jamais rempli plus tard. Il est à côté de l'image
dans le formulaire, pas replié dans un onglet « avancé ».

## 2. Le champ, côté formulaire

```html
<div id="illu"></div>
<script src="campaign-illustration.js"></script>
<script>
  CampaignIllustration.mount(document.getElementById('illu'), {
    name: 'illustration',
    // à l'édition : la valeur déjà enregistrée
    value: { url: '/uploads/campaigns/2026/01/ab12….jpg', alt: 'Galette dorée',
             focus_x: 0.5, focus_y: 0.35 },
    onChange: function (s) { /* s.file, s.alt, s.focus_x, s.focus_y, s.removed */ }
  });
</script>
```

Le formulaire poste alors, en `multipart/form-data` :

| Champ | Contenu |
|---|---|
| `illustration` | le fichier, **déjà redimensionné** à 2 000 px de large |
| `illustration_alt` | le texte alternatif |
| `illustration_focus_x` / `_y` | le point d'intérêt, 0..1 |
| `illustration_remove` | `1` quand l'utilisateur a retiré l'image existante |

Ce que le champ fait, et pourquoi :

- **Aperçu immédiat.** Personne ne devrait envoyer un visuel sans l'avoir vu.
- **Redimensionnement avant envoi.** Une photo de téléphone fait 8 Mo et
  4 000 px ; réduite à 2 000 px elle passe sous le mégaoctet. C'est ce qui fait
  la différence entre un upload qui aboutit et un upload qui expire sur la
  connexion d'une boutique. Une image déjà petite n'est **pas** ré-encodée —
  ce serait perdre en qualité pour rien.
- **Orientation EXIF respectée**, sinon les photos prises à la verticale
  arrivent couchées.
- **Point d'intérêt cliquable.** Le même visuel sert en bandeau 3:1 et en
  vignette carrée ; sans ce point, le recadrage automatique coupe les têtes.
  À l'affichage : `object-fit: cover; object-position: <focus_x>% <focus_y>%`
  (`CampaignImage::focusCss()` rend la valeur toute faite).
- **Glisser-déposer et collage** (Ctrl+V) : un visuel arrive souvent du
  presse-papier, pas d'un dossier.

Le contrôle client ne remplace pas la validation serveur — il rend l'erreur
immédiate et l'envoi plus léger, c'est tout.

## 3. Le stockage, côté serveur

```php
require 'CampaignImage.php';

$img = new CampaignImage([
    'dir'         => '/var/app/storage/campaigns',   // HORS webroot, voir §4
    'public_base' => '/uploads/campaigns',           // '' si servi par une route PHP
    'max_bytes'   => 5 * 1024 * 1024,
    'min_width'   => 600,
]);

$r = $img->store($_FILES['illustration']);
if (!$r['ok']) {
    $erreur = CampaignImage::errorMessage($r['error']);   // message prêt à afficher
} else {
    // → campaign.illustration_path / _mime / _bytes / _width / _height
    $img->thumbnail($r['path'], 480);                     // vignette carrée (GD)
}
```

`validate()` fait les mêmes contrôles **sans écrire** : à appeler pour afficher
une erreur de formulaire avant de toucher au disque.

Codes d'erreur rendus : `no_file`, `too_large`, `too_large_server_limit`,
`not_an_image`, `unsupported_type`, `too_many_pixels`, `too_small`, `empty`,
`not_an_upload`, `unreadable`, `mkdir_failed`, `move_failed`.

> `too_large_server_limit` est distinct de `too_large` pour une raison précise :
> il signale un `upload_max_filesize` / `post_max_size` PHP plus bas que la
> limite annoncée dans le formulaire. Confondu avec `too_large`, ce cas envoie
> l'utilisateur réduire une image qui respectait déjà la limite affichée.

### Variante A — le fichier vit dans l'API

Si l'API porte déjà les pièces jointes (`POST` multipart, puis
`GET /attachments/{id}/presigned-url`), préférez-la : rien à sauvegarder, rien
à servir, et le visuel suit la campagne partout. On stocke alors
`illustration_attachment_id` et **pas** `illustration_path` — jamais les deux,
sinon deux visuels concurrents et plus personne ne sait lequel s'affiche.

## 4. Ce qu'il ne faut pas rater sur un champ d'upload

Un champ « photo » est un point d'entrée. Ce qui arrive est un fichier choisi
par un utilisateur, dont ni le nom, ni l'extension, ni le type MIME annoncé ne
sont dignes de confiance. `CampaignImage` applique quatre règles ; elles ne
valent que si le déploiement tient la quatrième.

1. **Le type se déduit du contenu** (`getimagesize`), jamais de l'extension ni
   du `type` envoyé par le navigateur — les deux se falsifient à la main. Un
   `<?php … ?>` renommé en `.jpg` est refusé (`not_an_image`).
2. **Le nom de fichier est regénéré** : `aaaa/mm/<32 hexa>.ext`. Un nom fourni
   par le client porte des `../`, des octets nuls, ou une double extension
   `.jpg.php`. Le rangement par mois évite aussi un dossier à 200 000 entrées.
3. **Les dimensions sont bornées avant tout traitement.** Une image de
   64 000 × 64 000 pèse 12 ko sur le disque et 12 Go une fois décompressée en
   mémoire — c'est un déni de service à un fichier.
4. **Le dossier de destination ne doit pas exécuter de PHP.** Hors webroot, ou
   servi par un `location` nginx qui ne passe rien à FPM (ou
   `php_admin_flag engine off` sous Apache). Sans ça, la règle 2 est la seule
   chose qui vous sépare d'un webshell.

**SVG est refusé par défaut.** C'est un document XML : il exécute du script
dans le navigateur qui l'affiche. L'option `allow_svg` existe, mais ne
l'activez que si le fichier est servi en pièce jointe
(`Content-Disposition: attachment`) ou depuis un domaine séparé — jamais rendu
inline sur le domaine de l'application.

## 5. Afficher le visuel

```html
<div class="campaign-hero">
  <img src="{{ url }}" alt="{{ illustration_alt }}"
       style="object-fit:cover;object-position:{{ focusCss }}">
</div>
```

```php
$focusCss = CampaignImage::focusCss($row['illustration_focus_x'], $row['illustration_focus_y']);
```

La vignette carrée sert les listes ; le bandeau réutilise l'original avec le
même `object-position`. Pas de vignette (GD absent) n'est pas bloquant :
`object-fit: cover` sur l'original donne le même rendu, au poids près.

## 6. Rattacher la campagne à ses objectifs

`product_target` porte déjà `period_from` / `period_to`. Rapprocher par dates
casse dès que deux campagnes se chevauchent : ajoutez la clé.

```sql
ALTER TABLE product_target
  ADD COLUMN id_campaign BIGINT UNSIGNED NULL AFTER id_shop,
  ADD CONSTRAINT fk_target_campaign FOREIGN KEY (id_campaign)
      REFERENCES campaign (id) ON DELETE SET NULL;
```

`ON DELETE SET NULL`, pas `CASCADE` : supprimer une campagne ne doit effacer ni
les objectifs saisis, ni le réalisé qui s'y rapporte.

Le tableau de suivi est alors celui de `README-PRODUITS.md`, alimenté par :

```sql
SELECT t.ref_key, t.id_shop, t.qty_target
  FROM product_target t
  JOIN campaign c ON c.id = t.id_campaign
 WHERE c.code = 'galette-2026' AND t.level = 'product';
```
