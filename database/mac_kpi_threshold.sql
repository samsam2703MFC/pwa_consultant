-- ---------------------------------------------------------------------------
-- Seuils de mise en forme conditionnelle des KPI (atelierby_db) — préfixe kpi_.
--
-- AUCUNE couleur de marge codée en dur : chaque métrique (marge brute,
-- marge nette / result) a ses bandes « borne basse → couleur » en base,
-- modifiables sans redéploiement. min_pct NULL = -infini (bande « Perte »).
-- La bande retenue pour une valeur est celle avec le plus grand min_pct ≤ valeur.
--
-- Auto-création tentée par l'application (KpiThresholdRepository::ensureSchema) ;
-- si le compte applicatif n'a pas le privilège CREATE, exécuter ce fichier.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_kpi_threshold (
    id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    metric  VARCHAR(32)   NOT NULL,   -- 'gross_margin' | 'net_margin'
    sort    SMALLINT      NOT NULL,   -- ordre des bandes (0 = la plus basse)
    min_pct DECIMAL(6,2)  NULL,       -- borne basse incluse (%) ; NULL = -infini
    color   CHAR(7)       NOT NULL,   -- #rrggbb
    label   VARCHAR(64)   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metric_sort (metric, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seeds = échelles historiques de l'écran Boutiques (modifiables ensuite) :
INSERT IGNORE INTO mac_kpi_threshold (metric, sort, min_pct, color, label) VALUES
    ('net_margin',   0, NULL, '#8B0000', 'Perte'),
    ('net_margin',   1,    0, '#dc3545', '0–5 %'),
    ('net_margin',   2,    5, '#e67e22', '5–10 %'),
    ('net_margin',   3,   10, '#8FA31E', '10–15 %'),
    ('net_margin',   4,   15, '#27ae60', '15–25 %'),
    ('net_margin',   5,   25, '#C9A227', '> 25 %'),
    ('gross_margin', 0, NULL, '#8B0000', 'Perte'),
    ('gross_margin', 1,    0, '#dc3545', '< 40 %'),
    ('gross_margin', 2,   40, '#e67e22', '40–50 %'),
    ('gross_margin', 3,   50, '#8FA31E', '50–60 %'),
    ('gross_margin', 4,   60, '#27ae60', '60–70 %'),
    ('gross_margin', 5,   70, '#C9A227', '> 70 %');

-- Migration depuis l'ancienne table (si elle existe) — l'app la fait aussi
-- automatiquement au premier accès :
-- INSERT IGNORE INTO mac_kpi_threshold SELECT * FROM kpi_threshold;
