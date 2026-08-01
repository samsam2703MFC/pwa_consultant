-- ---------------------------------------------------------------------------
-- Classement réseau conservé, jour par jour (atelierby_db).
--
-- `/consultant/network/tasks/ranking` ne répond que pour UNE journée. Sans
-- cette table, l'accueil « ce qui manque » relirait tout le mois à chaque
-- ouverture — une trentaine d'appels sur le PREMIER écran d'après connexion.
--
-- Une journée passée ne change plus : elle est écrite une fois. La journée en
-- cours n'est jamais écrite ici (elle bouge encore) et reste toujours relue.
--
-- L'application tente le CREATE au premier affichage
-- (NetworkDayRepository::ensureSchema). Si le compte applicatif n'a pas le
-- privilège CREATE, exécuter ce fichier via le DBA : sans la table, l'accueil
-- fonctionne quand même mais se limite à une fenêtre de quelques jours, et il
-- l'écrit à l'écran.
--
-- Ce cache devient inutile le jour où T11 (GET /consultant/network/tasks)
-- couvre une PLAGE de dates : la table peut alors être supprimée.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_network_day (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snap_date        DATE            NOT NULL,
    id_shop          BIGINT UNSIGNED NOT NULL,
    -- Nom et ville au moment de la photo : un rapport de mois passé doit
    -- pouvoir se relire même si la boutique a quitté le périmètre depuis.
    shop_name        VARCHAR(190)    NULL,
    shop_city        VARCHAR(190)    NULL,
    planned          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    done             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    mandatory_missed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tasks_failed     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME        NOT NULL,
    updated_at       DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_day_shop (snap_date, id_shop),
    KEY idx_day (snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purge conseillée : au-delà d'un an, ces journées ne sont plus consultées.
--   DELETE FROM mac_network_day WHERE snap_date < DATE_SUB(CURDATE(), INTERVAL 400 DAY);
