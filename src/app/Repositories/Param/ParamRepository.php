<?php
namespace App\Consultant\app\Repositories\Param;

use App\Consultant\core\Db\Database;
use App\Consultant\core\Db\LegacyTableMigration;
use PDO;
use Throwable;

/**
 * Paramètres configurables (table mac_consultant_param) — clé/valeur.
 *
 * Objectif : AUCUNE constante métier codée en dur. Les valeurs (ex. multiple
 * de valorisation, marge cible) vivent en base et sont modifiables sans
 * redéploiement. Dégradation propre si la base est indisponible.
 *
 * Les valeurs par défaut ci-dessous sont des SEEDS (insérés une seule fois si
 * absents), pas des constantes de calcul : le code lit toujours la base.
 */
class ParamRepository
{
    use LegacyTableMigration;

    /** Paramètres connus : clé => [valeur initiale, libellé]. Sert au seed. */
    public const DEFAULTS = [
        'valuation_multiple'              => ['4.5', 'Multiple de valorisation (× résultat net)'],
        'valuation_target_net_margin_pct' => ['15',  'Marge nette cible (%) — valorisation à l\'objectif'],
        // Marge nette = CA − matière − main d'œuvre − frais généraux. Au-delà de
        // cette borne, c'est qu'un poste de coût manque au P&L : signalé à
        // l'écran, jamais corrigé en douce.
        'valuation_max_net_margin_pct'    => ['15',  'Marge nette plausible maximale (%) — au-delà, un poste de coût manque au P&L'],
        // Le CA annuel porte sur les 12 derniers mois CLÔTURÉS : le mois en
        // cours est partiel, le compter comme un mois plein tirerait la
        // moyenne vers le bas. La marge, elle, est celle du mois précédent.
        'valuation_window_months'         => ['12', 'Valorisation : nombre de mois clôturés observés pour le CA annuel'],
        'valuation_annual_months'         => ['12', 'Valorisation : durée sur laquelle le CA observé est ramené (mois)'],
        // Créneaux de la heatmap de rentabilité : bornes INCLUSES, exprimées en
        // tranches horaires (la borne 10 couvre 10:00 → 10:59). Les heures hors
        // de ces trois plages ne sont comptées dans aucun créneau.
        'daypart_morning_from'            => ['6',  'Heatmap : début du créneau « matin » (heure incluse)'],
        'daypart_morning_to'              => ['10', 'Heatmap : fin du créneau « matin » (heure incluse)'],
        'daypart_midday_from'             => ['11', 'Heatmap : début du créneau « midi » (heure incluse)'],
        'daypart_midday_to'               => ['14', 'Heatmap : fin du créneau « midi » (heure incluse)'],
        'daypart_afternoon_from'          => ['15', 'Heatmap : début du créneau « après-midi » (heure incluse)'],
        'daypart_afternoon_to'            => ['19', 'Heatmap : fin du créneau « après-midi » (heure incluse)'],
        'trends_budget_seconds'           => ['30', 'Tendances : budget de temps (s) — au-delà, le CA est rendu sans les objectifs'],
        // Le mois en cours s'arrête au dernier jour COMPLET : une journée à
        // moitié écoulée face à des journées pleines de l'an dernier
        // sous-estime le mois. Mettre 1 si l'API remonte le jour courant en
        // temps réel et que vous voulez le voir.
        'trends_count_today'              => ['0',  'Tendances : compter la journée en cours dans le mois courant (0 = s\'arrêter à hier)'],
        // Vide = tout utilisateur ayant accès à l'écran peut valider un contrôle.
        // Renseigner un code de permission restreint la case à ce rôle (Owner).
        'owner_validation_permission'     => ['',   'Checklists : permission requise pour valider un contrôle consultant (vide = tous)'],
        // Gravité d'une non-conformité : elle vient de la NOTE du consultant
        // (1–5). En deçà de ce seuil (inclus), la NC est majeure. C'est une
        // décision de méthode, pas une constante du code.
        'checklist_nc_major_max_rating'   => ['2',  'Checklists : note (1–5) en deçà de laquelle une non-conformité est majeure'],
        // Le consultant contrôle des dizaines de tâches par boutique : lui
        // faire poser une note ET un verdict pour chacune double le geste.
        // « Conforme » et « Non conforme » posent donc une note d'office, qu'il
        // reste libre de corriger aux étoiles. Attention : la note KO commande
        // la GRAVITÉ (voir checklist_nc_major_max_rating) — à 2, toute
        // non-conformité est majeure par défaut.
        'checklist_review_rating_ok'      => ['4',  'Checklists : note posée d\'office par le bouton « Conforme » (0 = aucune)'],
        'checklist_review_rating_ko'      => ['2',  'Checklists : note posée d\'office par le bouton « Non conforme » (0 = aucune)'],
        // Code couleur du rapport Checklist : au-dessus du premier seuil c'est
        // vert, au-dessus du second orange, en dessous rouge.
        // Ce que le consultant évalue vraiment : une tâche qui exige une photo
        // (sans preuve, il n'y a rien à juger) ET qui est obligatoire. Les
        // autres tâches faites s'affichent « Faite », sans rien réclamer.
        // Mettre 0 à l'une des deux clés élargit la file d'un cran.
        'checklist_review_needs_photo'     => ['1', 'Checklists : n\'évaluer que les tâches exigeant une photo'],
        'checklist_review_needs_mandatory' => ['1', 'Checklists : n\'évaluer que les tâches obligatoires'],
        // Un lien de partage donne accès au rapport SANS authentification :
        // sa durée de vie est une décision de sécurité, pas une constante.
        'report_share_days'               => ['14', 'Partage de rapport : durée de validité du lien (jours)'],
        // L'écran « toutes les tâches » interroge trois endpoints par boutique.
        // Un parc qui grandit ne doit pas le transformer en attente : au-delà
        // de ce nombre, la liste est tronquée et le dit à l'écran.
        'checklist_all_tasks_max_shops'   => ['30', 'Toutes les tâches : nombre de boutiques lues au maximum'],
        // Accueil « ce qui manque » : le classement réseau couvre tout le parc
        // pour UN jour, donc un mois coûte un appel par jour. Les journées
        // closes sont conservées en base et ne se relisent plus ; sans base,
        // l'accueil se limite à une fenêtre courte et le dit à l'écran.
        'dashboard_shortfall_enabled'     => ['1',  'Accueil : afficher « ce qui manque » (tâches prévues non faites)'],
        'dashboard_shortfall_days_max'    => ['31', 'Accueil « ce qui manque » : journées relues au maximum par ouverture'],
        'dashboard_shortfall_days_nodb'   => ['7',  'Accueil « ce qui manque » : fenêtre (jours) quand la base ne peut rien conserver'],
        // Les premiers jours du mois, le mois en cours ne contient presque
        // rien : on glisse alors sur une fenêtre roulante, et l'écran le dit.
        'dashboard_shortfall_min_days'    => ['7',  'Accueil « ce qui manque » : en deçà de ce quantième, fenêtre roulante au lieu du mois'],
        'dashboard_shortfall_rolling_days'=> ['30', 'Accueil « ce qui manque » : longueur (jours) de la fenêtre roulante'],
        'checklist_green_pct'             => ['90', 'Rapport checklists : seuil vert (% de tâches faites)'],
        'checklist_orange_pct'            => ['75', 'Rapport checklists : seuil orange (% de tâches faites)'],
        // Trois endpoints renvoient le CA d'une boutique (monthly-sales,
        // sales-kpis, pnl/monthly). En deçà de cet écart, on les considère
        // d'accord : un centime d'arrondi n'est pas une divergence.
        'diag_ca_tolerance_pct'           => ['0.5', 'Diagnostic CA : écart (%) en deçà duquel deux sources sont réputées d\'accord'],
        // Un rapport constant entre deux sources trahit une TVA. Liste des
        // facteurs à reconnaître, du plus courant au plus rare (BE : 6 %
        // alimentaire, 12 % restauration, 21 % standard).
        'diag_ca_vat_factors'             => ['1.06,1.12,1.21', 'Diagnostic CA : facteurs de TVA reconnus dans un rapport entre sources'],
        // Mesure des temps de rendu (mac_consultant_perf, écran /system/perf).
        // Tout jugement sur la vitesse de l'application repose sinon sur des
        // estimations. La mesure s'écrit APRÈS la réponse : elle ne ralentit
        // rien, et se coupe d'un seul paramètre si besoin.
        'perf_enabled'                    => ['1',  'Mesure des temps : enregistrer chaque affichage (0 = couper)'],
        'perf_sample_pct'                 => ['100','Mesure des temps : part des affichages enregistrés (%)'],
        'perf_retention_days'             => ['90', 'Mesure des temps : durée de conservation des mesures (jours)'],
        // Bornes de l'échelle de couleur : en deçà, c'est confortable ;
        // au-delà, l'écran est franchement lent. Ce sont des décisions de
        // service, pas des constantes du code.
        'perf_ok_ms'                      => ['800', 'Mesure des temps : en deçà de ce temps (ms), un écran est confortable'],
        'perf_slow_ms'                    => ['2500','Mesure des temps : au-delà de ce temps (ms), un écran est lent'],
        'perf_window_days'                => ['14',  'Mesure des temps : fenêtre affichée par défaut (jours)'],
        // Bouton « Corriger » du formulaire de note (API Claude). La clé vit
        // dans config/anthropic.local.php, hors Git ; sans elle le bouton ne
        // s'affiche pas. Ces réglages-ci décident du COÛT et de la LATENCE.
        'note_ai_enabled'                 => ['1',   'Notes : proposer le bouton « Corriger » (relecture orthographique)'],
        // Le modèle est un réglage, pas une constante : on en change sans
        // redéployer, et l'écran de configuration liste les valeurs possibles.
        'note_ai_model'                   => ['claude-sonnet-5', 'Notes « Corriger » : modèle Claude appelé'],
        // Une correction n'a rien à raisonner : l'effort le plus bas évite
        // d'attendre une réflexion inutile. Vide = paramètre omis (modèles qui
        // ne le connaissent pas).
        'note_ai_effort'                  => ['low', 'Notes « Corriger » : effort de raisonnement (low/medium/high, vide = non transmis)'],
        // Au-delà, on refuse plutôt que de tronquer : une note coupée en deux
        // serait pire que la faute qu'on venait réparer.
        'note_ai_max_chars'               => ['4000','Notes « Corriger » : longueur maximale acceptée (caractères)'],
        'note_ai_max_tokens'              => ['2000','Notes « Corriger » : longueur maximale de la réponse (tokens)'],
        'note_ai_timeout'                 => ['20',  'Notes « Corriger » : délai d\'attente maximal (secondes)'],
    ];

