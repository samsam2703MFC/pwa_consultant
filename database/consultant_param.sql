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

CREATE TABLE IF NOT EXISTS consultant_param (
    param_key   VARCHAR(64)  NOT NULL,
    param_value VARCHAR(255) NOT NULL,
    label       VARCHAR(190) NULL,
    updated_at  DATETIME     NULL,
    PRIMARY KEY (param_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valeurs initiales (modifiables ensuite en base) :
INSERT IGNORE INTO consultant_param (param_key, param_value, label) VALUES
    ('valuation_multiple',              '4.5', 'Multiple de valorisation (× résultat net)'),
    ('valuation_target_net_margin_pct', '15',  'Marge nette cible (%) — valorisation à l''objectif');
