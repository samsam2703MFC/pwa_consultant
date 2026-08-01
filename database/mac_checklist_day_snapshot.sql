-- ---------------------------------------------------------------------------
-- Photo journalière des checklists d'une boutique (atelierby_db).
--
-- POURQUOI CETTE TABLE
-- L'API ne sait répondre que jour par jour : il n'existe aucun endpoint par
-- plage pour les checklists. Un rapport mensuel devrait donc relire ~26 jours
-- à chaque ouverture — et un rapport hebdomadaire 6. Comme un jour CLOS ne
-- change plus, on le fige ici une fois pour toutes.
--
-- Le rapport lit cette table ; seuls les jours absents sont demandés à l'API,
-- en parallèle. Un jour non figé (is_final = 0) est relu à la prochaine
-- génération : c'est le cas du jour courant, où des tâches peuvent encore être
-- réalisées ou évaluées.
--
-- L'application tente un CREATE au premier accès (ChecklistSnapshotRepository::
-- ensureSchema). Si le compte applicatif n'a pas le privilège CREATE, exécuter
-- ce fichier via le DBA.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_checklist_day_snapshot (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop         BIGINT UNSIGNED NOT NULL,
    snap_date       DATE            NOT NULL,

    -- Comptages du jour. « planned » = tâches attendues, « done » = réalisées.
    planned         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    done            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Réalisées ET évaluées par le consultant : dénominateur du taux de
    -- conformité. Une tâche faite mais jamais relue n'est ni conforme ni non
    -- conforme — la compter comme conforme flatterait le taux.
    reviewed        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    conform         SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Non-conformités, ventilées par gravité. La gravité vient de la NOTE du
    -- consultant (1–5) : le seuil est un paramètre, pas une constante.
    nc_major        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nc_minor        SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Tâches OBLIGATOIRES non réalisées. Bloquantes par définition : elles
    -- n'ont pas de note, donc pas de gravité, et ne peuvent pas être comptées
    -- parmi les non-conformités. Les taire les ferait disparaître du rapport.
    blocking_missed SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Note moyenne du jour : somme et effectif, pour agréger sans biais sur la
    -- semaine ou le mois (une moyenne de moyennes serait fausse).
    rating_sum      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rating_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Détail du jour en JSON : une entrée par non-conformité (tâche, checklist,
    -- note, gravité, commentaire, pièce jointe) et le récapitulatif par
    -- checklist. Évite une seconde table pour une donnée jamais requêtée
    -- autrement que « tout le jour d'un coup ».
    nc_json         MEDIUMTEXT      NULL,
    checklists_json TEXT            NULL,
    -- Tâches repassées CONFORMES ce jour-là : seul moyen de dire si une
    -- non-conformité antérieure a été levée, plutôt que simplement retirée
    -- de la checklist.
    conform_ids_json TEXT           NULL,

    -- 0 = jour encore ouvert, à relire à la prochaine génération.
    is_final        TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_day (id_shop, snap_date),
    KEY idx_day (snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sur une table créée par une version antérieure, la colonne est ajoutée par :
--   ALTER TABLE mac_checklist_day_snapshot ADD COLUMN conform_ids_json TEXT NULL;

-- Purge éventuelle d'un jour à recalculer (ex. après correction d'un avis) :
-- DELETE FROM mac_checklist_day_snapshot
--  WHERE id_shop = 2 AND snap_date = '2026-05-26';
