<?php
namespace App\Consultant\app\Http\Controllers\System;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Perf\PerfRepository;
use App\Consultant\app\Services\Param\ParamService;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Ce que coûtent réellement les écrans — /system/perf.
 *
 * Trois portes :
 *   • POST /system/perf/beacon — le navigateur rapporte le temps VÉCU ;
 *   • GET  /system/perf/data   — les mesures agrégées, en JSON ;
 *   • GET  /system/perf        — la heatmap écran × heure.
 *
 * La heatmap répond à une question précise : QUAND et OÙ l'application est
 * lente. Un écran lent à 7 h (le consultant ouvre sa journée, tout est froid)
 * et rapide à 11 h ne se corrige pas comme un écran lent en permanence : le
 * premier est un problème de cache, le second un problème de requêtes.
 */
class PerfController extends Controller
{
    public function __construct(
        private PerfRepository $perf,
        private ParamService $params,
    ) {}

    /**
     * Le temps vécu, renvoyé par la page elle-même (navigator.sendBeacon).
     *
     * Réponse toujours 204 : une balise n'a personne pour lire une erreur, et
     * un échec de mesure ne doit rien apprendre à un appelant hostile.
     */
    public function beacon(): JsonResponse
    {
        $raw  = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : $_POST;
        $rid  = (string)($body['rid'] ?? '');
        $ms   = (int)($body['ms'] ?? 0);

        if (preg_match('/^[a-f0-9]{16}$/', $rid) === 1) {
            $this->perf->completeClient($rid, $ms);
        }

        return $this->json(null, 204);
    }

