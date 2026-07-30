<?php
namespace App\Consultant\app\Http\Controllers\Valuation;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopRepository;
use App\Consultant\app\Services\Valuation\ValuationService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Valorisation des boutiques / réseau (bouton « Valeurs réseau » de la section
 * Boutiques). Chargé à la demande (accordéon) pour ne pas alourdir la page.
 */
class ValuationController extends Controller
{
    public function __construct(private ValuationService $valuation) {}

    /** Cache serveur du résultat (données réseau, communes à tous). */
    private const CACHE_TTL = 900; // 15 min

    /**
     * Version du FORMAT de la réponse. À incrémenter dès que la structure ou
     * les règles de calcul changent.
     *
     * Sans cela, un déploiement corrigeant un calcul reste invisible jusqu'à
     * quinze minutes : l'ancienne réponse, toujours « exploitable », continue
     * d'être servie telle quelle. Le correctif semble alors n'avoir rien fait.
     */
    private const CACHE_VERSION = 3;

    private static function cachePath(): string
    {
        return sys_get_temp_dir() . '/pwa_consultant_valuation_cache_v' . self::CACHE_VERSION . '.json';
    }

    /**
     * Le résultat porte-t-il un P&L exploitable ?
     *
     * `shops` compte TOUJOURS une entrée par boutique, même quand aucune donnée
     * n'a été trouvée : sa seule présence ne dit rien. Sans ce critère, une
     * réponse vide — P&L mensuel muet au moment du calcul — était figée quinze
     * minutes et resservie comme un succès, y compris après le rétablissement
     * de l'endpoint.
     */
    private static function hasPnl(?array $data): bool
    {
        foreach (($data['shops'] ?? []) as $s) {
            if (!empty($s['months']) || ($s['avg_margin'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET /shops/valuation — données de valorisation (JSON).
     *
     * ?fresh=1  ignore le cache et réarme les endpoints batch
     * ?debug=1  ajoute le détail des sondes API (diagnostic en production)
     */
    public function data(): JsonResponse
    {
        // P&L du mois + historique 12 mois + CA 12 mois de chaque boutique :
        // sans marge de temps, un backend lent coupe la réponse au milieu.
        @set_time_limit(180);

        $cacheFile = self::cachePath();
        $fresh     = isset($_GET['fresh']);
        if ($fresh) {
            ShopRepository::batchBreakerReset();
        }
        // ?debug=1 → on garde la réponse BRUTE du P&L pour la première
        // boutique. Sans elle, « aucun mois renvoyé » se diagnostique à
        // l'aveugle. Le cache est ignoré dans ce mode (voir ci-dessous), donc
        // l'échantillon décrit bien l'appel qui vient d'avoir lieu.
        if (isset($_GET['debug'])) {
            ShopRepository::batchBreakerReset();
            ShopRepository::sampleOn();
        }
        if (!$fresh && !isset($_GET['debug']) && is_file($cacheFile)
            && (time() - (int)@filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && self::hasPnl($cached)) {
                return $this->json(['ok' => true, 'data' => $cached, 'cached' => true]);
            }
        }

        $t0 = microtime(true);
        try {
            $data = $this->valuation->build();
            $data['elapsed_s'] = round(microtime(true) - $t0, 1);
            // On ne met en cache QUE des données exploitables : figer une
            // réponse vide la ferait survivre au rétablissement de l'endpoint.
            if (self::hasPnl($data)) {
                @file_put_contents($cacheFile, json_encode($data));
            } else {
                @unlink($cacheFile);
            }
            $out = ['ok' => true, 'data' => $data];
        } catch (\Throwable $e) {
            // Throwable, pas Exception : un TypeError doit devenir un message
            // lisible sur la page, pas un 500 muet.
            error_log('[valuation] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $stale = is_file($cacheFile)
                ? json_decode((string)@file_get_contents($cacheFile), true) : null;
            if (is_array($stale) && self::hasPnl($stale)) {
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

        if (isset($_GET['debug'])) {
            $out['debug'] = ShopRepository::batchDiagnostics();
            $out['debug']['samples'] = ShopRepository::samples();
        }
        // Jamais de cache navigateur sur ces réponses : le cache serveur est
        // déjà là, versionné, et une couche de plus rendrait un correctif
        // invisible sans qu'on sache laquelle interroger.
        $res = $this->json($out);
        $res->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $res;
    }
}
