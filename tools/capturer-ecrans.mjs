#!/usr/bin/env node
/**
 * Produit les captures d'écran du panel pour sa fiche sur la landing.
 *
 * La landing ne fabrique pas d'images : elle reprend celles que ce dépôt
 * publie dans `docs/landing/*.png`. Ce script les fabrique depuis une
 * instance en service — c'est le workflow `captures-landing` qui l'appelle,
 * mais il tourne aussi bien à la main.
 *
 * Le nom du fichier fait le rattachement : `consultant-checklists.png` se
 * range sous la fonction dont la clé est « checklists ». C'est pour ça que le
 * plan ci-dessous reprend les clés exactes des fiches, et pas des libellés.
 *
 * Format tablette par défaut (1194 × 834, un iPad en paysage) : c'est le poste
 * réel du panel consultant et du kiosque cuisine. `--format=bureau` ou
 * `--format=mobile` pour les modules qui se regardent ailleurs.
 *
 * Usage :
 *   node tools/capturer-ecrans.mjs --module=consultant --base=https://serveur/pwa_consultant
 *
 * Ces écrans demandent une session ouverte. Deux façons de la donner :
 *   · laisser le script se connecter — c'est le plus simple :
 *       CAPTURE_USER='0600000000' CAPTURE_PASS='…' node tools/capturer-ecrans.mjs …
 *   · ou coller une session déjà ouverte :
 *       node tools/capturer-ecrans.mjs … --cookie="PHPSESSID=…"
 *
 * Le mot de passe n'est ni écrit ni affiché : il passe par l'environnement et
 * ne sert qu'à remplir le formulaire de connexion de l'application.
 *
 * Le résultat est un dossier à copier tel quel dans `docs/landing/` du dépôt
 * du module, puis à committer. La landing les reprend au déploiement suivant.
 *
 * Aucun secret n'est écrit : l'adresse et le cookie de session passent en
 * argument ou par les variables CAPTURE_BASE / CAPTURE_COOKIE.
 */

import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = dirname(fileURLToPath(import.meta.url));

/** Les formats d'écran. Le premier est celui du poste réel du module. */
const FORMATS = {
  tablette: { width: 1194, height: 834, deviceScaleFactor: 2 },
  bureau: { width: 1440, height: 900, deviceScaleFactor: 2 },
  mobile: { width: 430, height: 932, deviceScaleFactor: 3 },
};

/**
 * Le plan de capture de chaque module : clé de fonction → chemin de la page.
 * Les clés sont celles des fiches (`landing_fonctions.cle`) — les changer
 * détacherait la capture de sa fonction.
 */
/**
 * Ce qui doit disparaître d'une capture avant qu'elle parte sur un site
 * public. Partagé par les deux modules : c'est le même réseau derrière.
 *
 * Le principe : rien de ce qui permet de reconnaître une enseigne, un point
 * de vente ou une personne. Les chiffres, eux, restent tels quels — les
 * maquiller ferait de la capture un mensonge, et c'est pour ça que les écrans
 * qui en étalent sont écartés plutôt que retouchés.
 */
const ANONYMAT = {
  marque: 'RESEAU DEMO',
  // Le nom d'un point de vente, tel qu'il apparaît dans les vues.
  noms: [{ motif: "Atelier by[A-Za-z\u00c0-\u00ff' \\-]{0,40}", prefixe: 'Boutique' }],
  villes: ['Halle', 'Corbais', 'Gembloux', 'Nysa', 'Sombreffe'],
  // Ce qui reste d'enseigne une fois les noms complets remplacés.
  enseignes: ['Atelier', 'Berlo', 'Harmonie', 'L\u2019Atelier', "L'Atelier"],
  // Les porteurs de nom de personne, reperes dans les gabarits Twig.
  personnes:
    '.nt-note-author, .nt-reply-author, .nt-thread-author, .task-by, .who,' +
    ' .pf-name, .tm-name, .nt-me-sig-name, .app-nav-profile-name, .lt-name',
  // Les vignettes d'identite : deux lettres suffisent a reconnaitre quelqu'un
  // dans une petite equipe.
  avatars:
    '.nt-avatar, .nt-thread-avatar, .nt-me-sig-avatar, .pf-avatar, .app-nav-profile-avatar',
  // Les pieces jointes : une photo de comptoir montre l'enseigne, les
  // produits, parfois le personnel. Floutees plutot que retirees, pour que la
  // capture continue de montrer que la fonction existe.
  photos:
    '[class*="photo"] img, [class*="attach"] img, .rpt-thumb, .nt-reply-thumb,' +
    ' .claim-photo, .photo-box img, .photo-preview img',
  // Les tiers : un fournisseur nomme, c'est une relation commerciale du
  // reseau qui devient publique.
  tiers: '.claim-supplier-bar, .claim-status-eyebrow',
  // Le texte libre saisi en magasin. Personne ne relit ce qu'un employe
  // ecrit dans une reclamation ou une note : ca peut nommer quelqu'un,
  // decrire un incident, citer un client. Floute — la capture montre que la
  // fonction existe, sans donner a lire.
  libres: '.claim-description, .nt-note-texte, .nt-reply-texte, .nt-ctx-title',
  // Les references internes : ORD-4-9E53…, CMP-4-553D…
  references: true,
  courriels: true,
  telephones: true,
};

