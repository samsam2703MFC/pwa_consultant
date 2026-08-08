-- ---------------------------------------------------------------------------
-- Mix produits & catégories — tables portables (compagnon de food_cost_tables.sql).
--
-- Les VENTES par produit viennent de l'API (product-category-groups) : rien à
-- stocker. Ce fichier porte ce que l'API ne donne pas :
--   1. product_sector            — le niveau au-dessus de la catégorie
--   2. product_hierarchy_override — rattachement local, tant que l'API ne
--                                   porte pas encore le secteur
--   3. product_target            — les objectifs en PIÈCES (ligne « Objectif »)
--   4. product_target_snapshot   — l'historique du réalisé, pour comparer N/N-1
--
-- MySQL / MariaDB.
-- ---------------------------------------------------------------------------

-- ─────────────── 1. Secteurs (niveau 1 de la hiérarchie) ───────────────
-- « Votre traiteur est en baisse de 8 % » est une phrase actionnable ;
-- « votre catégorie 47 est en baisse de 8 % » ne l'est pas. Le secteur est le
-- niveau auquel un réseau se pilote — la catégorie est une étagère.
--
-- Ne jamais coder la liste en dur côté client : un secteur renommé dériverait
-- en silence. Elle se lit par GET /consultant/product-sectors.

CREATE TABLE IF NOT EXISTS product_sector (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,           -- boulangerie, viennoiserie, traiteur…
    sort       SMALLINT     NOT NULL DEFAULT 0,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sur la table `product` du back-office :
-- ALTER TABLE product
--   ADD COLUMN is_pdm    TINYINT(1) NOT NULL DEFAULT 0,   -- jamais NULL : PDM ou pas
--   ADD COLUMN sector_id BIGINT UNSIGNED NULL,
--   ADD KEY idx_sector (sector_id),
--   ADD CONSTRAINT fk_product_sector FOREIGN KEY (sector_id) REFERENCES product_sector (id);
--
-- Rendre `sector_id` obligatoire laisse le catalogue existant sans secteur.
-- Deux issues acceptables : le backfiller, ou assumer le NULL et afficher
-- « secteur non renseigné ». La seule à éviter est de compter ces produits
-- pour zéro — ils disparaîtraient d'une ventilation sans que personne le voie.


-- ─────────────── 2. Rattachement local (tant que l'API ne l'a pas) ───────────────
-- Table de repli : elle rattache un produit (ou une catégorie entière) à un
-- secteur / groupe côté panel, sans attendre la reprise du back-office.
-- `ProductMix` lit le secteur du payload en priorité ; cette table ne sert
-- qu'aux produits qui n'en portent pas.

CREATE TABLE IF NOT EXISTS product_hierarchy_override (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_level   ENUM('product','category','group') NOT NULL,  -- sur quoi on matche
    match_key     VARCHAR(190) NOT NULL,        -- id API, ou nom normalisé en minuscules
    sector_name   VARCHAR(120) NULL,
    group_name    VARCHAR(120) NULL,
    category_name VARCHAR(120) NULL,
    created_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_match (match_level, match_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────── 3. Objectifs en pièces (ligne « Objectif ») ───────────────
-- Un objectif se pose à N'IMPORTE QUEL niveau : un produit, une catégorie, un
-- secteur, ou le total de la période. D'où `level` + `ref_key` plutôt qu'une
-- colonne product_id — sinon il faut une table par niveau.
--
-- `ref_key` = l'identifiant API quand il existe ('product:1042'), sinon le nom
-- normalisé ('product#galette frangipane'). C'est exactement la clé que rend
-- ProductMix::rollup() / table(), pour que le rapprochement soit direct.

CREATE TABLE IF NOT EXISTS product_target (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop     BIGINT UNSIGNED NOT NULL,
    level       ENUM('total','sector','group','category','product') NOT NULL,
    ref_key     VARCHAR(190) NOT NULL,          -- 'total' pour la ligne Total période
    ref_label   VARCHAR(190) NULL,              -- libellé au moment de la saisie (traçabilité)
    period_from DATE NOT NULL,
    period_to   DATE NOT NULL,
    qty_target  DECIMAL(12,2) NOT NULL,         -- objectif en PIÈCES
    ca_target   DECIMAL(14,2) NULL,             -- objectif en € (facultatif)
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NULL,
    updated_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_target (id_shop, level, ref_key, period_from, period_to),
    KEY idx_period (period_from, period_to),
    KEY idx_shop_level (id_shop, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Objectifs d'une campagne, dans la forme attendue par ProductMix::table()
-- ($targets[ref_key][id_shop] = qty_target) :
--   SELECT ref_key, id_shop, qty_target
--     FROM product_target
--    WHERE level = 'product' AND period_from = ? AND period_to = ?;
--
-- Ligne « Objectif » du total :
--   SELECT id_shop, qty_target FROM product_target
--    WHERE level = 'total' AND period_from = ? AND period_to = ?;

-- Un objectif réseau se SOMME depuis les boutiques ; ne pas stocker un total
-- réseau à côté des lignes boutique : les deux divergent au premier ajout de
-- boutique, et rien ne dit alors lequel fait foi.


-- ─────────────── 4. Historique du réalisé (comparaison N / N-1) ───────────────
-- L'API rend les ventes d'une fenêtre, pas l'historique d'une campagne close.
-- Une campagne (galette, chocolats de Pâques…) se compare d'une année sur
-- l'autre : sans snapshot, la comparaison est perdue dès que la fenêtre sort
-- de la profondeur d'historique du back-office.

CREATE TABLE IF NOT EXISTS product_target_snapshot (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop     BIGINT UNSIGNED NOT NULL,
    campaign    VARCHAR(120) NULL,              -- 'galette 2026', libre
    level       ENUM('total','sector','group','category','product') NOT NULL,
    ref_key     VARCHAR(190) NOT NULL,
    ref_label   VARCHAR(190) NULL,
    period_from DATE NOT NULL,
    period_to   DATE NOT NULL,
    qty         DECIMAL(12,2) NULL,             -- pièces vendues
    ca          DECIMAL(14,2) NULL,
    tickets     INT UNSIGNED NULL,              -- tickets de LA MÊME fenêtre
    qty_target  DECIMAL(12,2) NULL,             -- objectif tel qu'il était
    captured_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_snap (id_shop, level, ref_key, period_from, period_to),
    KEY idx_campaign (campaign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `tickets` est stocké avec le réalisé, pas déduit après coup : le taux de
-- pénétration (pièces ÷ tickets) n'a de sens que si les deux couvrent la MÊME
-- fenêtre. Un compteur de tickets pris ailleurs — l'année glissante, par
-- exemple — donne un pourcentage qui ne se recalcule pas à la main.
