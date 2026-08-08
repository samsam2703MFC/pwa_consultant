-- ---------------------------------------------------------------------------
-- Food Cost — tables portables.
--
-- Le food cost lui-même n'est PAS stocké : il se dérive de l'API métier
-- (coût matière ÷ CA). Ces tables portent ce que l'API ne peut pas donner :
--   1. mac_kpi_threshold     — bandes de couleur (aucune couleur en dur)
--   2. mac_shop_monthly_pnl  — snapshot mensuel, seul moyen d'avoir un
--                              historique (l'API ne détaille que le mois courant)
--   3. waste_entry           — casse / invendus, saisie boutique
--
-- MySQL / MariaDB. Le préfixe `mac_` est celui du projet d'origine : à
-- renommer librement, aucun code ne dépend de ces noms.
-- ---------------------------------------------------------------------------

-- ─────────────────── 1. Seuils de couleur des marges ───────────────────
-- La bande retenue pour une valeur est celle avec le plus grand min_pct
-- <= valeur. min_pct NULL = -infini (bande « Perte »).

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

INSERT IGNORE INTO mac_kpi_threshold (metric, sort, min_pct, color, label) VALUES
    -- Marge brute = 100 − food cost %. C'est l'échelle du levier Food Cost.
    ('gross_margin', 0, NULL, '#8B0000', 'Perte'),
    ('gross_margin', 1,    0, '#dc3545', '< 40 %'),
    ('gross_margin', 2,   40, '#e67e22', '40–50 %'),
    ('gross_margin', 3,   50, '#8FA31E', '50–60 %'),
    ('gross_margin', 4,   60, '#27ae60', '60–70 %'),
    ('gross_margin', 5,   70, '#C9A227', '> 70 %'),
    -- Marge nette : fournie parce qu'elle partage la table.
    ('net_margin',   0, NULL, '#8B0000', 'Perte'),
    ('net_margin',   1,    0, '#dc3545', '0–5 %'),
    ('net_margin',   2,    5, '#e67e22', '5–10 %'),
    ('net_margin',   3,   10, '#8FA31E', '10–15 %'),
    ('net_margin',   4,   15, '#27ae60', '15–25 %'),
    ('net_margin',   5,   25, '#C9A227', '> 25 %');

-- Lecture de la bande d'une valeur (ex. marge brute 63,4 %) :
--   SELECT color, label FROM mac_kpi_threshold
--    WHERE metric = 'gross_margin' AND (min_pct IS NULL OR min_pct <= 63.4)
--    ORDER BY (min_pct IS NULL), min_pct DESC LIMIT 1;


-- ─────────────────── 2. Snapshot P&L mensuel par boutique ───────────────────
-- `material` porte le coût matière du mois : c'est LA colonne qui historise le
-- food cost (food_pct = material / ca * 100). Elle n'existe pas dans le projet
-- d'origine, où le food cost est recalculé via l'API à chaque affichage.

CREATE TABLE IF NOT EXISTS mac_shop_monthly_pnl (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop        BIGINT UNSIGNED NOT NULL,
    year           SMALLINT UNSIGNED NOT NULL,
    month          TINYINT UNSIGNED  NOT NULL,          -- 1..12
    ca             DECIMAL(14,2)  NULL,                 -- CA du mois (turnover)
    material       DECIMAL(14,2)  NULL,                 -- coût matière du mois (food cost €)
    labour         DECIMAL(14,2)  NULL,                 -- main d'œuvre du mois
    overhead       DECIMAL(14,2)  NULL,                 -- loyer, redevances, énergie, amortissements…
    net_result     DECIMAL(14,2)  NULL,                 -- ca − material − labour − overhead
    net_margin_pct DECIMAL(7,3)   NULL,                 -- net_result / ca * 100
    captured_at    DATETIME       NOT NULL,
    updated_at     DATETIME       NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_month (id_shop, year, month),
    KEY idx_shop_period (id_shop, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si la table existe déjà sans le coût matière :
-- ALTER TABLE mac_shop_monthly_pnl ADD COLUMN material DECIMAL(14,2) NULL AFTER ca;

-- Upsert mensuel (à appeler par un job, une fois le mois clôturé) :
--   INSERT INTO mac_shop_monthly_pnl
--          (id_shop, year, month, ca, material, labour, overhead, net_result, net_margin_pct, captured_at)
--   VALUES (?,?,?,?,?,?,?,?,?, NOW())
--   ON DUPLICATE KEY UPDATE
--          ca = VALUES(ca), material = VALUES(material), labour = VALUES(labour),
--          overhead = VALUES(overhead), net_result = VALUES(net_result),
--          net_margin_pct = VALUES(net_margin_pct), updated_at = NOW();

-- Food cost et marge brute historisés :
--   SELECT year, month,
--          material / NULLIF(ca, 0) * 100            AS food_pct,
--          100 - material / NULLIF(ca, 0) * 100      AS gross_pct
--     FROM mac_shop_monthly_pnl
--    WHERE id_shop = ? AND material IS NOT NULL
--    ORDER BY year, month;

-- Attention : ne compter que les mois CLÔTURÉS dans une moyenne. Le mois en
-- cours a un CA partiel et tire la moyenne vers le bas.


-- ─────────────────── 3. Casse / invendus (3e KPI du levier) ───────────────────
-- Saisie quotidienne boutique. Food cost corrigé = (matière + casse) ÷ CA.

CREATE TABLE IF NOT EXISTS waste_entry (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shop    INT UNSIGNED NOT NULL,
    entry_date DATE         NOT NULL,
    amount     DECIMAL(10,2) NOT NULL,   -- € valeur casse + invendus
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_date (id_shop, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Casse en % du CA sur une fenêtre (le CA vient de l'API, pas d'ici) :
--   SELECT SUM(amount) FROM waste_entry
--    WHERE id_shop = ? AND entry_date BETWEEN ? AND ?;
