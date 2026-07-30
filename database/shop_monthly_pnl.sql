-- ---------------------------------------------------------------------------
-- Snapshot mensuel du P&L par boutique (atelierby_db) — pour la valorisation.
--
-- L'API n'expose la marge nette que pour le mois courant. On capture donc,
-- chaque mois, le CA + la marge nette de chaque boutique. La « moyenne marge
-- nette 12 mois » et le graphique d'évolution se construisent à partir de ces
-- snapshots (exacts au fil des mois).
--
-- Auto-création tentée par l'application ; sinon exécuter ce fichier (DBA).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shop_monthly_pnl (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop        BIGINT UNSIGNED NOT NULL,
    year           SMALLINT UNSIGNED NOT NULL,
    month          TINYINT UNSIGNED  NOT NULL,          -- 1..12
    ca             DECIMAL(14,2)  NULL,                 -- CA du mois
    net_margin_pct DECIMAL(7,3)   NULL,                 -- marge nette du mois (%)
    net_result     DECIMAL(14,2)  NULL,                 -- résultat net du mois
    labour         DECIMAL(14,2)  NULL,                 -- labour cost du mois
    overhead       DECIMAL(14,2)  NULL,                 -- overhead cost du mois
    captured_at    DATETIME       NOT NULL,
    updated_at     DATETIME       NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_month (id_shop, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installations antérieures (table déjà créée sans labour/overhead) :
-- ALTER TABLE shop_monthly_pnl ADD COLUMN labour   DECIMAL(14,2) NULL AFTER net_result;
-- ALTER TABLE shop_monthly_pnl ADD COLUMN overhead DECIMAL(14,2) NULL AFTER labour;
