-- ---------------------------------------------------------------------------
-- Relevé mensuel de la note Google par magasin (atelierby_db).
--
-- POURQUOI CETTE TABLE EXISTE. La note Google se lit en direct chez Google
-- (Places API) et ne vit ensuite que dans un cache serveur de douze heures :
-- rien ne la garde. Or Google ne rend que le PRÉSENT — on ne peut pas
-- reconstruire un historique après coup, contrairement au P&L mensuel ou aux
-- ventes, qui dorment en base et se rattrapent quand on en a besoin.
--
-- Chaque mois sans relevé est donc un mois de comparaison perdu pour toujours.
-- C'est la seule raison de cette table : rendre possible, l'année prochaine,
-- le « même mois l'an dernier » du levier Expérience Client — au tableau des
-- magasins du back office CEO, qui lit cette base.
--
-- Une ligne par magasin et par mois, mise à jour à chaque lecture de la note :
-- en fin de mois, la ligne porte la dernière note connue de ce mois.
--
-- Écriture non bloquante : si le privilège manque, la note s'affiche quand
-- même — on perd le relevé, pas l'écran.
--
-- L'application tente le CREATE au premier relevé
-- (GoogleRatingSnapshotRepository::ensureSchema). Si le compte applicatif n'a
-- pas le privilège CREATE, exécuter ce fichier via le DBA.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_google_rating_snapshot (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop     BIGINT UNSIGNED NOT NULL,
    -- Le MOIS du relevé, pas la date : c'est la maille de comparaison.
    snap_month  CHAR(7)         NOT NULL,          -- 'AAAA-MM'
    -- La note Google est une moyenne cumulée de tous les avis reçus depuis
    -- l'ouverture : ce n'est pas la note « du mois », c'est la note AU mois.
    rating      DECIMAL(3,2)    NOT NULL,
    -- Cumulé lui aussi. L'écart entre deux mois donne les avis NOUVEAUX de la
    -- période — la seule mesure vraiment mensuelle qu'on puisse en tirer.
    reviews     INT UNSIGNED    NOT NULL DEFAULT 0,
    captured_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    -- Un seul relevé par magasin et par mois : une seconde lecture met à jour,
    -- elle n'empile pas.
    UNIQUE KEY uq_shop_month (id_shop, snap_month),
    KEY idx_month (snap_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La série d'un magasin, du plus ancien au plus récent :
--   SELECT snap_month, rating, reviews
--     FROM mac_google_rating_snapshot
--    WHERE id_shop = 4 ORDER BY snap_month;

-- Le « même mois l'an dernier », tel que l'écran des leviers l'interrogera :
--   SELECT a.id_shop, a.rating AS note_2026, b.rating AS note_2025,
--          a.reviews - b.reviews AS avis_gagnes
--     FROM mac_google_rating_snapshot a
--     LEFT JOIN mac_google_rating_snapshot b
--            ON b.id_shop = a.id_shop
--           AND b.snap_month = DATE_FORMAT(
--                 DATE_SUB(CONCAT(a.snap_month, '-01'), INTERVAL 1 YEAR), '%Y-%m')
--    WHERE a.snap_month = '2026-08';

-- Couverture — depuis quand la série existe, et si elle a des trous :
--   SELECT COUNT(DISTINCT snap_month) AS mois, MIN(snap_month) AS depuis,
--          COUNT(DISTINCT id_shop) AS magasins, COUNT(*) AS lignes
--     FROM mac_google_rating_snapshot;
