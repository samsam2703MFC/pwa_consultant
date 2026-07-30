-- ---------------------------------------------------------------------------
-- Avis consultant sur les tâches réalisées (atelierby_db).
--
-- L'API d'avancement des checklists expose la note, la conformité et le
-- commentaire (review_rating, review_is_accepted, review_comment) mais PAS qui
-- a évalué ni quand. Le panel connaît le consultant connecté au moment où
-- l'avis est posé : il le consigne ici.
--
-- Deux usages : afficher « Vérifié par … » sur la tâche, et suivre l'activité
-- d'évaluation par consultant.
--
-- Sur une table créée par une version antérieure, les trois colonnes de
-- validation Owner sont ajoutées par ALTER au premier accès :
--   ALTER TABLE mac_task_review ADD COLUMN owner_validated_at DATETIME NULL;
--   ALTER TABLE mac_task_review ADD COLUMN id_owner BIGINT UNSIGNED NULL;
--   ALTER TABLE mac_task_review ADD COLUMN owner_name VARCHAR(190) NULL;
--
-- L'application tente un CREATE au premier accès (TaskReviewRepository::
-- ensureSchema). Si le compte applicatif n'a pas le privilège CREATE,
-- exécuter ce fichier via le DBA.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_task_review (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop         BIGINT UNSIGNED NOT NULL,
    id_checklist    BIGINT UNSIGNED NULL,
    id_task         BIGINT UNSIGNED NOT NULL,
    review_date     DATE            NOT NULL,
    completion_id   BIGINT UNSIGNED NULL,
    id_consultant   BIGINT UNSIGNED NOT NULL,
    consultant_name VARCHAR(190)    NULL,
    rating          TINYINT UNSIGNED NULL,
    is_accepted     TINYINT(1)      NULL,
    comment         TEXT            NULL,
    -- Contrôle de l'Owner : validation de l'avis d'un consultant.
    owner_validated_at DATETIME     NULL,
    id_owner        BIGINT UNSIGNED NULL,
    owner_name      VARCHAR(190)    NULL,
    created_at      DATETIME        NULL,
    updated_at      DATETIME        NULL,
    PRIMARY KEY (id),
    -- Un seul avis par tâche et par jour : un second enregistrement met à jour.
    UNIQUE KEY uq_review (id_shop, id_task, review_date),
    KEY idx_consultant (id_consultant, review_date),
    KEY idx_shop_date (id_shop, review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activité d'évaluation par consultant sur une période :
-- SELECT id_consultant, MAX(consultant_name) AS nom, COUNT(*) AS avis,
--        COUNT(DISTINCT id_shop) AS boutiques, ROUND(AVG(rating), 2) AS note_moy,
--        SUM(is_accepted = 0) AS refus,
--        SUM(owner_validated_at IS NOT NULL) AS valides, MAX(updated_at) AS dernier
--   FROM mac_task_review
--  WHERE review_date BETWEEN '2026-07-01' AND '2026-07-31'
--  GROUP BY id_consultant ORDER BY avis DESC;