    /**
     * Clés retirées : l'application ne les lit plus (les créneaux ont des
     * bornes début/fin explicites depuis daypart_*_from/_to). Les lignes
     * restent en base — on les masque simplement pour que l'écran de
     * configuration ne propose pas de réglages sans effet. Le DBA peut les
     * supprimer avec le DELETE commenté dans database/mac_consultant_param.sql.
     */
    public const RETIRED = ['daypart_morning_until', 'daypart_midday_until',
                            // L'accueil n'affiche plus le détail par boutique.
                            'dashboard_shortfall_shops'];

    private bool $ready = false;

    protected function pdo(): ?PDO
    {
        return Database::pdo();
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->ready = true;
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS mac_consultant_param ('
                . 'param_key VARCHAR(64) NOT NULL,'
                . 'param_value VARCHAR(255) NOT NULL,'
                . 'label VARCHAR(190) NULL,'
                . 'updated_at DATETIME NULL,'
                . 'PRIMARY KEY (param_key)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Migration douce AVANT les seeds (conserve les valeurs perso).
            $this->migrateLegacyTable($pdo, 'consultant_param', 'mac_consultant_param');
            $st = $pdo->prepare('INSERT IGNORE INTO mac_consultant_param (param_key, param_value, label) VALUES (:k, :v, :l)');
            foreach (self::DEFAULTS as $key => [$val, $label]) {
                $st->execute([':k' => $key, ':v' => $val, ':l' => $label]);
            }
        } catch (Throwable $e) {
            error_log('[param] ensureSchema: ' . $e->getMessage());
        }
    }