const PLANS = {
  consultant: {
    format: 'tablette',
    connexion: { chemin: '/auth', identifiant: 'phone', motdepasse: 'password' },
    // `sensible` marque les écrans qui étalent le compte de résultat d'un
    // réseau identifiable. Ils ne sortent qu'avec --sensibles, et seulement
    // depuis une instance de démonstration.
    ecrans: {
      dashboard: { chemin: '/dashboard', sensible: true },
      shops: { chemin: '/shops', sensible: true },
      sixl: { chemin: '/levers', sensible: true },
      agenda: '/agenda',
      checklists: '/checklists',
      targets: { chemin: '/targets', sensible: true },
      trends: { chemin: '/trends', sensible: true },
      notes: '/notes',
      rapports: { chemin: '/reports', sensible: true },
      helpdesk: '/helpdesk',
      tasks: '/tasks',
      claims: '/claims',
    },
    anonymat: ANONYMAT,
  },
  cuisine: {
    format: 'tablette',
    connexion: { chemin: '/auth', identifiant: 'phone', motdepasse: 'password' },
    ecrans: {
      dashboard: { chemin: '/dashboard', sensible: true },
      checklists: '/checklists',
      produits: '/knowledge/products',
      commandes: '/orders',
      'nouvelle-commande': '/orders/new',
      reclamations: '/complaints',
    },
    anonymat: ANONYMAT,
  },
};

/** Lit `--nom=valeur` sur la ligne de commande. */
function argument(nom, defaut = '') {
  const trouve = process.argv.find((a) => a.startsWith(`--${nom}=`));
  return trouve ? trouve.slice(nom.length + 3) : defaut;
}

const slug = argument('module');
const base = (argument('base') || process.env.CAPTURE_BASE || '').replace(/\/+$/, '');
const cookie = argument('cookie') || process.env.CAPTURE_COOKIE || '';
const attente = Number(argument('attente', '2500'));
const identifiant = argument('identifiant') || process.env.CAPTURE_USER || '';
// Les écrans chiffrés ne sortent que sur demande explicite, et l'anonymat ne
// se lève que sur demande explicite : les deux oublis coûtent cher.
const avecSensibles = process.argv.includes('--sensibles');
const sansAnonymat = process.argv.includes('--sans-anonymat');
const motDePasse = process.env.CAPTURE_PASS || '';

/**
 * Un plan improvisé, pour un module qui n'en a pas encore :
 *   --ecrans=dashboard=/tableau,stock=/stock
 * Les clés doivent être celles des fonctions de la fiche.
 */
const surMesure = argument('ecrans');
const ecransImprovises = surMesure
  ? Object.fromEntries(
      surMesure.split(',').map((p) => {
        const i = p.indexOf('=');
        return [p.slice(0, i).trim(), p.slice(i + 1).trim()];
      }),
    )
  : null;

if (!slug || (!PLANS[slug] && !ecransImprovises)) {
  console.error(`Module inconnu. Plans disponibles : ${Object.keys(PLANS).join(', ')}`);
  console.error("Sinon, décrire les écrans à la volée : --ecrans=cle=/chemin,cle2=/chemin2");
  process.exit(1);
}
if (!base) {
  console.error('Adresse de l\'instance manquante : --base=https://… ou CAPTURE_BASE.');
  process.exit(1);
}

// Un plan improvisé garde les règles du module — connexion et anonymat —
// sinon reprendre un seul écran le publierait en clair.
const plan = ecransImprovises
  ? { format: 'tablette', ...(PLANS[slug] || {}), ecrans: ecransImprovises }
  : PLANS[slug];
