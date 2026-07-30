<?php
namespace App\Consultant\app\Http\Controllers\System;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Param\ParamRepository;
use App\Consultant\app\Services\Kpi\KpiThresholdService;
use App\Consultant\app\Services\Param\ParamService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Configuration applicative par ENDPOINTS — plus besoin d'accès SQL pour
 * ajuster les paramètres (mac_consultant_param) ni les bandes de couleur
 * des marges (mac_kpi_threshold). Effet immédiat sur tous les écrans.
 *
 *   GET  /system/params           → liste clé / valeur / libellé
 *   POST /system/params           → { "key": "...", "value": "..." }
 *   GET  /system/kpi-thresholds   → bandes par métrique
 *   POST /system/kpi-thresholds   → { "metric": "net_margin",
 *                                     "bands": [ {"min":null,"color":"#8B0000","label":"Perte"}, … ] }
 */
class ConfigController extends Controller
{
    private const METRICS = ['net_margin', 'gross_margin'];

    public function __construct(
        private ParamService $params,
        private ParamRepository $paramRepo,
        private KpiThresholdService $kpiThresholds
    ) {}

    /** GET /system/params */
    public function params(): JsonResponse
    {
        return $this->pretty(['ok' => true, 'params' => $this->paramRepo->rows()]);
    }

    /** POST /system/params — { key, value } (clés connues uniquement). */
    public function saveParam(): JsonResponse
    {
        $in    = $this->body();
        $key   = (string)($in['key'] ?? '');
        $value = $in['value'] ?? null;
        if (!array_key_exists($key, ParamRepository::DEFAULTS)) {
            return $this->pretty([
                'ok'    => false,
                'error' => 'Clé inconnue. Clés valides : ' . implode(', ', array_keys(ParamRepository::DEFAULTS)) . '.',
            ], 422);
        }
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            return $this->pretty(['ok' => false, 'error' => 'Valeur numérique attendue.'], 422);
        }
        $ok = $this->params->set($key, (string)$value);
        return $this->pretty([
            'ok'     => $ok,
            'error'  => $ok ? null : 'Écriture impossible (base indisponible ?).',
            'params' => $this->paramRepo->rows(),
        ], $ok ? 200 : 500);
    }

    /** GET /system/kpi-thresholds */
    public function kpiThresholds(): JsonResponse
    {
        return $this->pretty(['ok' => true, 'thresholds' => $this->kpiThresholds->all()]);
    }

    /** POST /system/kpi-thresholds — { metric, bands: [{min, color, label}] }. */
    public function saveKpiThresholds(): JsonResponse
    {
        $in     = $this->body();
        $metric = (string)($in['metric'] ?? '');
        $bands  = $in['bands'] ?? null;
        if (!in_array($metric, self::METRICS, true)) {
            return $this->pretty(['ok' => false, 'error' => 'Métrique invalide. Valides : ' . implode(', ', self::METRICS) . '.'], 422);
        }
        if (!is_array($bands) || $bands === [] || count($bands) > 12) {
            return $this->pretty(['ok' => false, 'error' => 'bands : liste de 1 à 12 bandes attendue.'], 422);
        }
        $clean = [];
        foreach ($bands as $b) {
            if (!is_array($b)) {
                return $this->pretty(['ok' => false, 'error' => 'Chaque bande doit être un objet {min, color, label}.'], 422);
            }
            $min   = $b['min'] ?? null;
            $color = (string)($b['color'] ?? '');
            if ($min !== null && !is_numeric($min)) {
                return $this->pretty(['ok' => false, 'error' => 'min : nombre ou null (= -infini).'], 422);
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                return $this->pretty(['ok' => false, 'error' => 'color : format #rrggbb attendu (reçu « ' . $color . ' »).'], 422);
            }
            $clean[] = [
                'min'   => $min !== null ? (float)$min : null,
                'color' => $color,
                'label' => (string)($b['label'] ?? ''),
            ];
        }
        // Tri par borne basse (null = -infini en premier) → l'ordre stocké est
        // toujours cohérent avec la règle « plus grande borne ≤ valeur ».
        usort($clean, fn($a, $b2) => ($a['min'] ?? -INF) <=> ($b2['min'] ?? -INF));

        $ok = $this->kpiThresholds->replaceBands($metric, $clean);
        return $this->pretty([
            'ok'         => $ok,
            'error'      => $ok ? null : 'Écriture impossible (base indisponible ?).',
            'thresholds' => $this->kpiThresholds->all(),
        ], $ok ? 200 : 500);
    }

    /** Corps JSON (ou formulaire en repli). */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $j   = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        return $_POST;
    }

    private function pretty(array $payload, int $status = 200): JsonResponse
    {
        $r = $this->json($payload, $status);
        $r->setEncodingOptions($r->getEncodingOptions() | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $r;
    }
}