    /**
     * Lignes complètes (clé, valeur, libellé) — pour l'endpoint de
     * configuration. Fusionne les défauts avec ce qui est en base.
     *
     * @return array<int, array{key: string, value: string, label: ?string}>
     */
    public function rows(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => [$v, $label]) {
            $out[$k] = ['key' => $k, 'value' => $v, 'label' => $label];
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo !== null) {
            try {
                foreach ($pdo->query('SELECT param_key, param_value, label FROM mac_consultant_param') as $r) {
                    if (in_array((string)$r['param_key'], self::RETIRED, true)) {
                        continue;
                    }
                    $out[(string)$r['param_key']] = [
                        'key'   => (string)$r['param_key'],
                        'value' => (string)$r['param_value'],
                        'label' => $r['label'] !== null ? (string)$r['label'] : (self::DEFAULTS[(string)$r['param_key']][1] ?? null),
                    ];
                }
            } catch (Throwable $e) {
                error_log('[param] rows: ' . $e->getMessage());
            }
        }
        return array_values($out);
    }

    /** @return array<string,string> map clé => valeur (fusionne les défauts). */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => [$v]) {
            $out[$k] = $v;
        }
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $out;
        }
        try {
            $rows = $pdo->query('SELECT param_key, param_value FROM mac_consultant_param')->fetchAll();
            foreach ($rows as $r) {
                if (in_array((string)$r['param_key'], self::RETIRED, true)) {
                    continue;
                }
                $out[(string)$r['param_key']] = (string)$r['param_value'];
            }
        } catch (Throwable $e) {
            error_log('[param] all: ' . $e->getMessage());
        }
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        $fallback = $default ?? (self::DEFAULTS[$key][0] ?? null);
        if ($pdo === null) {
            return $fallback;
        }
        try {
            $st = $pdo->prepare('SELECT param_value FROM mac_consultant_param WHERE param_key = :k');
            $st->execute([':k' => $key]);
            $v = $st->fetchColumn();
            return $v !== false ? (string)$v : $fallback;
        } catch (Throwable $e) {
            error_log('[param] get: ' . $e->getMessage());
            return $fallback;
        }
    }

    public function set(string $key, string $value, ?string $label = null): bool
    {
        $this->ensureSchema();
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO mac_consultant_param (param_key, param_value, label, updated_at) '
                . 'VALUES (:k, :v, :l, :now) '
                . 'ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_at = VALUES(updated_at)'
            );
            return $st->execute([
                ':k' => $key, ':v' => $value,
                ':l' => $label ?? (self::DEFAULTS[$key][1] ?? null),
                ':now' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[param] set: ' . $e->getMessage());
            return false;
        }
    }
}
