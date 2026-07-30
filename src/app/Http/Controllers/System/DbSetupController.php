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
        $this->params->ensureSchema();          // consultant_param
        $this->snapshots->ensureSchema();       // shop_monthly_pnl (+ labour/overhead)
        $this->kpiThresholds->ensureSchema();   // kpi_threshold
        $this->agenda->ensureSchema();          // consultant_visit + consultant_lever_action

        $tables = [
            'consultant_param',
            'shop_monthly_pnl',
            'kpi_threshold',
            'consultant_visit',
            'consultant_lever_action',
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
        if (!empty($out['shop_monthly_pnl']['exists'])) {
            try {
                $pdo->query('SELECT labour, overhead FROM shop_monthly_pnl LIMIT 1');
                $out['shop_monthly_pnl']['labour_overhead'] = true;
            } catch (\Throwable $e) {
                $out['shop_monthly_pnl']['labour_overhead'] = false;
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
