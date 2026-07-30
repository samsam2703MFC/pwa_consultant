<?php
namespace App\Consultant\app\Http\Controllers\Trends;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopRepository;
use App\Consultant\app\Services\Trends\TrendsService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Section Tendances — CA consolidé du réseau (N, N-1, objectifs) sur les
 * 12 derniers mois + top 3 des meilleurs mois. La page se charge tout de
 * suite ; les données arrivent ensuite via /trends/data (beaucoup d'appels
 * API en parallèle côté serveur).
 */
class TrendsController extends Controller
{
    public function __construct(private TrendsService $trends) {}

    /** GET /trends — la page. */
    public function index(): void
    {
        $this->view('trends/index', []);
    }

    /** Cache serveur du résultat (les données sont réseau, communes à tous). */
    private const CACHE_TTL = 900; // 15 min

    /**
     * GET /trends/data — données consolidées (JSON).
     *
     * ?fresh=1  ignore le cache et réarme les endpoints batch
     * ?debug=1  ajoute le détail des sondes API (diagnostic en production)
     */
    public function data(): JsonResponse
    {
        // 24 fenêtres de ventes + 12 de targets par boutique : on laisse le
        // temps au repli SQL local si l'API ventes n'est pas disponible.
        @set_time_limit(180);

        $cacheFile = sys_get_temp_dir() . '/pwa_consultant_trends_cache.json';
        $fresh     = isset($_GET['fresh']);
        if ($fresh) {
            ShopRepository::batchBreakerReset();
        }
        if (!$fresh && !isset($_GET['debug']) && is_file($cacheFile)
            && (time() - (int)@filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['months'])) {
                return $this->json(['ok' => true, 'data' => $cached, 'cached' => true]);
            }
        }

        $t0 = microtime(true);
        try {
            $data = $this->trends->build();
            $data['elapsed_s'] = round(microtime(true) - $t0, 1);
            @file_put_contents($cacheFile, json_encode($data));
            $out = ['ok' => true, 'data' => $data];
        } catch (\Throwable $e) {
            // Throwable, pas Exception : un TypeError doit devenir un message
            // lisible sur la page, pas un 500 muet.
            error_log('[trends] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $stale = is_file($cacheFile)
                ? json_decode((string)@file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && !empty($stale['months'])) {
                $out = ['ok' => true, 'data' => $stale, 'stale' => true];
            } else {
                $out = [
                    'ok'        => false,
                    'data'      => null,
                    'error'     => get_class($e) . ' — ' . $e->getMessage()
                        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
                    'elapsed_s' => round(microtime(true) - $t0, 1),
                ];
            }
        }

        // Le diagnostic est joint AUTOMATIQUEMENT quand le résultat est
        // inexploitable : la page l'affiche alors telle quelle. Plus besoin de
        // demander à l'utilisateur d'ouvrir une URL à la main pour savoir quel
        // endpoint a lâché.
        $unusable = empty($out['ok']) || empty($out['data']['months']);
        if (isset($_GET['debug']) || $unusable) {
            $diag = ShopRepository::batchDiagnostics();
            $diag['shops_count'] = $out['data']['shops_count'] ?? null;
            $diag['elapsed_s']   = round(microtime(true) - $t0, 1);
            $out['debug'] = $diag;
        }
        return $this->json($out);
    }
}
