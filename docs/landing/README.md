# Captures pour la fiche landing

La landing publique (`samsam2703MFC/landing_tfb`) ne fabrique pas d'images :
elle reprend celles que ce dépôt publie **dans ce dossier**, au déploiement
suivant. Tant qu'il est vide, la fiche « Panel consultant » n'a rien à montrer.

## Le nom du fichier fait le rattachement

`consultant-<clé>.png` — la clé est celle de la fonction dans
`.tfb/module.json`. `consultant-checklists.png` se range donc sous « Checklists
notées ». Un nom qui ne correspond à aucune clé reste rattaché au module entier,
sans erreur mais sans précision.

Huit sont publiées. Quatre écrans — `dashboard`, `shops`, `sixl`, `targets`,
`trends`, `rapports` — étalent le compte de résultat d'un réseau identifiable :
ils sont marqués `sensible` dans le plan et ne sortent qu'avec `--sensibles`,
depuis une instance de démonstration.

Les attendues :

| Fichier | Écran |
|---|---|
| `consultant-agenda.png` | `/agenda` — Agenda des visites |
| `consultant-checklists.png` | `/checklists` — Checklists notées |
| `consultant-notes.png` | `/notes` — Notes de terrain |
| `consultant-helpdesk.png` | `/helpdesk` — Demandes et tickets |
| `consultant-tasks.png` | `/tasks` — Tâches du consultant |
| `consultant-claims.png` | `/claims` — Réclamations matériel |
| `consultant-dashboard.png` | `/dashboard` — Aperçu du jour · **sensible** |
| `consultant-shops.png` | `/shops` — Boutiques et compte de résultat · **sensible** |
| `consultant-sixl.png` | `/levers` — HEXm, les six leviers · **sensible** |
| `consultant-targets.png` | `/targets` — Objectifs du franchisé · **sensible** |
| `consultant-trends.png` | `/trends` — Tendances sur douze mois · **sensible** |
| `consultant-rapports.png` | `/reports` — Comptes rendus PDF · **sensible** |

## L'anonymisation

Juste avant chaque déclic, le script retire du rendu ce qui identifie le
réseau : le logo du client devient « RESEAU DEMO », et chaque point de vente
un pseudonyme stable — « Atelier by Berlo - Corbais » est « Boutique 1 » sur
toutes les captures, sinon on ne pourrait plus suivre une boutique d'un écran
à l'autre. Les noms de villes deviennent « Ville ». Les infobulles et les
textes de graphiques sont traités comme le reste.

Les chiffres, eux, ne sont pas touchés : une capture produit qui invente ses
montants est un mensonge. C'est pour ça que les écrans chiffrés sont écartés
tant que la source n'est pas une instance de démonstration.

`--sans-anonymat` lève la règle, à n'utiliser que sur des données fictives.

## Les produire — automatique

**Actions → captures-landing → Run workflow.** Le workflow ouvre une session
sur l'instance, prend les douze écrans en 1194 × 834 densité 2, et les commite
ici s'ils ont changé. Le push déclenche `notify-landing`, qui demande à la
landing de resynchroniser. Il repasse aussi tout seul le 1er de chaque mois.

Trois secrets à renseigner une fois, dans Settings → Secrets and variables →
Actions :

| Secret | Valeur |
|---|---|
| `CAPTURE_BASE` | `https://185.180.206.46/pwa_consultant` |
| `CAPTURE_USER` | le téléphone d'un compte consultant |
| `CAPTURE_PASS` | son mot de passe |

Prenez un compte qui voit plusieurs boutiques : les écrans seront remplis
plutôt que vides, et c'est ce qui fait la différence sur la fiche.

Tant que `CAPTURE_BASE` est vide, le workflow ne fait rien et reste vert.

## Les produire — à la main

Le même script, depuis un poste qui atteint l'instance :

```bash
npm i -D playwright && npx playwright install chromium

 CAPTURE_USER='0600000000' CAPTURE_PASS='…' \
 node tools/capturer-ecrans.mjs \
   --module=consultant \
   --base=https://<serveur>/pwa_consultant
```

Les images sont écrites directement dans `docs/landing/`. L'authentification
passe par l'API (`/consultant/auth/login`) : sans session, tous les écrans sauf
`/auth` renvoient vers la connexion, et le script le dit plutôt que
d'enregistrer douze pages de login.

`--attente=5000` si les graphiques sortent vides — c'est le temps laissé aux
données avant le déclic.

## Ce qui se passe ensuite

`pipeline/sync-captures.mjs` les télécharge au déploiement de la landing, les
range sous `apps/web/public/captures/consultant/` et crée la ligne
correspondante dans `landing_captures`. Titre, ordre et rattachement se
corrigent ensuite dans la console d'administration, sans toucher à ce dépôt.
