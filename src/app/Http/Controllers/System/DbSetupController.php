<?php
namespace App\Consultant\app\Http\Controllers\System;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Agenda\AgendaRepository;
use App\Consultant\app\Repositories\Kpi\KpiThresholdRepository;
use App\Consultant\app\Repositories\Param\ParamRepository;
use App\Consultant\app\Repositories\Valuation\PnlSnapshotRepository;
use App\Consultant\core\Db\Database;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provisioning des tables applicatives dans atelierby_db — GET /system/db-setup.
 *
 * Déclenche l'auto-création (CREATE TABLE IF NOT EXISTS + seeds) de toutes
 * les tables que l'app sait provisionner, avec les identifiants MySQL de
 * l'app, puis vérifie et rapporte l'état de chacune (existe / nb de lignes).
 * À ouvrir une fois après un déploiement ; si une table manque encore, le
 * compte n'a pas le privilège CREATE → exécuter database/*.sql via le DBA.
 */
class DbSetupController extends Controller
{
    public function __construct(
        private ParamRepository $params,
        private PnlSnapshotRepository $snapshots,
        private KpiThresholdRepository $kpiThresholds,
        private AgendaRepository $agenda,
    ) {}

    public function setup(): JsonResponse
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return $this->pretty([
                'ok'     => false,
                'db'     => 'indisponible',
                'hint'   => 'config/db.local.php absent ou connexion MySQL refusée.',
                'tables' => [],
            ]);
        }

        // Auto-création de tout ce que l'app sait provisionner (idempotent).
        $this->params->ensureSchema();          // mac_consultant_param
        $this->snapshots->ensureSchema();       // mac_shop_monthly_pnl (+ labour/overhead)
        $this->kpiThresholds->ensureSchema();   // mac_kpi_threshold
        $this->agenda->ensureSchema();          // mac_consultant_visit + mac_consultant_lever_action

        $tables = [
            'mac_consultant_param',
            'mac_shop_monthly_pnl',
            'mac_kpi_threshold',
            'mac_consultant_visit',
            'mac_consultant_lever_action',
        ];
        $out = [];
        foreach ($tables as $t) {
            try {
                $n = $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
                $out[$t] = ['exists' => true, 'rows' => (int)$n];
            } catch (\Throwable $e) {
                $out[$t] = ['exists' => false, 'error' => $e->getMessage()];
            }
        }
        // Colonnes ajoutées après coup (migration douce) : labour/overhead.
        if (!empty($out['mac_shop_monthly_pnl']['exists'])) {
            try {
                $pdo->query('SELECT labour, overhead FROM mac_shop_monthly_pnl LIMIT 1');
                $out['mac_shop_monthly_pnl']['labour_overhead'] = true;
            } catch (\Throwable $e) {
                $out['mac_shop_monthly_pnl']['labour_overhead'] = false;
            }
        }
        // Anciennes tables (sans préfixe mac_) : nombre de lignes, pour vérifier
        // que la migration douce a bien tout copié. Absentes = rien à migrer.
        $legacy = [
            'mac_consultant_param'        => 'consultant_param',
            'mac_shop_monthly_pnl'        => 'shop_monthly_pnl',
            'mac_kpi_threshold'           => 'kpi_threshold',
            'mac_consultant_visit'        => 'consultant_visit',
            'mac_consultant_lever_action' => 'consultant_lever_action',
        ];
        foreach ($legacy as $new => $old) {
            try {
                $out[$new]['legacy_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $old . '`')->fetchColumn();
            } catch (\Throwable $e) {
                // ancienne table absente — rien à signaler
            }
        }

        $allOk = true;
        foreach ($out as $st) {
            if (empty($st['exists'])) {
                $allOk = false;
            }
        }
        return $this->pretty([
            'ok'     => $allOk,
            'db'     => 'connectée',
            'tables' => $out,
            'hint'   => $allOk
                ? 'Toutes les tables sont en place.'
                : 'Table(s) manquante(s) : le compte MySQL de l\'app n\'a probablement pas le privilège CREATE — exécuter les fichiers database/*.sql via le DBA.',
        ]);
    }

    private function pretty(array $payload): JsonResponse
    {
        $r = $this->json($payload);
        $r->setEncodingOptions($r->getEncodingOptions() | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $r;
    }
}