// Un écran se décrit par un chemin, ou par un objet quand il porte un drapeau.
const ecrans = Object.entries(plan.ecrans)
  .map(([cle, v]) => (typeof v === 'string' ? { cle, chemin: v, sensible: false } : { cle, ...v }))
  .filter((e) => avecSensibles || !e.sensible);

const ecartes = Object.entries(plan.ecrans).length - ecrans.length;
if (ecartes > 0) {
  console.log(`· ${ecartes} écran(s) chiffré(s) écarté(s) — --sensibles pour les inclure,`);
  console.log('  et seulement depuis une instance de démonstration.');
}

const total = ecrans.length;

const format = FORMATS[argument('format', plan.format)] || FORMATS.tablette;
// Chez lui, le dépôt écrit directement là où la landing ira lire.
const sortie = resolve(argument('sortie') || resolve(ICI, '..', 'docs', 'landing'));

// Playwright n'est pas une dépendance du pipeline : il n'est utile que pour
// cette tâche, lancée à la main. Le message le dit plutôt que d'échouer sec.
let chromium;
{
  // playwright-core suffit quand un navigateur est déjà installé sur la machine.
  const essais = [];
  for (const paquet of ['playwright', 'playwright-core']) {
    try {
      ({ chromium } = await import(paquet));
      break;
    } catch (err) {
      essais.push(`  ${paquet} : ${err.code || err.message.split('\n')[0]}`);
    }
  }
  if (!chromium) {
    console.error(`Aucun module Playwright chargeable depuis ${ICI} :`);
    // La raison exacte compte : un paquet absent et un paquet cassé se
    // soignent différemment, et « manquant » les confondait.
    console.error(essais.join('\n'));
    console.error('');
    console.error('Installer, depuis la racine du dépôt :');
    console.error('  npm i -D playwright');
    console.error('  npx playwright install chromium');
    console.error('');
    console.error('Le workflow captures-landing s\'en charge tout seul :');
    console.error('  Actions → captures-landing → Run workflow.');
    process.exit(1);
  }
}

await mkdir(sortie, { recursive: true });

/** Les chemins où un navigateur traîne d'habitude sur une machine Linux. */
const NAVIGATEURS = [
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/usr/lib/chromium/chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/opt/google/chrome/chrome',
  '/snap/bin/chromium',
];

/**
 * Les navigateurs que `playwright install` a déposés dans le cache. Ils
 * survivent aux `npm ci` du déploiement, contrairement à node_modules — c'est
 * donc là qu'il faut regarder en premier quand le module vient d'être
 * réinstallé.
 */
function navigateursDuCache() {
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || resolve(homedir(), '.cache', 'ms-playwright');
  if (!existsSync(cache)) return [];
  const trouves = [];
  for (const dossier of readdirSync(cache)) {
    if (!dossier.startsWith('chromium')) continue;
    for (const suite of [
      ['chrome-linux', 'chrome'],
      ['chrome-linux64', 'chrome'],
      ['chrome-headless-shell-linux64', 'chrome-headless-shell'],
    ]) {
      const chemin = resolve(cache, dossier, ...suite);
      if (existsSync(chemin)) trouves.push(chemin);
    }
  }
  // Un vrai Chrome avant la coquille sans interface : les captures d'une
  // application qui dessine des graphiques y gagnent.
  return trouves.sort((a, b) => (a.includes('headless-shell') ? 1 : 0) - (b.includes('headless-shell') ? 1 : 0));
}

function aider() {
  console.error('');
  console.error('Aucun navigateur utilisable. Au choix, une seule fois :');
  console.error('  npx playwright install chromium');
  console.error('    (sans --with-deps : pas de dpkg, donc pas de conflit avec');
  console.error('     unattended-upgrades ; à relancer avec --with-deps si le');
  console.error('     lancement se plaint ensuite d\'une bibliothèque manquante)');
  console.error('  apt-get install -y chromium        puis relancer ce script');
  console.error('');
  console.error('Un navigateur déjà installé ailleurs se désigne par son chemin :');
  console.error('  PLAYWRIGHT_CHROMIUM=/chemin/vers/chrome node tools/capturer-ecrans.mjs …');
}

/**
 * Ouvre le navigateur ET son contexte, en essayant les candidats dans l'ordre.
 *
 * Les deux vont ensemble volontairement : un lancement qui réussit ne prouve
 * rien — `chrome-headless-shell` démarre puis meurt sur certains serveurs, et
 * la panne n'apparaît qu'à l'ouverture du premier onglet. On valide donc
 * chaque candidat en ouvrant le contexte, et on passe au suivant s'il tombe.
 */
