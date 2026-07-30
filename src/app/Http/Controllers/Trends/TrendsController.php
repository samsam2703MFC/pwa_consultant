<?php
namespace App\Consultant\app\Http\Controllers\Trends;

use App\Consultant\app\Http\Controllers\Controller;
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

    /** GET /trends/data — données consolidées (JSON). */
    public function data(): JsonResponse
    {
        // 24 fenêtres de ventes + 12 de targets par boutique : on laisse le
        // temps au repli SQL local si l'API ventes n'est pas disponible.
        @set_time_limit(180);

        $cacheFile = sys_get_temp_dir() . '/pwa_consultant_trends_cache.json';
        if (!isset($_GET['fresh']) && is_file($cacheFile)
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
            return $this->json(['ok' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            // Throwable, pas Exception : un TypeError doit devenir un message
            // lisible sur la page, pas un 500 muet.
            error_log('[trends] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $stale = is_file($cacheFile)
                ? json_decode((string)@file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && !empty($stale['months'])) {
                return $this->json(['ok' => true, 'data' => $stale, 'stale' => true]);
            }
            return $this->json([
                'ok'        => false,
                'data'      => null,
                'error'     => get_class($e) . ' — ' . $e->getMessage(),
                'elapsed_s' => round(microtime(true) - $t0, 1),
            ]);
        }
    }
}
