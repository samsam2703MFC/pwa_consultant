<?php
namespace App\Consultant\core\Support;

use App\Consultant\app\Repositories\Perf\PerfRepository;
use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\core\Http\ApiClient;
use Throwable;

/**
 * Chronomètre de la requête — une ligne dans mac_consultant_perf par écran.
 *
 * MESURER NE DOIT PAS RALENTIR. Trois précautions, dans cet ordre :
 *   1. pendant la requête, on ne fait qu'un microtime() — rien d'autre ;
 *   2. l'écriture a lieu dans un register_shutdown_function(), donc APRÈS que
 *      la page est partie ; sous FPM, fastcgi_finish_request() rend la main au
 *      navigateur avant même la connexion à la base ;
 *   3. toute panne est avalée. Un incident de mesure ne casse pas un écran.
 *
 * L'écran est NORMALISÉ (/shops/{id}, pas /shops/42) : sans cela la heatmap
 * aurait une ligne par boutique et n'apprendrait rien. Seul /api-proxy garde
 * l'endpoint appelé — c'est justement ce qu'on cherche à voir pendant le
 * préchargement du login.
 */
class PerfRecorder
{
    private float $t0 = 0.0;
    private string $rid = '';
    private bool $armed = false;

    public function __construct(
        private PerfRepository $repo,
        private ParamService $params,
        private ApiClient $api,
    ) {}

    /** Le jeton qui relie cette page à la mesure du navigateur. */
    public function rid(): string
    {
        return $this->rid;
    }

    /**
     * Démarre le chronomètre. Appelé une fois, au tout début de la requête —
     * avant l'autoload des contrôleurs, pour que le temps mesuré soit celui
     * que l'utilisateur attend et non celui qu'on veut bien compter.
     */
    public function start(): void
    {
        if ($this->armed) {
            return;
        }
        $this->armed = true;
        $this->t0    = microtime(true);
        try {
            $this->rid = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $this->rid = substr(md5(uniqid('', true)), 0, 16);
        }
        GlobalRegistry::set('perf_rid', $this->rid);

        register_shutdown_function(function (): void {
            try {
                $this->finish();
            } catch (Throwable $e) {
                error_log('[perf] finish: ' . $e->getMessage());
            }
        });
    }

    private function finish(): void
    {
        // Rendre la main au navigateur AVANT de toucher la base.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        if ($this->params->getInt('perf_enabled', 1) !== 1) {
            return;
        }

        $route = $this->route();
        if ($route === '') {
            return;
        }
        // La balise qui rapporte le temps vécu ne se mesure pas elle-même :
        // elle ferait du bruit et n'apprendrait rien.
        if (str_starts_with($route, '/system/perf')) {
            return;
        }

        $taux = max(0, min(100, $this->params->getInt('perf_sample_pct', 100)));
        if ($taux === 0) {
            return;
        }
        if ($taux < 100) {
            try {
                if (random_int(1, 100) > $taux) {
                    return;
                }
            } catch (Throwable $e) {
                // tirage impossible : on mesure plutôt que de perdre la ligne
            }
        }

        $ms = (int)round((microtime(true) - $this->t0) * 1000);
        $s  = $this->api->stats();

        $now = new \DateTimeImmutable('now');
        $this->repo->record([
            'rid'        => $this->rid,
            'ts'         => $now->format('Y-m-d H:i:s'),
            'date'       => $now->format('Y-m-d'),
            'hour'       => (int)$now->format('G'),
            'dow'        => (int)$now->format('N'),
            'user_key'   => substr($this->api->userKey(), 0, 40),
            'route'      => $route,
            'method'     => substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8),
            'server_ms'  => min(16777215, max(0, $ms)),
            'api_ms'     => min(16777215, max(0, $s['ms'])),
            'api_calls'  => min(65535, $s['calls']),
            'api_cached' => min(65535, $s['cached']),
            'api_failed' => min(65535, $s['failed']),
        ]);

        // Purge de loin en loin : la rétention doit se tenir toute seule, sans
        // tâche planifiée à installer sur le serveur.
        try {
            if (random_int(1, 200) === 1) {
                $this->repo->purgeOlderThan($this->params->getInt('perf_retention_days', 90));
            }
        } catch (Throwable $e) {
            // sans importance : la purge repassera
        }
    }

    /**
     * L'écran, sous une forme qui se regroupe : les identifiants deviennent
     * {id}, les jetons {token}.
     */
    public function route(): string
    {
        $uri = '/' . trim((string)($_GET['url'] ?? ''), '/');
        if ($uri === '/') {
            $uri = '/dashboard';
        }
        $norm = $this->normalise($uri);

        // Le proxy n'est pas un écran : ce qui coûte, c'est l'endpoint qu'il
        // relaie. Sans cette précision, tout le préchargement du login se
        // résumerait à une seule ligne « /api-proxy ».
        if ($norm === '/api-proxy' && isset($_GET['endpoint']) && is_string($_GET['endpoint'])) {
            $norm .= ':' . $this->normalise($_GET['endpoint']);
        }

        return substr($norm, 0, 160);
    }

    private function normalise(string $path): string
    {
        $path = strtok($path, '?') ?: $path;
        $out  = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '') {
                $out[] = '';
                continue;
            }
            if (ctype_digit($seg)) {
                $out[] = '{id}';
            } elseif (preg_match('/^[a-f0-9]{16,}$/i', $seg)) {
                $out[] = '{token}';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $seg)) {
                $out[] = '{date}';
            } else {
                $out[] = preg_replace('/[^a-zA-Z0-9._{}-]/', '', $seg);
            }
        }
        return implode('/', $out);
    }
}
