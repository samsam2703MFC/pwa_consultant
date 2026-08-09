# Images pour l'impression — traitement automatique (brochures 10 × 15 cm)

Un seul fichier : **`PrintImage.php`** (PHP 8.1+, GD requis). Utilisable comme
classe, ou directement en ligne de commande sur un dossier entier.

```bash
# Trier avant de traiter : qu'est-ce qui passera, qu'est-ce qui ne passera pas ?
php PrintImage.php --in=photos --size=10x15 --inspect

# Traiter tout le dossier
php PrintImage.php --in=photos --out=print --size=10x15 --dpi=300
```

```
⚠ juste-1000.jpg        1252×1843 px @ 300 dpi  (réel 240 dpi, limite)
    · Image agrandie de 1000 à 1252 px : l'agrandissement n'ajoute aucun détail.
    · Résolution juste : 240 dpi en 10 × 15 cm — acceptable pour un aplat ou une
      photo d'ambiance, visible sur un texte ou un détail fin.
✓ paysage-4000.jpg      1843×1252 px @ 300 dpi  (réel 651 dpi, ok)
⚠ petite-800.jpg        1843×1252 px @ 300 dpi  (réel 130 dpi, insuffisant)
    · Résolution insuffisante : 130 dpi en 15 × 10 cm, il en faut 150 au
      minimum. Demandez le fichier d'origine.
✓ portrait-1600.jpg     1252×1843 px @ 300 dpi  (réel 383 dpi, ok)

4 traitée(s), 0 en échec, 2 à vérifier avant le bon à tirer.
```

---

## 1. Le calcul qui décide de tout

Pour le web, ce qui compte est le poids du fichier. Pour l'impression, c'est le
nombre de **pixels par centimètre**. Une image de 800 px de large est
confortable à l'écran et floue dès 7 cm de papier.

```
pixels = cm ÷ 2,54 × dpi
```

Pour un 10 × 15 cm :

| Résolution | Sans fond perdu | Avec 3 mm de fond perdu | Verdict |
|---|---|---|---|
| 300 dpi | 1 182 × 1 772 px | **1 252 × 1 843 px** | qualité offset |
| 240 dpi | 945 × 1 417 px | 1 002 × 1 474 px | acceptable |
| 150 dpi | 591 × 886 px | 626 × 921 px | plancher absolu |
| 96 dpi | 378 × 567 px | — | qualité écran, refusée |

Une photo de téléphone récent (4 000 × 3 000 px) couvre un 10 × 15 à plus de
600 dpi : elle passe largement. Une image récupérée sur un site web
(800 × 600 px) ne passe pas, et aucun traitement ne la fera passer.

## 2. Ce que le traitement fait, dans l'ordre

1. **Lit l'image, orientation EXIF appliquée** — sans ça les photos prises à la
   verticale sortent couchées.
2. **Choisit portrait ou paysage** (`orientation: auto`) : celui des deux qui
   recadre le moins. Forcer le portrait sur une photo paysage jette 60 % de
   l'image sans que personne l'ait demandé.
3. **Recadre autour du point d'intérêt** — le même `focus_x` / `focus_y` que le
   formulaire campagne (`README-CAMPAGNE.md`). Une seule saisie sert l'écran et
   le papier.
4. **Rééchantillonne** à la taille exacte, fond perdu compris.
5. **Ré-accentue** légèrement quand la réduction dépasse ~15 % : toute
   réduction ramollit une image, et ça se voit sur papier.
6. **Écrit la résolution dans le fichier** (segment JFIF).
7. **Rend un verdict** : la résolution réellement obtenue et si elle suffit.

### Le verdict est le point important

Agrandir n'invente aucun détail : une source de 800 px étirée à 1 843 px sera
floue à l'impression. Le traitement produit quand même le fichier — il est
parfois le seul disponible — mais il le dit, **avant** le bon à tirer :

| Verdict | Condition | Ce que ça veut dire |
|---|---|---|
| `ok` | ≥ 300 dpi | rien à signaler |
| `limite` | 150–300 dpi | passe sur un aplat ou une photo d'ambiance ; visible sur un texte ou un détail fin |
| `insuffisant` | < 150 dpi | demandez le fichier d'origine |

`allow_upscale => false` transforme `insuffisant` en échec franc, si vous
préférez qu'aucun fichier douteux ne sorte de la chaîne.

## 3. La résolution inscrite dans le fichier

GD écrit toujours **96 dpi** dans l'en-tête JFIF, quelle que soit l'image.
Conséquence : un fichier de 1 252 px arrive dans InDesign ou Word comme un objet
de **33 cm** de large, qu'il faut redimensionner à la main — et le jour où
personne ne le fait, l'image part à l'impression en 33 cm à 96 dpi.