async function ouvrirNavigateur() {
  const impose = process.env.PLAYWRIGHT_CHROMIUM;
  if (impose && !existsSync(impose)) {
    console.error(`PLAYWRIGHT_CHROMIUM pointe sur ${impose}, qui n'existe pas.`);
    const trouves = [...NAVIGATEURS.filter((c) => existsSync(c)), ...navigateursDuCache()];
    if (trouves.length > 0) console.error(`Sur cette machine : ${trouves.join(', ')}`);
    else aider();
    process.exit(1);
  }

  // Un chemin imposé ne souffre pas de repli : c'est un ordre, pas une piste.
  const candidats = impose
    ? [impose]
    : [...navigateursDuCache(), ...NAVIGATEURS.filter((c) => existsSync(c)), null];

  const options = {
    viewport: { width: format.width, height: format.height },
    deviceScaleFactor: format.deviceScaleFactor,
    // Le serveur de test présente un certificat auto-signé.
    ignoreHTTPSErrors: true,
  };

  const echecs = [];
  for (const chemin of candidats) {
    let navigateur = null;
    try {
      navigateur = await chromium.launch({
        executablePath: chemin || undefined,
        // Chromium refuse son bac à sable quand on est root, ce qui est le
        // cas ordinaire sur un serveur.
        chromiumSandbox: false,
      });
      const contexte = await navigateur.newContext(options);
      // Le premier onglet est le vrai test : c'est là que meurt une coquille
      // sans interface mal installée.
      const page = await contexte.newPage();
      if (chemin) console.log(`· navigateur : ${chemin}`);
      return { navigateur, contexte, page };
    } catch (err) {
      echecs.push(`  ${chemin || 'navigateur par défaut de Playwright'} : ${err.message.split('\n')[0]}`);
      await navigateur?.close().catch(() => {});
    }
  }

  console.error('Aucun navigateur n\'a tenu debout :');
  console.error(echecs.join('\n'));
  aider();
  process.exit(1);
}

const { navigateur, contexte, page } = await ouvrirNavigateur();

if (cookie) {
  const hote = new URL(base).hostname;
  for (const paire of cookie.split(';')) {
    const [nom, ...reste] = paire.trim().split('=');
    if (!nom || reste.length === 0) continue;
    await contexte.addCookies([{ name: nom, value: reste.join('='), domain: hote, path: '/' }]);
  }
}

