# 6L — Plan de données (audit du 21/07/2026)

La page **/levers** affiche 6 leviers × 3 KPI. Phase 1 : **8 KPI branchés**
sur les sources existantes ; les 10 autres affichent « données à venir »
jusqu'à la création des saisies/tables ci-dessous.

## KPI branchés (phase 1)

| Levier | KPI | Source |
|---|---|---|
| Trafic | Tickets/jour | `GET /shops/{id}/day-sales?period=…` (API kpis → repli `transaction`) |
| Récurrence | Panier moyen | idem |
| Food Cost | Coût matière % CA | P&L `/consultant/shops/{id}/pnl` (F = T − L − OC − R) |
| Food Cost | Marge brute % CA | dérivé (100 − FC%) |
| Labour | Coût MO % CA | P&L (labour ÷ turnover) |
| Overhead | Évolution hebdo vs N-1 | `day-sales?from&to` semaine courante vs même semaine N-1 |

Seuils de statut (LeversController) : score = sens × (valeur − moyenne) ÷ |moyenne| × 100 ;
✓ ≥ −5 % · ⚠ ≥ −15 % · ● en dessous. Sens inversé (−1) pour les KPI où plus bas = mieux.

## Données manquantes → à créer

### 1. Saisies consultant/boutique (débloquent 5 KPI rapidement)

```sql
-- Surface commerciale (KPI CA/m²) : une valeur par magasin.
ALTER TABLE shops ADD COLUMN surface_m2 DECIMAL(8,2) NULL;

-- Audits qualité (mystery shopper, HACCP, temps de prise en charge).
CREATE TABLE shop_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_shop INT NOT NULL,
    type ENUM('xp','haccp') NOT NULL,
    score DECIMAL(5,2) NOT NULL,          -- /100
    wait_seconds INT NULL,                -- temps de prise en charge (type xp)
    audited_at DATE NOT NULL,
    created_by INT NULL,
    INDEX (id_shop, type, audited_at)
);

-- Casse / invendus (KPI Food Cost n°3) : saisie quotidienne boutique.
CREATE TABLE waste_entry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_shop INT NOT NULL,
    entry_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,        -- € valeur casse + invendus
    UNIQUE KEY (id_shop, entry_date)
);

-- Heures travaillées (KPI CA/heure et productivité par créneau).
CREATE TABLE worked_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_shop INT NOT NULL,
    work_date DATE NOT NULL,
    hour_slot TINYINT NULL,               -- NULL = total jour ; 0–23 = créneau
    hours DECIMAL(6,2) NOT NULL,
    INDEX (id_shop, work_date)
);

-- Investissement total par magasin (KPI retour sur investissement).
ALTER TABLE shops ADD COLUMN total_investment DECIMAL(12,2) NULL;
```

### 2. Note Google (1 KPI)

Google Business Profile API, scope OAuth `https://www.googleapis.com/auth/business.manage`.
Un job quotidien alimente :

```sql
CREATE TABLE shop_google_rating (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_shop INT NOT NULL,
    rating DECIMAL(3,2) NOT NULL,
    review_count INT NOT NULL,
    fetched_at DATETIME NOT NULL,
    INDEX (id_shop, fetched_at)
);
```

Prérequis : accès au compte Google Business du réseau (config OAuth côté serveur).

### 3. Côté POS / Szymon (4 KPI fidélité)

Nouveaux clients/mois, taux de fidélisation, évolution adhérents : impossibles
sans identification client sur les tickets (`id_customer` sur `transaction`
+ table `customer`/`loyalty_member`). À discuter avec Szymon — le front 6L
les branchera dès qu'un endpoint existe.

## Branchement

Quand une source apparaît, il suffit dans `src/app/Views/levers/index.twig`
de donner un `src` au KPI concerné dans `SIXL_LEVERS` et d'alimenter la
métrique dans `loadShop()` — statuts, moyennes réseau et comparateur suivent
automatiquement.
