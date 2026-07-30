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
     * Le résultat porte-t-il un chiffre d'affaires exploitable ?
     *
     * `months` compte toujours douze entrées, même quand aucune donnée n'a été
     * trouvée : sa seule présence ne dit rien. C'est ce critère qui décide de
     * la mise en cache, du repli sur le cache périmé et de l'affichage du
     * diagnostic.
     */
    private static function hasSales(?array $data): bool
    {
        foreach (($data['months'] ?? []) as $m) {
            if (($m['ca_n'] ?? null) !== null || ($m['ca_n1'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

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
            if (is_array($cached) && self::hasSales($cached)) {
                return $this->json(['ok' => true, 'data' => $cached, 'cached' => true]);
            }
        }

        $t0 = microtime(true);
        try {
            $data = $this->trends->build();
            $data['elapsed_s'] = round(microtime(true) - $t0, 1);
            // On ne met en cache QUE des données exploitables. Un résultat vide
            // — API muette au moment du calcul — était figé 15 minutes et
            // resservi comme un succès : la page restait vide sans rien dire,
            // et le diagnostic ne se déclenchait pas.
            if (self::hasSales($data)) {
                @file_put_contents($cacheFile, json_encode($data));
            } else {
                @unlink($cacheFile);
            }
            $out = ['ok' => true, 'data' => $data];
        } catch (\Throwable $e) {
            // Throwable, pas Exception : un TypeError doit devenir un message
            // lisible sur la page, pas un 500 muet.
            error_log('[trends] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $stale = is_file($cacheFile)
                ? json_decode((string)@file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && self::hasSales($stale)) {
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
        // « months » est TOUJOURS rempli — douze lignes, éventuellement toutes à
        // null. Le seul critère qui distingue un écran utile d'un écran vide est
        // la présence d'un chiffre d'affaires.
        $unusable = empty($out['ok']) || !self::hasSales($out['data'] ?? null);
        if (isset($_GET['debug']) || $unusable) {
            $diag = ShopRepository::batchDiagnostics();
            $diag['shops_count'] = $out['data']['shops_count'] ?? null;
            $diag['elapsed_s']   = round(microtime(true) - $t0, 1);
            $out['debug'] = $diag;
        }
        return $this->json($out);
    }
}
