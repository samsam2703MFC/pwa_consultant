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
    ('valuation_max_net_margin_pct',    '15',  'Marge nette plausible maximale (%) — au-delà, un poste de coût manque au P&L'),
    ('valuation_window_months',         '12',  'Valorisation : nombre de mois clôturés observés pour le CA annuel'),
    ('valuation_annual_months',         '12',  'Valorisation : durée sur laquelle le CA observé est ramené (mois)'),
    ('daypart_morning_from',            '6',   'Heatmap : début du créneau « matin » (heure incluse)'),
    ('daypart_morning_to',              '10',  'Heatmap : fin du créneau « matin » (heure incluse)'),
    ('daypart_midday_from',             '11',  'Heatmap : début du créneau « midi » (heure incluse)'),
    ('daypart_midday_to',               '14',  'Heatmap : fin du créneau « midi » (heure incluse)'),
    ('daypart_afternoon_from',          '15',  'Heatmap : début du créneau « après-midi » (heure incluse)'),
    ('daypart_afternoon_to',            '19',  'Heatmap : fin du créneau « après-midi » (heure incluse)'),
    ('trends_budget_seconds',           '30',  'Tendances : budget de temps (s) — au-delà, le CA est rendu sans les objectifs'),
    ('trends_count_today',              '0',   'Tendances : compter la journée en cours dans le mois courant (0 = s''arrête à hier)'),
    ('owner_validation_permission',     '',    'Checklists : permission requise pour valider un contrôle consultant (vide = tous)'),
    ('checklist_nc_major_max_rating',   '2',   'Checklists : note (1–5) en deçà de laquelle une non-conformité est majeure'),
    ('checklist_review_rating_ok',      '4',   'Checklists : note posée d''office par le bouton « Conforme » (0 = aucune)'),
    ('checklist_review_rating_ko',      '2',   'Checklists : note posée d''office par le bouton « Non conforme » (0 = aucune)'),
    ('checklist_review_needs_photo',     '1',   'Checklists : n''évaluer que les tâches exigeant une photo'),
    ('checklist_review_needs_mandatory', '1',   'Checklists : n''évaluer que les tâches obligatoires'),
    ('report_share_days',               '14',  'Partage de rapport : durée de validité du lien (jours)'),
    ('checklist_all_tasks_max_shops',   '30',  'Toutes les tâches : nombre de boutiques lues au maximum'),
    ('dashboard_shortfall_enabled',     '1',   'Accueil : afficher « ce qui manque » (tâches prévues non faites)'),
    ('dashboard_shortfall_days_max',    '31',  'Accueil « ce qui manque » : journées relues au maximum par ouverture'),
    ('dashboard_shortfall_days_nodb',   '7',   'Accueil « ce qui manque » : fenêtre (jours) quand la base ne peut rien conserver'),
    ('dashboard_shortfall_min_days',    '7',   'Accueil « ce qui manque » : en deçà de ce quantième, fenêtre roulante au lieu du mois'),
    ('dashboard_shortfall_rolling_days','30',  'Accueil « ce qui manque » : longueur (jours) de la fenêtre roulante'),
    ('checklist_green_pct',             '90',  'Rapport checklists : seuil vert (% de tâches faites)'),
    ('checklist_orange_pct',            '75',  'Rapport checklists : seuil orange (% de tâches faites)'),
    ('diag_ca_tolerance_pct',           '0.5', 'Diagnostic CA : écart (%) en deçà duquel deux sources sont réputées d''accord'),
    ('diag_ca_vat_factors',             '1.06,1.12,1.21', 'Diagnostic CA : facteurs de TVA reconnus dans un rapport entre sources'),
    ('perf_enabled',                    '1',   'Mesure des temps : enregistrer chaque affichage (0 = couper)'),
    ('perf_sample_pct',                 '100', 'Mesure des temps : part des affichages enregistrés (%)'),
    ('perf_retention_days',             '90',  'Mesure des temps : durée de conservation des mesures (jours)'),
    ('perf_ok_ms',                      '800', 'Mesure des temps : en deçà de ce temps (ms), un écran est confortable'),
    ('perf_slow_ms',                    '2500','Mesure des temps : au-delà de ce temps (ms), un écran est lent'),
    ('perf_window_days',                '14',  'Mesure des temps : fenêtre affichée par défaut (jours)'),
    ('note_ai_enabled',                 '1',   'Notes : proposer le bouton « Corriger » (relecture orthographique)'),
    ('note_ai_model',                   'claude-sonnet-5', 'Notes « Corriger » : modèle Claude appelé'),
    ('note_ai_effort',                  'low', 'Notes « Corriger » : effort de raisonnement (low/medium/high, vide = non transmis)'),
    ('note_ai_max_chars',               '4000','Notes « Corriger » : longueur maximale acceptée (caractères)'),
    ('note_ai_max_tokens',              '2000','Notes « Corriger » : longueur maximale de la réponse (tokens)'),
    ('note_ai_timeout',                 '20',  'Notes « Corriger » : délai d''attente maximal (secondes)'),
    ('product_ref_enabled',             '1',   'Contrôle qualité : afficher la photo de la fiche technique en comparaison'),
    ('product_ref_endpoint',            '/products', 'Contrôle qualité : endpoint du catalogue produits (relatif à l''API)');

-- Clés remplacées par les bornes début/fin ci-dessus. L'application ne les lit
-- plus et les masque de l'écran de configuration ; à supprimer quand vous
-- voulez :
-- DELETE FROM mac_consultant_param
--  WHERE param_key IN ('daypart_morning_until', 'daypart_midday_until');

-- Migration depuis l'ancienne table (si elle existe) — l'app la fait aussi
-- automatiquement au premier accès :
-- INSERT IGNORE INTO mac_consultant_param SELECT * FROM consultant_param;
