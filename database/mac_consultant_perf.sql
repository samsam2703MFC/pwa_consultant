-- ---------------------------------------------------------------------------
-- Mesure des temps de rendu (atelierby_db) — mac_consultant_perf.
--
-- Une ligne par affichage d'écran. On y lit trois choses qu'aucun log ne
-- donne aujourd'hui :
--   • server_ms  — temps total passé dans PHP pour rendre la page ;
--   • api_ms     — dont temps passé à ATTENDRE l'API ;
--   • api_calls / api_cached — combien d'appels sont réellement partis sur le
--     réseau, et combien ont été servis par le cache serveur.
-- La différence server_ms − api_ms est notre propre coût ; api_cached dit si
-- le préchargement au login sert vraiment à quelque chose.
--
-- client_ms est renseigné APRÈS coup par le navigateur (balise sendBeacon sur
-- /system/perf/beacon) : c'est le temps RÉELLEMENT vécu, réseau mobile et
-- rendu compris. Il reste NULL si la page a été quittée trop vite.
--
-- Les colonnes snap_date / hour_of_day / day_of_week sont dénormalisées
-- exprès : la heatmap (écran × heure) se lit alors en un GROUP BY, sans
-- fonction de date, et reste rapide même sur un an de mesures.
--
-- Aucune donnée nominative : user_key est l'identité STABLE issue du jeton
-- (la même clé que celle du cache API), pas un nom ni un e-mail.
--
-- L'application tente le CREATE au premier accès (PerfRepository::ensureSchema).
-- Si le compte applicatif n'a pas le privilège CREATE, exécuter ce fichier via
-- le DBA.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_consultant_perf (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    -- Corrélation serveur ↔ navigateur : la page porte ce jeton, la balise le
    -- renvoie pour compléter client_ms sur la MÊME ligne.
    rid          CHAR(16)         NOT NULL,
    ts           DATETIME         NOT NULL,
    snap_date    DATE             NOT NULL,
    hour_of_day  TINYINT UNSIGNED NOT NULL,   -- 0 – 23
    day_of_week  TINYINT UNSIGNED NOT NULL,   -- 1 = lundi … 7 = dimanche
    user_key     VARCHAR(40)      NOT NULL,
    -- Écran normalisé : /shops/{id}, jamais /shops/42 — sinon la heatmap aurait
    -- une ligne par boutique et n'apprendrait rien.
    route        VARCHAR(160)     NOT NULL,
    method       VARCHAR(8)       NOT NULL,
    server_ms    MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    api_ms       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    api_calls    SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    api_cached   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    api_failed   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    client_ms    MEDIUMINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rid (rid),
    KEY idx_date_hour (snap_date, hour_of_day),
    KEY idx_route_date (route, snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Requêtes de lecture (celles que sert /system/perf/data).
-- ---------------------------------------------------------------------------

-- HEATMAP écran × heure : temps moyen vécu, et part des affichages lents.
-- SELECT route, hour_of_day,
--        COUNT(*)                                        AS n,
--        ROUND(AVG(COALESCE(client_ms, server_ms)))      AS avg_ms,
--        ROUND(AVG(server_ms))                           AS avg_server_ms,
--        ROUND(AVG(api_ms))                              AS avg_api_ms,
--        ROUND(AVG(api_calls), 1)                        AS avg_calls,
--        SUM(api_cached) / NULLIF(SUM(api_cached + api_calls), 0) * 100 AS cache_pct
--   FROM mac_consultant_perf
--  WHERE snap_date BETWEEN '2026-08-01' AND '2026-08-31'
--  GROUP BY route, hour_of_day;

-- Purge : l'application supprime au-delà de perf_retention_days
-- (mac_consultant_param). À la main :
-- DELETE FROM mac_consultant_perf WHERE snap_date < CURDATE() - INTERVAL 90 DAY;