`PrintImage::setJpegDpi()` corrige l'en-tête après écriture (`units = 1`,
densités en big-endian aux octets 13–17). Le fichier s'importe alors
directement à 10 × 15 cm.

```php
PrintImage::jpegDpi('photo_print.jpg');   // 300
```

Le PNG ne porte cette information que dans un chunk optionnel (`pHYs`) que peu
d'outils lisent : en PNG, le traitement le signale et vous indiquez le format à
l'import.

## 4. Fond perdu et marge de sécurité

- **Fond perdu** (3 mm par défaut) : l'image déborde du format fini, parce
  qu'un massicot ne coupe jamais au dixième de millimètre. Sans lui, un liseré
  blanc apparaît sur le bord. `--bleed=0` pour une image qui ne va pas à bord
  perdu.
- **Marge de sécurité** (4 mm) : la zone intérieure où rien d'important ne doit
  se trouver. Le traitement ne la dessine pas, il la rend en pixels
  (`safe_px`) pour que la maquette la place.

Le retour donne les trois formats : `trim_px` (fini, après massicot),
`width`/`height` (fichier livré, fond perdu compris) et `bleed_px`.

## 5. En code

```php
require 'PrintImage.php';

$pi = new PrintImage([
    'dpi'      => 300,
    'min_dpi'  => 150,
    'bleed_mm' => 3,
]);

// Ce qu'une image peut donner, sans rien écrire
$i = $pi->inspect('photo.jpg', 10, 15);
// ['max_dpi' => 651.3, 'verdict' => 'ok', 'orientation' => 'paysage',
//  'max_cm' => [33.9, 25.4], 'message' => 'Résolution suffisante : 651 dpi en 10 × 15 cm.']

// Traitement d'une image, avec le point d'intérêt de la campagne
$r = $pi->process('photo.jpg', 'print/photo_print.jpg', [
    'width_cm' => 10, 'height_cm' => 15,
    'focus'    => [$row['illustration_focus_x'], $row['illustration_focus_y']],
    'fit'      => 'cover',        // 'contain' pour un packshot entier sur fond uni
]);

// Un dossier entier
$b = $pi->batch('photos', 'print', ['width_cm' => 10, 'height_cm' => 15]);
// ['done' => 12, 'failed' => 0, 'results' => [...]]
```

`inspect()` rend aussi `max_cm` : le plus grand format imprimable à 300 dpi avec
les pixels disponibles. Utile pour répondre « cette photo, on peut la monter
jusqu'où ? » sans essayer.

### `cover` ou `contain`

- **`cover`** (défaut) : l'image remplit le format, l'excédent est rogné. Pour
  une photo. Au-delà de 35 % de perte, un message le signale — c'est le moment
  de revoir le point d'intérêt.
- **`contain`** : l'image entière tient dans le format, le reste est du fond
  uni (`background`, blanc par défaut). Pour un packshot ou un logo. Le fond
  perdu est alors du fond, pas de l'image — dit explicitement dans les
  messages. Le verdict porte sur la taille **réellement occupée** par l'image,
  pas sur la page : une image qui s'imprime en 10 × 8 cm ne doit pas être jugée
  sur 10 × 15.

## 6. Ce que ça ne fait pas — le CMJN

GD travaille en RVB. Les fichiers produits sont donc en **RVB**, ce qui convient
à l'impression numérique et à la plupart des imprimeurs, qui convertissent avec
leur propre profil.

Pour un offset qui exige du CMJN, la conversion se fait avec un profil ICC
(FOGRA39 pour un couché européen) et **pas** par une conversion naïve : sans
profil, les noirs virent et les couleurs saturées se ternissent. Deux voies :

```bash
# ImageMagick, avec les profils
convert photo_print.jpg -profile sRGB.icc -profile CoatedFOGRA39.icc \
        -colorspace CMYK photo_print_cmyk.tif
```

… ou laisser l'imprimeur le faire, ce qui est le choix par défaut et le plus
sûr. Une source **déjà** en CMJN est détectée et refusée (`cmyk_source`) plutôt
que lue de travers par GD — une image aux couleurs inversées sans message vaut
moins qu'une erreur claire.

## 7. Options de la ligne de commande

| Option | Défaut | |
|---|---|---|
| `--in=` | — | fichier ou dossier |
| `--out=` | `<in>/print` | dossier de sortie |
| `--size=` | `10x15` | format fini en cm |
| `--dpi=` | `300` | résolution cible |
| `--bleed=` | `3` | fond perdu en mm (`0` = sans) |
| `--fit=` | `cover` | `cover` ou `contain` |
| `--format=` | `jpg` | `jpg` ou `png` |
| `--orientation=` | `auto` | `auto`, `portrait`, `paysage` |
| `--inspect` | — | analyse seulement, n'écrit rien |

Le code de sortie vaut `1` si au moins un fichier a échoué — de quoi enchaîner
dans un script sans lire la sortie.
