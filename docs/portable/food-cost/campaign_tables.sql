-- ---------------------------------------------------------------------------
-- Campagne — identité, période, boutiques et ILLUSTRATION.
--
-- Complète product_tables.sql : `product_target` porte les objectifs, cette
-- table porte la campagne qui les regroupe (« Galette 2026 ») et son visuel.
--
-- Deux stockages d'image sont prévus, au choix :
--   A. `illustration_attachment_id` — le fichier vit dans l'API (upload
--      multipart, lecture par /attachments/{id}/presigned-url). À préférer
--      quand l'API sait déjà porter des pièces jointes : rien à sauvegarder,
--      rien à servir, et le visuel suit la campagne partout.
--   B. `illustration_path` — le fichier vit sur le disque du projet
--      (CampaignImage.php). Plus simple, mais c'est à vous de le sauvegarder
--      et de servir le dossier.
-- Renseigner l'un OU l'autre, jamais les deux : deux visuels concurrents et
-- plus personne ne sait lequel s'affiche.
--
-- MySQL / MariaDB.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS campaign (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- ── Identité ──────────────────────────────────────────────────────────
    code         VARCHAR(80)  NOT NULL,          -- 'galette-2026' — stable, sert de clé externe
    name         VARCHAR(190) NOT NULL,          -- « Galette des Rois 2026 »
    subtitle     VARCHAR(190) NULL,              -- accroche courte, affichée sous le titre
    description  TEXT         NULL,

    -- ── Période ───────────────────────────────────────────────────────────
    starts_on    DATE NOT NULL,
    ends_on      DATE NOT NULL,
    status       ENUM('draft','scheduled','running','closed') NOT NULL DEFAULT 'draft',

    -- ── Illustration ──────────────────────────────────────────────────────
    -- A. via l'API (pièce jointe)
    illustration_attachment_id BIGINT UNSIGNED NULL,
    -- B. en local
    illustration_path    VARCHAR(255) NULL,      -- chemin RELATIF au dossier de stockage
    illustration_mime    VARCHAR(60)  NULL,      -- image/jpeg | image/png | image/webp
    illustration_bytes   INT UNSIGNED NULL,
    illustration_width   SMALLINT UNSIGNED NULL,
    illustration_height  SMALLINT UNSIGNED NULL,
    -- Commun aux deux
    illustration_alt     VARCHAR(190) NULL,      -- texte alternatif (accessibilité)
    illustration_focus_x DECIMAL(4,3) NOT NULL DEFAULT 0.500,  -- point d'intérêt 0..1
    illustration_focus_y DECIMAL(4,3) NOT NULL DEFAULT 0.500,

    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL,
    updated_at   DATETIME NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code),
    KEY idx_period (starts_on, ends_on),
    KEY idx_status (status),
    CONSTRAINT ck_period CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `status` est stocké ET dérivable des dates. Le stocker permet le brouillon
-- et la clôture anticipée — deux états que les dates ne savent pas exprimer.
-- Recalage nocturne, si vous voulez qu'il suive les dates tout seul :
--   UPDATE campaign SET status = 'running'
--    WHERE status = 'scheduled' AND starts_on <= CURDATE() AND ends_on >= CURDATE();
--   UPDATE campaign SET status = 'closed'
--    WHERE status IN ('scheduled','running') AND ends_on < CURDATE();

-- Le point d'intérêt (`focus_x` / `focus_y`) évite le visuel décapité : la même
-- image sert en bandeau large et en vignette carrée, et c'est ce point qui reste
-- au centre du recadrage. En CSS : object-fit: cover; object-position: X% Y%.


-- ─────────────── Boutiques concernées ───────────────
-- Une campagne ne concerne pas toujours tout le réseau. Table de liaison
-- plutôt qu'une liste d'ids en colonne : sans elle, impossible de filtrer les
-- objectifs d'une campagne par boutique en SQL.

CREATE TABLE IF NOT EXISTS campaign_shop (
    id_campaign BIGINT UNSIGNED NOT NULL,
    id_shop     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_campaign, id_shop),
    KEY idx_shop (id_shop),
    CONSTRAINT fk_cshop_campaign FOREIGN KEY (id_campaign) REFERENCES campaign (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aucune ligne pour une campagne = tout le réseau. C'est un choix à assumer
-- explicitement dans le code ; l'autre convention (aucune ligne = aucune
-- boutique) est tout aussi défendable, mais les deux ne peuvent pas coexister.


-- ─────────────── Visuels supplémentaires (facultatif) ───────────────
-- L'illustration de `campaign` est LE visuel de la campagne, celui du
-- formulaire. Une campagne réelle en demande souvent d'autres — affiche A3
-- pour la vitrine, story 9:16, vignette e-mail. Cette table les porte sans
-- multiplier les colonnes sur `campaign`.

CREATE TABLE IF NOT EXISTS campaign_asset (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_campaign   BIGINT UNSIGNED NOT NULL,
    kind          ENUM('poster','thumbnail','social','banner','other') NOT NULL DEFAULT 'other',
    label         VARCHAR(120) NULL,
    attachment_id BIGINT UNSIGNED NULL,          -- stockage A
    path          VARCHAR(255) NULL,             -- stockage B
    mime          VARCHAR(60)  NULL,
    bytes         INT UNSIGNED NULL,
    width         SMALLINT UNSIGNED NULL,
    height        SMALLINT UNSIGNED NULL,
    alt           VARCHAR(190) NULL,
    sort          SMALLINT NOT NULL DEFAULT 0,
    created_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_campaign (id_campaign, kind),
    CONSTRAINT fk_asset_campaign FOREIGN KEY (id_campaign) REFERENCES campaign (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────── Rattachement aux objectifs ───────────────
-- `product_target` (product_tables.sql) porte déjà period_from / period_to.
-- Pour rattacher ses lignes à une campagne plutôt que de les rapprocher par
-- dates — fragile dès que deux campagnes se chevauchent :
--
-- ALTER TABLE product_target
--   ADD COLUMN id_campaign BIGINT UNSIGNED NULL AFTER id_shop,
--   ADD KEY idx_campaign (id_campaign),
--   ADD CONSTRAINT fk_target_campaign FOREIGN KEY (id_campaign)
--       REFERENCES campaign (id) ON DELETE SET NULL;
--
-- ON DELETE SET NULL et non CASCADE : supprimer une campagne ne doit pas
-- effacer les objectifs saisis, ni le réalisé qui s'y rapporte.

-- Tableau d'une campagne (à passer à ProductMix::table) :
--   SELECT t.ref_key, t.id_shop, t.qty_target
--     FROM product_target t
--     JOIN campaign c ON c.id = t.id_campaign
--    WHERE c.code = 'galette-2026' AND t.level = 'product';