// Se connecter une fois, puis toutes les pages suivent dans la même session.
if (identifiant && plan.connexion) {
  if (!motDePasse) {
    console.error('Mot de passe attendu dans CAPTURE_PASS (jamais en argument).');
    process.exit(1);
  }
  const ou = `${base}${plan.connexion.chemin}`;
  await page.goto(ou, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.fill(`[name="${plan.connexion.identifiant}"]`, identifiant);
  await page.fill(`[name="${plan.connexion.motdepasse}"]`, motDePasse);
  await page.click('button[type=submit], input[type=submit]');
  // Attendre d'avoir quitté la page de connexion, pas que le réseau se
  // taise : ces applications interrogent l'API en continu et `networkidle`
  // n'arrive jamais.
  await page
    .waitForURL((u) => !/login|auth|connexion/i.test(String(u)), { timeout: 12000 })
    .catch(() => {});
  await page.waitForTimeout(attente);
  if (/login|auth|connexion/i.test(page.url())) {
    console.error(`✗ connexion refusée sur ${ou} — identifiant ou mot de passe.`);
    await navigateur.close();
    process.exit(1);
  }
  console.log(`· session ouverte pour ${identifiant}`);
} else if (!identifiant && !cookie && plan.connexion) {
  console.log('· sans session : les écrans protégés vont renvoyer vers la connexion.');
  console.log('  CAPTURE_USER=… CAPTURE_PASS=… pour laisser le script se connecter.');
}

/**
 * Retire du rendu ce qui identifie le réseau : la marque affichée et les noms
 * de points de vente. Fait dans la page, juste avant le déclic — donc après
 * que les données soient arrivées, ce qu'un remplacement au chargement aurait
 * manqué.
 *
 * Le pseudonyme est stable dans toute la session : « Atelier by Berlo -
 * Corbais » devient « Boutique 1 » sur les douze écrans, sinon on ne pourrait
 * plus suivre une boutique d'une capture à l'autre.
 */
async function anonymiser(page, regles) {
  await page.evaluate((r) => {
    // Les tables de pseudonymes vivent sur `window` : elles doivent survivre
    // aux douze appels successifs, sinon une meme boutique changerait de nom
    // d'un ecran a l'autre.
    window.__pseudos = window.__pseudos || new Map();
    window.__gens = window.__gens || new Map();

    const pseudo = (trouve, prefixe, table) => {
      const cle = trouve.trim().toLowerCase();
      if (!cle) return trouve;
      if (!table.has(cle)) table.set(cle, `${prefixe} ${table.size + 1}`);
      return table.get(cle);
    };

    const remplacer = (texte) => {
      let sortie = texte;
      for (const regle of r.noms || []) {
        // L'espace qui suivait le nom est rendu : sans lui, « Boutique 1 — 395 € »
        // devient « Boutique 1— 395 € ».
        sortie = sortie.replace(
          new RegExp(regle.motif, 'gi'),
          (m) => pseudo(m, regle.prefixe, window.__pseudos) + m.match(/\s*$/)[0],
        );
      }
      for (const ville of r.villes || []) {
        sortie = sortie.replace(new RegExp(`\\b${ville}\\b`, 'gi'), 'Ville');
      }
      // Ce qui reste d'enseigne apres le remplacement des noms complets.
      for (const enseigne of r.enseignes || []) {
        sortie = sortie.replace(new RegExp(`\\b${enseigne}\\b`, 'gi'), 'Enseigne');
      }
      if (r.references) {
        // Un identifiant technique ne dit rien de sensible en soi, mais il
        // relie la capture a une commande reelle.
        sortie = sortie.replace(/\b[A-Z]{3}-\d+-[0-9A-F]{8,}\b/g, (m) => `${m.split('-')[0]}-0-XXXXXXXX`);
      }
      if (r.courriels) {
        sortie = sortie.replace(/[\w.+-]+@[\w-]+\.[\w.]{2,}/g, 'contact@exemple.eu');
      }
      if (r.telephones) {
        // Neuf a quinze chiffres, sans barre oblique : une date n'est pas un
        // numero, et « 21/170 » non plus.
        sortie = sortie.replace(/(?:\+|00)?[\d][\d \-.()]{7,}[\d]/g, (m) => {
          const chiffres = m.replace(/\D/g, '').length;
          if (m.includes('/') || chiffres < 9 || chiffres > 15) return m;
          return '+00 000 00 00 00';
        });
      }
      return sortie;
    };

    // ── Les personnes, par selecteur : un nom propre ne se devine pas au
    //    motif, mais le gabarit sait ou il l'affiche. ────────────────────────
    for (const el of document.querySelectorAll(r.personnes || '')) {
      const brut = (el.textContent || '').trim();
      if (!brut || el.dataset.anonyme) continue;
      el.dataset.anonyme = '1';
      el.textContent = pseudo(brut, 'Consultant', window.__gens);
    }

    // Les vignettes d'identite : deux lettres suffisent a reconnaitre
    // quelqu'un dans une petite equipe.
    for (const el of document.querySelectorAll(r.avatars || '')) {
      if (el.dataset.anonyme) continue;
      el.dataset.anonyme = '1';
      if (el.tagName === 'IMG') el.style.filter = 'blur(10px)';
      else el.textContent = '··';
    }

    // Les tiers nommes : fournisseurs, prestataires.
    for (const el of document.querySelectorAll(r.tiers || '')) {
      const brut = (el.textContent || '').trim();
      if (!brut || el.dataset.anonyme) continue;
      el.dataset.anonyme = '1';
      el.textContent = pseudo(brut, 'Fournisseur', window.__gens);
    }

    // ── Le texte, y compris celui d'un graphique en SVG ────────────────────
    const marcheur = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const aTraiter = [];
    while (marcheur.nextNode()) aTraiter.push(marcheur.currentNode);
    for (const noeud of aTraiter) {
      const neuf = remplacer(noeud.nodeValue);
      if (neuf !== noeud.nodeValue) noeud.nodeValue = neuf;
    }
    // Les infobulles disent parfois ce que le texte tait.
    for (const el of document.querySelectorAll('[title], [alt], [aria-label]')) {
      for (const attr of ['title', 'alt', 'aria-label']) {
        const v = el.getAttribute(attr);
        if (v) el.setAttribute(attr, remplacer(v));
      }
    }

    // ── Les pieces jointes : une photo de comptoir montre l'enseigne, les
    //    produits, parfois le personnel. Floutee plutot que retiree, pour que
    //    la capture continue de montrer que la fonction existe. ─────────────
    for (const el of document.querySelectorAll(r.photos || '')) {
      el.style.filter = 'blur(14px)';
    }

    // Le texte libre saisi en magasin, floute plutot que remplace : on ne
    // peut pas deviner ce qu'il contient, et le remplacer par une phrase
    // inventee ferait passer une invention pour une capture.
    for (const el of document.querySelectorAll(r.libres || '')) {
      el.style.filter = 'blur(5px)';
      el.style.userSelect = 'none';
    }

    // Le logo du client : une image ne se remplace pas par du texte, on la
    // masque et on ecrit le nom neutre a sa place.
    const logos = document.querySelectorAll(
      'img[src*="logo" i], img[alt*="logo" i], img[src*="atelier" i], .logo, .brand, [class*="logo" i]',
    );
    for (const logo of logos) {
      if (!logo || logo.dataset.anonyme) continue;
      logo.dataset.anonyme = '1';
      const etiquette = document.createElement('span');
      etiquette.textContent = r.marque;
      etiquette.style.cssText =
        'font:700 15px/1.2 system-ui,sans-serif;letter-spacing:.08em;color:#6b7280;white-space:nowrap';
      logo.replaceWith(etiquette);
    }
  }, regles);
}

console.log(`· ${total} écrans à prendre, comptez une dizaine de secondes chacun.`);
if (plan.anonymat && !sansAnonymat) {
  console.log('· anonymisation active : marque et noms de boutiques remplacés.');
}

let faites = 0;
let ratees = 0;
let refusees = 0;

let rang = 0;

for (const { cle, chemin } of ecrans) {
  const url = `${base}${chemin}`;
  rang += 1;
  // Annoncer avant d'agir : sans ça, une page lente passe pour un blocage,
  // et la tentation d'interrompre est forte.
  process.stdout.write(`  ${String(rang).padStart(2)}/${total} ${cle.padEnd(18)} …`);
  try {
    const reponse = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    // Le silence réseau est un bonus, pas une condition : on lui laisse
    // quelques secondes et on continue s'il ne vient pas.
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
    const code = reponse?.status() ?? 0;
    // Une page de connexion renvoie 200 : on regarde aussi où l'on a atterri.
    const redirige = /login|auth|connexion/i.test(page.url()) && !/login|auth/i.test(chemin);
    if (code >= 400 || redirige) {
      process.stdout.write('\r\u001b[2K');
      console.error(`✗ ${String(rang).padStart(2)}/${total} ${cle.padEnd(18)} ${code}${redirige ? '→ renvoyé vers la connexion' : ''}  ${url}`);
      ratees += 1;
      if (redirige) refusees += 1;
      continue;
    }
    // Laisser les données arriver : ces écrans se remplissent en XHR.
    // --attente=5000 si les graphiques sont encore vides sur les captures.
    await page.waitForTimeout(attente);
    if (plan.anonymat && !sansAnonymat) await anonymiser(page, plan.anonymat);
    const fichier = resolve(sortie, `${slug}-${cle}.png`);
    await page.screenshot({ path: fichier, fullPage: false });
    process.stdout.write('\r\u001b[2K');
    console.log(`✓ ${String(rang).padStart(2)}/${total} ${cle.padEnd(18)} ${fichier.replace(`${process.cwd()}/`, '')}`);
    faites += 1;
  } catch (err) {
    process.stdout.write('\r\u001b[2K');
    console.error(`✗ ${String(rang).padStart(2)}/${total} ${cle.padEnd(18)} ${err.message.split('\n')[0]}`);
    ratees += 1;
  }
}

await navigateur.close();

console.log('');
console.log(`${faites} capture(s) dans ${sortie}${ratees ? ` — ${ratees} échec(s)` : ''}`);
if (faites > 0) {
  console.log('');
  console.log('Étape suivante, dans le dépôt du module :');
  console.log(`  cp ${sortie}/*.png docs/landing/`);
  console.log('  git add docs/landing && git commit -m "Publier les captures de la fiche landing"');
  console.log('La landing les reprend au déploiement suivant (sync-captures.mjs).');
}
if (refusees > 0) {
  console.log('');
  console.log('Des écrans renvoient vers la connexion. Ouvrir une session :');
  console.log('  CAPTURE_USER=… CAPTURE_PASS=… node tools/capturer-ecrans.mjs …');
  console.log('  ou --cookie="PHPSESSID=…" si la session est déjà ouverte ailleurs.');
}

process.exit(ratees > 0 && faites === 0 ? 1 : 0);
