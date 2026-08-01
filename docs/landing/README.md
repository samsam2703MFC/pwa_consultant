# Captures pour la fiche landing

La landing publique (`samsam2703MFC/landing_tfb`) ne fabrique pas d'images :
elle reprend celles que ce dépôt publie **dans ce dossier**, au déploiement
suivant. Tant qu'il est vide, la fiche « Panel consultant » n'a rien à montrer.

## Le nom du fichier fait le rattachement

`consultant-<clé>.png` — la clé est celle de la fonction dans
`.tfb/module.json`. `consultant-checklists.png` se range donc sous « Checklists
notées ». Un nom qui ne correspond à aucune clé reste rattaché au module entier,
sans erreur mais sans précision.

Les douze attendues :

| Fichier | Écran |
|---|---|
| `consultant-dashboard.png` | `/dashboard` — Aperçu du jour |
| `consultant-shops.png` | `/shops` — Boutiques et compte de résultat |
| `consultant-sixl.png` | `/levers` — HEXm, les six leviers |
| `consultant-agenda.png` | `/agenda` — Agenda des visites |
| `consultant-checklists.png` | `/checklists` — Checklists notées |
| `consultant-targets.png` | `/targets` — Objectifs du franchisé |
| `consultant-trends.png` | `/trends` — Tendances sur douze mois |
| `consultant-notes.png` | `/notes` — Notes de terrain |
| `consultant-rapports.png` | `/reports` — Comptes rendus PDF |
| `consultant-helpdesk.png` | `/helpdesk` — Demandes et tickets |
| `consultant-tasks.png` | `/tasks` — Tâches du consultant |
| `consultant-claims.png` | `/claims` — Réclamations matériel |

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
