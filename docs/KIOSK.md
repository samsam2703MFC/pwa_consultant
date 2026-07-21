# Mode plein écran & kiosque

Trois niveaux d'affichage sans chrome de navigateur, selon l'usage :

## 1. Bouton « Plein écran » (dans l'app)

Menu ☰ → **Plein écran**. Utilise l'API Fullscreen du navigateur
(équivalent F11) — masque la barre d'adresse et la barre des tâches
Windows tant que le mode est actif.

- Sortie : re-cliquer sur le bouton (« Quitter le plein écran ») ou touche **Échap**.
- Le navigateur exige un clic utilisateur : le plein écran ne peut pas être
  forcé automatiquement au chargement.
- Fonctionne sur Chrome, Edge et Firefox (desktop et Android). Non
  disponible sur iPhone (limitation iOS) — le bouton s'y masque tout seul.

## 2. Application installée (PWA)

Le manifest est configuré en `"display": "fullscreen"` (repli
`standalone`). Une fois l'app **installée** (bannière « Installer
l'application » ou menu Chrome → *Installer Consultant Panel*), elle
s'ouvre dans sa propre fenêtre, **sans barre d'adresse**.

> Les utilisateurs ayant déjà installé la PWA avant ce changement doivent
> la désinstaller/réinstaller (ou attendre la mise à jour automatique du
> manifest par le navigateur) pour obtenir le mode fullscreen.

## 3. Écran fixe / borne / TV magasin : Chrome en mode kiosque

Pour un affichage permanent sans AUCUNE interface (ni barre d'adresse, ni
barre des tâches Windows, ni possibilité d'en sortir au clic) :

**Windows — raccourci ou script `.bat` :**

```bat
@echo off
REM Kiosque plein écran — Consultant Panel
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk "http://185.180.206.46/pwa_consultant/" ^
  --no-first-run --disable-session-crashed-bubble --noerrdialogs
```

Avec Microsoft Edge :

```bat
start "" "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" ^
  --kiosk "http://185.180.206.46/pwa_consultant/" --edge-kiosk-type=fullscreen
```

- Sortie du kiosque : **Alt+F4** (ou Ctrl+Alt+Suppr).
- Lancement automatique au démarrage de Windows : placer le `.bat` dans
  `shell:startup` (Win+R → `shell:startup`).
- Pour une borne verrouillée (impossible d'en sortir sans mot de passe),
  utiliser le « mode Kiosque » intégré de Windows :
  Paramètres → Comptes → Autres utilisateurs → **Configurer un kiosque**.