    /** Les mesures agrégées — de quoi tracer la heatmap ailleurs si besoin. */
    public function data(): JsonResponse
    {
        [$from, $to] = $this->fenetre();
        $cells  = $this->perf->heatmap($from, $to);
        $routes = $this->parEcran($this->perf->samples($from, $to));

        $r = $this->json([
            'ok'      => $this->perf->available(),
            'window'  => ['from' => $from, 'to' => $to],
            'total'   => $this->perf->count(),
            'seuils'  => [
                'ok_ms'   => $this->params->getInt('perf_ok_ms', 800),
                'slow_ms' => $this->params->getInt('perf_slow_ms', 2500),
            ],
            'cells'   => $cells,
            'routes'  => $routes,
            'error'   => $this->perf->lastError,
        ]);
        $r->setEncodingOptions($r->getEncodingOptions() | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $r;
    }

    /** La heatmap, à l'écran. */
    public function page(): void
    {
        [$from, $to] = $this->fenetre();
        $cells   = $this->perf->heatmap($from, $to);
        $ecrans  = $this->parEcran($this->perf->samples($from, $to));

        $okMs   = max(1, $this->params->getInt('perf_ok_ms', 800));
        $slowMs = max($okMs + 1, $this->params->getInt('perf_slow_ms', 2500));

        // Les heures réellement observées : afficher 0 h → 23 h alors que
        // personne ne travaille la nuit noierait la carte de cases vides.
        $heures = [];
        $grille = [];
        foreach ($cells as $c) {
            $heures[$c['hour']] = true;
            $k = $c['route'] . '|' . $c['hour'];
            if (!isset($grille[$k])) {
                $grille[$k] = ['n' => 0, 'som' => 0, 'calls' => 0.0];
            }
            $grille[$k]['n']     += $c['n'];
            $grille[$k]['som']   += $c['avg_ms'] * $c['n'];
            $grille[$k]['calls'] += $c['avg_calls'] * $c['n'];
        }
        ksort($heures);
        $heures = array_keys($heures);

        $lignes = [];
        foreach ($ecrans as $e) {
            $cases = [];
            foreach ($heures as $h) {
                $g = $grille[$e['route'] . '|' . $h] ?? null;
                $cases[] = $g === null || $g['n'] === 0
                    ? ['vide' => true, 'hour' => $h]
                    : [
                        'vide'  => false,
                        'hour'  => $h,
                        'n'     => $g['n'],
                        'ms'    => (int)round($g['som'] / $g['n']),
                        'calls' => round($g['calls'] / $g['n'], 1),
                        'ton'   => $this->ton((int)round($g['som'] / $g['n']), $okMs, $slowMs),
                    ];
            }
            $lignes[] = ['route' => $e['route'], 'n' => $e['n'], 'cases' => $cases];
        }

        $this->view('system/perf', [
            'active_nav' => 'system',
            'from'       => $from,
            'to'         => $to,
            'heures'     => $heures,
            'lignes'     => $lignes,
            'ecrans'     => $ecrans,
            'total'      => $this->perf->count(),
            'ok_ms'      => $okMs,
            'slow_ms'    => $slowMs,
            'db_error'   => $this->perf->available() ? null : ($this->perf->lastError ?: 'base injoignable'),
        ]);
    }

    /**
     * Le teint d'une case : 0 = confortable, 4 = franchement lent.
     * Cinq paliers suffisent — une échelle continue se lit moins bien qu'un
     * escalier dont on connaît les marches.
     */
    private function ton(int $ms, int $ok, int $lent): int
    {
        if ($ms <= $ok)               { return 0; }
        if ($ms >= $lent)             { return 4; }
        $p = ($ms - $ok) / max(1, $lent - $ok);
        return $p < 0.34 ? 1 : ($p < 0.67 ? 2 : 3);
    }

    /**
     * Le résumé par écran : médiane, 95ᵉ centile, appels API, part de cache.
     *
     * La MOYENNE d'un temps de réponse ment — un seul affichage à 12 s tire
     * tout un écran vers le haut. La médiane dit l'ordinaire, le 95ᵉ centile
     * dit le mauvais jour. Les deux ensemble décident s'il faut agir.
     *
     * @param array<int, array> $samples
     * @return array<int, array>
     */
    private function parEcran(array $samples): array
    {
        $par = [];
        foreach ($samples as $s) {
            $r = $s['route'];
            if (!isset($par[$r])) {
                $par[$r] = ['ms' => [], 'api_ms' => 0, 'calls' => 0, 'cached' => 0];
            }
            $par[$r]['ms'][]    = $s['ms'];
            $par[$r]['api_ms'] += $s['api_ms'];
            $par[$r]['calls']  += $s['api_calls'];
            $par[$r]['cached'] += $s['api_cached'];
        }

        $out = [];
        foreach ($par as $route => $d) {
            $n = count($d['ms']);
            sort($d['ms']);
            $servis = $d['calls'] + $d['cached'];
            $out[] = [
                'route'      => $route,
                'n'          => $n,
                'p50'        => $this->centile($d['ms'], 50),
                'p95'        => $this->centile($d['ms'], 95),
                'api_ms_avg' => (int)round($d['api_ms'] / max(1, $n)),
                'calls_avg'  => round($d['calls'] / max(1, $n), 1),
                'cache_pct'  => $servis > 0 ? (int)round($d['cached'] / $servis * 100) : null,
            ];
        }

        // Le plus lourd d'abord : ce qui pèse, c'est la fréquence × le temps.
        usort($out, fn($a, $b) => ($b['p50'] * $b['n']) <=> ($a['p50'] * $a['n']));
        return $out;
    }

    /** @param int[] $tries valeurs DÉJÀ triées */
    private function centile(array $tries, int $p): int
    {
        $n = count($tries);
        if ($n === 0) {
            return 0;
        }
        $i = (int)ceil($p / 100 * $n) - 1;
        return (int)$tries[max(0, min($n - 1, $i))];
    }

    /** @return array{0:string,1:string} */
    private function fenetre(): array
    {
        $jours = max(1, min(365, (int)($_GET['days'] ?? $this->params->getInt('perf_window_days', 14))));
        $to    = new DateTimeImmutable('today');
        $from  = $to->modify('-' . ($jours - 1) . ' days');

        if (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])) {
            $from = new DateTimeImmutable((string)$_GET['from']);
        }
        if (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])) {
            $to = new DateTimeImmutable((string)$_GET['to']);
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }
}
