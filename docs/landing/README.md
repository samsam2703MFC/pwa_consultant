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

## Les produire

Le panel est un poste **tablette** : les captures se prennent en 1194 × 834,
densité 2. Le script du dépôt landing s'en charge, depuis une instance qui
tourne :

```bash
# dans une copie de samsam2703MFC/landing_tfb
node pipeline/capturer-ecrans.mjs \
  --module=consultant \
  --base=https://<serveur>/pwa_consultant \
  --cookie="PHPSESSID=<session ouverte>"
```

Le cookie est indispensable : hors `/auth`, tous les écrans renvoient vers la
connexion, et l'authentification passe par l'API (`/consultant/auth/login`).
Sans session, le script le dit écran par écran plutôt que d'enregistrer douze
pages de login.

Puis, ici :

```bash
cp <sortie>/*.png docs/landing/
git add docs/landing && git commit -m "Publier les captures de la fiche landing"
```

## Ce qui se passe ensuite

`pipeline/sync-captures.mjs` les télécharge au déploiement de la landing, les
range sous `apps/web/public/captures/consultant/` et crée la ligne
correspondante dans `landing_captures`. Titre, ordre et rattachement se
corrigent ensuite dans la console d'administration, sans toucher à ce dépôt.
