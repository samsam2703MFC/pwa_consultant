-- ---------------------------------------------------------------------------
-- Liens de partage d'un rapport mensuel (atelierby_db).
--
-- Un lien ouvre UN rapport, d'UNE boutique, d'UN mois — SANS authentification.
-- Le HTML est figé au moment du partage : la page publique n'appelle jamais
-- l'API, elle relit ce qui a été enregistré ici.
--
-- L'application tente le CREATE au premier partage
-- (ReportShareRepository::ensureSchema). Si le compte applicatif n'a pas le
-- privilège CREATE, exécuter ce fichier via le DBA.
--
-- Purge conseillée (les liens morts n'ont plus d'usage, et le HTML pèse) :
--   DELETE FROM mac_report_share
--    WHERE expires_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_report_share (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 32 octets d'aléa en base64 URL : 43 caractères, non devinables.
    token           VARCHAR(64)     NOT NULL,
    id_shop         BIGINT UNSIGNED NOT NULL,
    ym              CHAR(7)         NOT NULL,
    label           VARCHAR(190)    NOT NULL,
    -- Le rendu figé, comprimé (gzip) : ~30 Ko pour un rapport de 100 Ko.
    html            MEDIUMBLOB      NULL,
    id_consultant   BIGINT UNSIGNED NOT NULL,
    consultant_name VARCHAR(190)    NULL,
    created_at      DATETIME        NOT NULL,
    -- Durée réglée par mac_consultant_param.report_share_days (14 jours).
    expires_at      DATETIME        NOT NULL,
    revoked_at      DATETIME        NULL,
    -- Qui a ouvert, quand : le jour où l'on demande qui a vu un P&L.
    opens           INT UNSIGNED    NOT NULL DEFAULT 0,
    last_opened_at  DATETIME        NULL,
    last_ip         VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_consultant (id_consultant, created_at),
    KEY idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
