-- ---------------------------------------------------------------------------
-- Paramètres configurables (atelierby_db) — AUCUNE constante codée en dur.
--
-- Table clé/valeur lue par l'application (ex. valorisation : multiple,
-- marge nette cible). Modifiable sans redéploiement.
--
-- L'application tente un CREATE + un seed (INSERT IGNORE) au premier accès
-- (ParamRepository::ensureSchema). Si le compte applicatif n'a pas le
-- privilège CREATE, exécuter ce fichier via le DBA.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mac_consultant_param (
    param_key   VARCHAR(64)  NOT NULL,
    param_value VARCHAR(255) NOT NULL,
    label       VARCHAR(190) NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (param_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valeurs initiales (modifiables ensuite en base) :
--
-- Créneaux de la heatmap de rentabilité : bornes INCLUSES, en tranches
-- horaires — la borne 10 couvre 10:00 → 10:59. Les heures hors de ces trois
-- plages ne sont comptées dans aucun créneau (ni CA, ni coûts).
INSERT IGNORE INTO mac_consultant_param (param_key, param_value, label) VALUES
    ('valuation_multiple',              '4.5', 'Multiple de valorisation (× résultat net)'),
    ('valuation_target_net_margin_pct', '15',  'Marge nette cible (%) — valorisation à l''objectif'),
    ('daypart_morning_from',            '6',   'Heatmap : début du créneau « matin » (heure incluse)'),
    ('daypart_morning_to',              '10',  'Heatmap : fin du créneau « matin » (heure incluse)'),
    ('daypart_midday_from',             '11',  'Heatmap : début du créneau « midi » (heure incluse)'),
    ('daypart_midday_to',               '14',  'Heatmap : fin du créneau « midi » (heure incluse)'),
    ('daypart_afternoon_from',          '15',  'Heatmap : début du créneau « après-midi » (heure incluse)'),
    ('daypart_afternoon_to',            '19',  'Heatmap : fin du créneau « après-midi » (heure incluse)'),
    ('trends_budget_seconds',           '30',  'Tendances : budget de temps (s) — au-delà, le CA est rendu sans les objectifs');

-- Clés remplacées par les bornes début/fin ci-dessus. L'application ne les lit
-- plus et les masque de l'écran de configuration ; à supprimer quand vous
-- voulez :
-- DELETE FROM mac_consultant_param
--  WHERE param_key IN ('daypart_morning_until', 'daypart_midday_until');

-- Migration depuis l'ancienne table (si elle existe) — l'app la fait aussi
-- automatiquement au premier accès :
-- INSERT IGNORE INTO mac_consultant_param SELECT * FROM consultant_param;
