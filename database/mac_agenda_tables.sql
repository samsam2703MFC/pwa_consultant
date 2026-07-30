-- ---------------------------------------------------------------------------
-- Agenda des visites consultants — schéma (MySQL / atelierby_db)
--
-- À exécuter par un utilisateur disposant du privilège CREATE si le compte
-- applicatif ne l'a pas (l'application tente aussi un CREATE TABLE IF NOT
-- EXISTS au premier accès, cf. AgendaRepository::ensureSchema()).
--
-- Deux tables, toutes deux nouvelles (aucune donnée existante touchée) :
--   mac_consultant_visit         : les visites planifiées/faites par les consultants
--   mac_consultant_lever_action  : ce qu'il faut travailler, par levier (T/R/E/F/L/O)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_consultant_visit (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_consultant   BIGINT UNSIGNED NOT NULL,               -- id d'adhésion (JWT) du consultant
    consultant_name VARCHAR(190)    NULL,                   -- dénormalisé (affichage multi-consultants)
    id_shop         BIGINT UNSIGNED NOT NULL,
    shop_name       VARCHAR(190)    NULL,
    scheduled_at    DATETIME        NOT NULL,               -- date + heure de la visite
    duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    type            VARCHAR(20)     NOT NULL DEFAULT 'development', -- surprise|development|quality|other
    goal            TEXT            NULL,                    -- but de la visite
    status          VARCHAR(20)     NOT NULL DEFAULT 'planned', -- planned | done | cancelled
    report_ref      VARCHAR(255)    NULL,                   -- lien/réf du rapport indexé
    id_checklist    BIGINT UNSIGNED NULL,                   -- checklist liée à la visite
    checklist_name  VARCHAR(190)    NULL,
    lever_period    CHAR(7)         NULL,                   -- 'YYYY-MM' : mois de référence des leviers
    shared          TINYINT(1)      NOT NULL DEFAULT 0,      -- partagé au franchisé
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_cons_time (id_consultant, scheduled_at),
    KEY idx_shop_time (id_shop, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mac_consultant_lever_action (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop       BIGINT UNSIGNED NOT NULL,
    id_visit      BIGINT UNSIGNED NULL,                     -- rattachement éventuel à une visite
    id_consultant BIGINT UNSIGNED NOT NULL,
    lever         VARCHAR(20)     NOT NULL,                 -- trafic|recurrence|xp|food|labour|overhead
    action        TEXT            NOT NULL,                 -- ce qu'il faut travailler
    status        VARCHAR(20)     NOT NULL DEFAULT 'todo',  -- todo | doing | done
    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_shop_lever (id_shop, lever),
    KEY idx_visit (id_visit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration depuis les anciennes tables (si elles existent) — l'app la fait
-- aussi automatiquement au premier accès (ids conservés, id_visit cohérent) :
-- INSERT IGNORE INTO mac_consultant_visit SELECT * FROM consultant_visit;
-- INSERT IGNORE INTO mac_consultant_lever_action SELECT * FROM consultant_lever_action;
