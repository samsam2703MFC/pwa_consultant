<?php
namespace App\Consultant\app\Http\Controllers\Debug;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Param\ParamRepository;
use App\Consultant\app\Repositories\Shop\ShopSalesRepository;
use App\Consultant\app\Services\Shop\ShopService;
use App\Consultant\core\Cookie\CookieManager;
use App\Consultant\core\Http\ApiClient;
use App\Consultant\core\Support\Route;

/**
 * Diagnostic P&L — désactivé par défaut.
 * Activer avec `SetEnv PNL_DIAG 1` dans public/.htaccess, puis ouvrir
 *   /pwa_consultant/pnl-debug?shop=ID&period=month
 * Affiche la réponse brute de /consultant/shops/{id}/pnl (session connectée).
 * À RETIRER après diagnostic.
 */
class DebugController extends Controller
{
    public function __construct(
        private ApiClient $apiClient,
        private CookieManager $cookieManager,
        private ShopSalesRepository $shopSales,
        private ShopService $shopService,
        private ParamRepository $params,
    ) {}

    /**
     * Sonde générique : /api-debug?endpoint=/consultant/shops/summary
     * Affiche la réponse brute de n'importe quel endpoint /consultant/*.
     *
     * Cas particulier `?ca=1` : comparaison des trois sources de chiffre
     * d'affaires. Elle ne demande PAS le drapeau serveur — elle ne montre que
     * des montants que la session voit déjà sur Boutiques et Tendances, lus
     * sous trois angles. Le reste de la sonde renvoie des réponses brutes
     * arbitraires et reste derrière PNL_DIAG.
     */
    #[Route('GET', '/api-debug')]
    public function probe(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        if (($_GET['ca'] ?? '') === '1') {
            $this->caCompare();
            return;
        }
        $flag = $_SERVER['PNL_DIAG'] ?? $_ENV['PNL_DIAG'] ?? getenv('PNL_DIAG') ?: '0';
        if ($flag !== '1') { http_response_code(404); echo 'Not found'; return; }

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $endpoint = $_GET['endpoint'] ?? '';

        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<body style="font-family:ui-monospace,Menlo,monospace;max-width:900px;margin:24px auto;padding:0 16px;color:#241f1c;background:#F4EFE8;line-height:1.5">';
        echo '<h2 style="font-family:sans-serif">Sonde API</h2>';

        if (!str_starts_with($endpoint, '/consultant/')) {
            echo '<p>Ajoute <code>?endpoint=/consultant/…</code> (préfixe /consultant/ requis).</p>';
            echo '<p>Ex : <code>/pwa_consultant/api-debug?endpoint=/consultant/shops/summary</code></p>';
            echo '<p>Les paramètres de l’endpoint se passent à la suite :<br>'
               . '<code>?endpoint=/consultant/shops/4/pnl/monthly&amp;from=2025-07&amp;to=2026-06</code></p>';
            echo '<p>Comparaison des trois sources de CA : <code>?ca=1</code></p>';
            echo '</body>';
            return;
        }

        // Les autres paramètres de l'URL sont transmis à l'endpoint. Sans cela,
        // « ?endpoint=/…/pnl/monthly?from=X » se coupait au premier « ? » :
        // PHP lisait `from` comme un paramètre de la sonde, et l'endpoint
        // partait sans sa fenêtre de dates — donc sans réponse utile.
        $extra = $_GET;
        unset($extra['endpoint']);
        if ($extra !== []) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($extra);
        }

        $resp = $this->apiClient->get($endpoint);
        $data = $resp['data'] ?? [];
        echo '<p><b>Endpoint</b> : ' . $esc(API_BASE_URL . $endpoint) . '</p>';
        echo '<p><b>success</b> : ' . ($resp['success'] ? '✅' : '❌ (HTTP ' . $esc($resp['error'] ?? '?') . ')') . '</p>';
        if (is_array($data)) {
            $keys = array_keys($data);
            echo '<p><b>Clés racine</b> : ' . $esc(implode(', ', array_map('strval', $keys))) . '</p>';
            // Si tableau de sklepów, montre les clés du 1er élément.
            if (isset($data[0]) && is_array($data[0])) {
                echo '<p><b>Clés du 1er élément</b> : ' . $esc(implode(', ', array_keys($data[0]))) . '</p>';
            }
        }
        echo '<h3 style="font-family:sans-serif">Réponse brute</h3>';
        echo '<pre style="white-space:pre-wrap;background:#fff;padding:12px;border-radius:8px;font-size:12px">'
           . $esc(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</pre>';
        echo '<p style="font-family:sans-serif;color:#8D1D2C">⚠️ Diagnostic temporaire — retire <code>SetEnv PNL_DIAG 1</code> après usage.</p>';
        echo '</body>';
    }

    /**
     * Trois endpoints renvoient le chiffre d'affaires d'une boutique. Pour une
     * même boutique et un même mois CLOS, ils ne donnent pas toujours le même
     * nombre — et le panneau n'a aucune règle pour arbitrer : il code une
     * préférence par écran. Cette page pose les trois côte à côte pour que la
     * question se règle sur des chiffres et non sur des impressions.
     *
     *   /api-debug?ca=1[&month=YYYY-MM][&shop=ID]
     *
     * Trois allers-retours pour tout le réseau : P1 et P3 couvrent toutes les
     * boutiques en un appel chacun, P2 part en parallèle.
     */
    private function caCompare(): void
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $eur = fn(?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ');
        $pct = fn(?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' %';

        // Mois CLOS par défaut : le mois en cours est partiel, et trois sources
        // arrêtées à trois instants différents ne se comparent pas.
        $ym = (string)($_GET['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            $ym = (new \DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m');
        }
        $from = $ym . '-01';
        $to   = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $only = (int)($_GET['shop'] ?? 0);

        // Une valeur vidée en base ne doit pas devenir une tolérance de zéro :
        // tout écarterait alors, y compris un centime d'arrondi.
        $tol = (float)($this->params->get('diag_ca_tolerance_pct') ?? '');
        if ($tol <= 0) {
            $tol = (float)ParamRepository::DEFAULTS['diag_ca_tolerance_pct'][0];
        }
        $vat = array_values(array_filter(array_map(
            'floatval',
            explode(',', (string)($this->params->get('diag_ca_vat_factors', '') ?? ''))
        ), fn($f) => $f > 1));

        $names = [];
        foreach ($this->shopService->getAllShops() as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0 && ($only === 0 || $id === $only)) {
                $names[$id] = (string)($s['representative_name'] ?? $s['name'] ?? ('#' . $id));
            }
        }
        $ids = array_keys($names);

        $serie = $this->shopService->getMonthlySalesAllShops($ym, $ym);        // P1
        $kpis  = $this->shopService->getSalesKpisAllShops($from, $to);         // P3
        $pnl   = $ids !== [] ? $this->shopService->getMonthlyPnlMany($ids, $ym, $ym) : []; // P2

        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<style>'
           . 'body{font-family:ui-monospace,Menlo,monospace;max-width:1000px;margin:20px auto;padding:0 14px;'
           . 'color:#241f1c;background:#F4EFE8;line-height:1.5}'
           . 'h2,h3,p.s{font-family:system-ui,sans-serif}'
           . '.wrap{overflow-x:auto;background:#fff;border-radius:10px}'
           . 'table{border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap}'
           . 'th{background:#8D1D2C;color:#fff;text-align:left;padding:7px 9px;font-weight:600}'
           . 'td{padding:6px 9px;border-top:1px solid #eee;text-align:right}'
           // Sur un téléphone, les trois montants sortent de l'écran : le nom
           // reste accroché à gauche pendant qu'on fait défiler les colonnes,
           // sinon on lit des chiffres sans savoir de quelle boutique.
           . 'td.l,th:first-child{text-align:left;position:sticky;left:0;background:#fff;'
           . 'max-width:46vw;overflow:hidden;text-overflow:ellipsis}'
           . 'th:first-child{background:#8D1D2C}'
           . 'tr.tot td.l{background:#fff}'
           . 'tr.tot td{border-top:2px solid #241f1c;font-weight:700}'
           . '.ko{color:#8D1D2C;font-weight:700}.oky{color:#1c7a4a;font-weight:700}'
           . 'form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}'
           . 'input,button{min-height:44px;font-size:16px;border-radius:8px;border:1px solid #d8cfc4;padding:0 12px}'
           . 'button{background:#8D1D2C;color:#fff;border:none;min-width:110px;cursor:pointer}'
           . '.note{background:#fff;border-radius:10px;padding:12px 14px;margin-top:16px;font-family:system-ui,sans-serif}'
           . '</style><body>';
        echo '<h2>CA : trois sources, un seul mois</h2>';
        echo '<form method="get"><input type="hidden" name="ca" value="1">'
           . ($only > 0 ? '<input type="hidden" name="shop" value="' . $esc($only) . '">' : '')
           . '<label>Mois <input type="month" name="month" value="' . $esc($ym) . '"></label>'
           . '<button type="submit">Comparer</button></form>';

        $srcKo = [];
        if ($serie === null) { $srcKo[] = 'monthly-sales (P1)'; }
        if ($kpis  === null) { $srcKo[] = 'sales-kpis (P3)'; }
        if ($ids !== [] && $pnl === []) { $srcKo[] = 'pnl/monthly (P2)'; }
        if ($srcKo !== []) {
            echo '<p class="s ko">Source(s) sans réponse sur ce mois : ' . $esc(implode(', ', $srcKo))
               . '. Les colonnes correspondantes resteront vides — c\'est un résultat, pas une panne de la page.</p>';
        }

        echo '<div class="wrap"><table><tr>'
           . '<th>Boutique</th><th>monthly-sales</th><th>sales-kpis</th><th>pnl/monthly</th>'
           . '<th>écart</th><th>kpis / série</th><th>pnl / série</th></tr>';

        $tA = $tB = $tC = 0.0;   // totaux sur les boutiques où les TROIS répondent
        $complete = 0; $accord = 0;
        $rBA = []; $rCA = [];

        foreach ($names as $id => $name) {
            $a = isset($serie[$id][$ym]['ca']) ? (float)$serie[$id][$ym]['ca'] : null;
            $b = isset($kpis[$id]['ca'])       ? (float)$kpis[$id]['ca']       : null;
            $c = $pnl[$id][$ym]['turnover']    ?? null;
            $c = is_numeric($c) ? (float)$c : null;

            $vals = array_values(array_filter([$a, $b, $c], fn($v) => $v !== null && $v > 0));
            $gap  = count($vals) >= 2 && min($vals) > 0
                ? (max($vals) - min($vals)) / min($vals) * 100
                : null;
            $ok = $gap !== null && $gap <= $tol;

            if ($a !== null && $b !== null && $c !== null) {
                $complete++; $tA += $a; $tB += $b; $tC += $c;
                if ($ok) { $accord++; }
            }
            if ($a > 0 && $b !== null) { $rBA[] = $b / $a; }
            if ($a > 0 && $c !== null) { $rCA[] = $c / $a; }

            echo '<tr><td class="l" title="' . $esc($name) . '">' . $esc($name)
               . ' <span style="color:#a2988c">#' . $esc($id) . '</span></td>'
               . '<td>' . $esc($eur($a)) . '</td><td>' . $esc($eur($b)) . '</td><td>' . $esc($eur($c)) . '</td>'
               . '<td class="' . ($gap === null ? '' : ($ok ? 'oky' : 'ko')) . '">' . $esc($pct($gap)) . '</td>'
               . '<td>' . $esc($a > 0 && $b !== null ? number_format($b / $a, 4, ',', ' ') : '—') . '</td>'
               . '<td>' . $esc($a > 0 && $c !== null ? number_format($c / $a, 4, ',', ' ') : '—') . '</td></tr>';
        }

        echo '<tr class="tot"><td class="l">Total (' . $esc($complete) . ' boutique(s) à trois sources)</td>'
           . '<td>' . $esc($eur($tA)) . '</td><td>' . $esc($eur($tB)) . '</td><td>' . $esc($eur($tC)) . '</td>'
           . '<td colspan="3"></td></tr>';
        echo '</table></div>';

        // Le total ne porte que sur les boutiques où les trois sources ont
        // répondu : additionner des périmètres différents fabriquerait un écart
        // qui n'existe pas.
        $median = function (array $v): ?float {
            if ($v === []) { return null; }
            sort($v);
            $n = count($v);
            return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
        };
        $mBA = $median($rBA);
        $mCA = $median($rCA);

        echo '<div class="note">';
        echo '<p class="s"><b>Verdict</b> — ' . ($complete === 0
            ? 'aucune boutique n\'a ses trois sources sur ' . $esc($ym) . '.'
            : $esc($accord) . ' boutique(s) sur ' . $esc($complete)
              . ' où les trois sources concordent à ' . $esc(number_format($tol, 2, ',', ' ')) . ' % près.')
           . '</p>';

        foreach ([['sales-kpis / monthly-sales', $mBA], ['pnl/monthly / monthly-sales', $mCA]] as [$lib, $m]) {
            if ($m === null) { continue; }
            $txt = '<p class="s"><b>' . $esc($lib) . '</b> : rapport médian '
                 . $esc(number_format($m, 4, ',', ' '));
            // Un rapport constant d'une boutique à l'autre n'est pas du bruit :
            // c'est une définition qui diffère. S'il colle à un taux de TVA,
            // autant le nommer.
            $hit = null;
            foreach ($vat as $f) {
                if (abs($m - $f) <= $tol / 100) { $hit = $f; break; }
            }
            if (abs($m - 1) <= $tol / 100) {
                $txt .= ' — les deux sources sont d\'accord.';
            } elseif ($hit !== null) {
                $txt .= ' — soit un facteur ' . $esc(number_format($hit, 2, ',', ' '))
                      . ' : <b>une des deux sources est TTC, l\'autre HT</b>.';
            } elseif (abs($m - 1) <= 5 * $tol / 100) {
                // Un écart de quelques dixièmes n'est pas une divergence de
                // définition : l'annoncer comme un problème de périmètre
                // enverrait le backend chercher une cause qui n'existe pas.
                $txt .= ' — écart marginal (' . $esc(number_format(abs($m - 1) * 100, 2, ',', ' '))
                      . ' %) : arrondis ou décalage d\'une journée. Ces deux sources disent la même chose.';
            } else {
                $txt .= ' — ne correspond à aucun facteur de TVA connu : l\'écart vient d\'un périmètre '
                      . '(canal B2B, tickets annulés, jours sans clôture) et non d\'une taxe.';
            }
            echo $txt . '</p>';
        }

        // Un rapport CONSTANT accuse une définition (TVA, unité). Un rapport
        // qui varie d'une boutique à l'autre accuse une FENÊTRE : l'endpoint ne
        // répond pas sur la période demandée. La sonde ci-dessous tranche.
        $spread = ($rBA !== [] && min($rBA) > 0) ? max($rBA) / min($rBA) : 1.0;
        if ($mBA !== null && abs($mBA - 1) > $tol / 100 && $spread > 1 + $tol / 100) {
            $this->windowProbe($ym, $from, $to, $names, $kpis, $tol, $esc, $eur);
        }

        echo '<p class="s" style="color:#7a7168">Mois clos par défaut ; le mois en cours n\'est pas comparable, '
           . 'les trois sources ne s\'arrêtent pas au même instant. Tolérance et facteurs de TVA se règlent dans '
           . 'la configuration (<code>diag_ca_tolerance_pct</code>, <code>diag_ca_vat_factors</code>).</p>';
        echo '</div></body>';
    }

    /**
     * L'écart de sales-kpis vient-il de la FENÊTRE ou du PÉRIMÈTRE ?
     *
     * On redemande le même endpoint sur une seule journée, puis sur le mois
     * précédent. Si les trois réponses sont identiques, l'endpoint ne lit pas
     * ses dates — et tout l'écart s'explique par là. On confronte aussi
     * l'endpoint multi-boutiques à l'endpoint unitaire, qui n'est pas le même
     * chemin côté backend.
     */
    private function windowProbe(
        string $ym, string $from, string $to, array $names, ?array $kpis,
        float $tol, callable $esc, callable $eur
    ): void {
        $day  = (new \DateTimeImmutable($from))->modify('+14 days')->format('Y-m-d');
        $prev = (new \DateTimeImmutable($from))->modify('-1 month');
        $pFrom = $prev->format('Y-m-01');
        $pTo   = $prev->modify('last day of this month')->format('Y-m-d');

        $kDay  = $this->shopService->getSalesKpisAllShops($day, $day);
        $kPrev = $this->shopService->getSalesKpisAllShops($pFrom, $pTo);

        // L'endpoint unitaire sur TOUTES les boutiques, en parallèle : une
        // attente, et la comparaison des deux chemins backend cesse de reposer
        // sur une seule boutique.
        $unitMany = $this->shopService->getSalesKpisManyApiOnly(array_map(
            fn($id) => ['shop' => (int)$id, 'from' => $from, 'to' => $to],
            array_keys($names)
        ));
        $unitOf = fn(int $id) => $unitMany["{$id}|{$from}|{$to}"] ?? null;

        echo '<h3>D\'où vient l\'écart : la fenêtre ou le périmètre ?</h3>';
        echo '<div class="wrap"><table><tr><th>Boutique</th>'
           . '<th>mois ' . $esc($ym) . '</th><th>1 seule journée</th><th>mois précédent</th></tr>';

        $ignored = 0; $compared = 0;
        foreach ($names as $id => $name) {
            $m = isset($kpis[$id]['ca'])  ? (float)$kpis[$id]['ca']  : null;
            $d = isset($kDay[$id]['ca'])  ? (float)$kDay[$id]['ca']  : null;
            $p = isset($kPrev[$id]['ca']) ? (float)$kPrev[$id]['ca'] : null;
            if ($m !== null && $d !== null && $m > 0) {
                $compared++;
                if (abs($d - $m) / $m * 100 <= $tol) { $ignored++; }
            }
            echo '<tr><td class="l" title="' . $esc($name) . '">' . $esc($name) . '</td>'
               . '<td>' . $esc($eur($m)) . '</td><td>' . $esc($eur($d)) . '</td>'
               . '<td>' . $esc($eur($p)) . '</td></tr>';
        }
        echo '</table></div>';

        echo '<div class="note">';
        if ($compared === 0) {
            echo '<p class="s">Sonde non concluante : l\'endpoint n\'a pas répondu sur les fenêtres de contrôle.</p>';
        } elseif ($ignored === $compared) {
            echo '<p class="s ko">Une seule journée renvoie le même montant que le mois entier, sur les '
               . $esc($compared) . ' boutiques testées : <b>l\'endpoint ne lit pas <code>date_from</code> / '
               . '<code>date_to</code></b>. Il répond sur une période à lui. C\'est l\'explication de tout '
               . 'l\'écart — ni TVA, ni périmètre commercial.</p>';
        } else {
            echo '<p class="s">La fenêtre est bien lue (' . $esc($compared - $ignored) . ' boutique(s) sur '
               . $esc($compared) . ' donnent un montant différent sur une journée). L\'écart vient donc de '
               . '<b>ce qui est compté</b>, pas de la période : canal B2B, tickets annulés, jours sans clôture.</p>';
        }

        echo '</div>';
        $this->linesVsTicketsProbe($from, $to, $names, $kpis, $unitOf, $tol, $esc, $eur);
    }

    /**
     * Le multi-boutiques compte-t-il les LIGNES de ticket au lieu des TICKETS ?
     *
     * Un ticket a plusieurs lignes. Une requête qui joint les lignes et somme
     * le montant du ticket à chaque fois multiplie le CA par le nombre
     * d'articles du ticket — un facteur qui varie d'une boutique à l'autre,
     * exactement comme celui observé. On confronte donc, boutique par boutique,
     * le rapport multi/unitaire au nombre d'articles par ticket. S'ils
     * coïncident, la cause est nommée et le correctif tient en une ligne de SQL.
     */
    private function linesVsTicketsProbe(
        string $from, string $to, array $names, ?array $kpis, callable $unitOf,
        float $tol, callable $esc, callable $eur
    ): void {
        echo '<h3>Le multi-boutiques compte-t-il les lignes au lieu des tickets ?</h3>';
        echo '<div class="wrap"><table><tr><th>Boutique</th><th>unitaire</th><th>multi-boutiques</th>'
           . '<th>rapport</th><th>lignes / ticket</th><th>écart</th><th>articles / ticket</th></tr>';

        $match = 0; $tested = 0; $divergent = 0; $pairs = 0;
        // Le rapport et le panier d'articles ne se mesurent pas au centime :
        // une tolérance de comptage, plus large que celle des montants.
        $band = max(5.0, 10 * $tol);

        foreach ($names as $id => $name) {
            $u   = $unitOf((int)$id);
            $uCa = isset($u['ca']) ? (float)$u['ca'] : null;
            $bCa = isset($kpis[$id]['ca']) ? (float)$kpis[$id]['ca'] : null;
            $rat = ($uCa !== null && $uCa > 0 && $bCa !== null) ? $bCa / $uCa : null;

            // LIGNES, pas articles : une ligne « 3 croissants » vaut une ligne
            // et trois articles. Un endpoint qui somme le ticket une fois par
            // ligne se trahit par les lignes — les comparer aux articles ferait
            // rejeter la bonne hypothèse.
            $st  = $this->shopSales->getTicketLineStats((int)$id, $from, $to);
            $lpt = ($st !== null && $st['tickets'] > 0) ? $st['lines'] / $st['tickets'] : null;
            $apt = ($st !== null && $st['tickets'] > 0 && $st['articles'] > 0)
                 ? $st['articles'] / $st['tickets'] : null;
            if ($apt === null && isset($u['products_per_ticket']) && is_numeric($u['products_per_ticket'])) {
                $apt = (float)$u['products_per_ticket'];
            }
            $rat !== null && $pairs++;
            if ($rat !== null && abs($rat - 1) * 100 > $tol) { $divergent++; }

            $ecart = ($rat !== null && $lpt !== null && $lpt > 0) ? abs($rat - $lpt) / $lpt * 100 : null;
            if ($ecart !== null) {
                $tested++;
                if ($ecart <= $band) { $match++; }
            }

            echo '<tr><td class="l" title="' . $esc($name) . '">' . $esc($name) . '</td>'
               . '<td>' . $esc($eur($uCa)) . '</td><td>' . $esc($eur($bCa)) . '</td>'
               . '<td>' . $esc($rat === null ? '—' : number_format($rat, 4, ',', ' ')) . '</td>'
               . '<td>' . $esc($lpt === null ? '—' : number_format($lpt, 4, ',', ' ')) . '</td>'
               . '<td class="' . ($ecart === null ? '' : ($ecart <= $band ? 'oky' : 'ko')) . '">'
               . $esc($ecart === null ? '—' : number_format($ecart, 1, ',', ' ') . ' %') . '</td>'
               . '<td style="color:#a2988c">' . $esc($apt === null ? '—' : number_format($apt, 4, ',', ' '))
               . '</td></tr>';
        }
        echo '</table></div><div class="note">';

        if ($pairs === 0) {
            echo '<p class="s">Sonde non concluante : l\'endpoint unitaire n\'a rien renvoyé.</p>';
        } elseif ($divergent === 0) {
            echo '<p class="s oky">Les deux chemins backend donnent le même montant : le défaut n\'est pas '
               . 'dans l\'endpoint multi-boutiques.</p>';
        } else {
            echo '<p class="s ko">Les deux chemins backend divergent sur ' . $esc($divergent) . ' boutique(s) sur '
               . $esc($pairs) . ' : <b>le défaut est dans <code>/consultant/shops/sales-kpis</code></b>, pas dans '
               . 'la donnée — <code>/shops/{id}/statistics/sales/kpis</code> lit la même base et tombe juste.</p>';
            if ($tested > 0 && $match === $tested) {
                echo '<p class="s ko">Et le rapport est, boutique par boutique, <b>le nombre de lignes par '
                   . 'ticket</b> (à ' . $esc(number_format($band, 0, ',', ' ')) . ' % près sur les '
                   . $esc($tested) . ' boutiques testées). L\'endpoint multi-boutiques joint les lignes de '
                   . 'ticket et additionne le montant du ticket <b>une fois par ligne</b>. Correctif : sommer '
                   . 'sur les tickets — <code>GROUP BY</code> ticket, ou somme des lignes plutôt que du total '
                   . 'répété.</p>';
            } elseif ($tested > 0) {
                echo '<p class="s">Le rapport ne suit pas le nombre de lignes par ticket ('
                   . $esc($match) . ' boutique(s) sur ' . $esc($tested) . ') : la duplication ne vient pas des '
                   . 'lignes de ticket. Piste suivante : une jointure sur les affectations de consultants, ou '
                   . 'un périmètre commercial différent.</p>';
            }
            // La colonne « articles » est là pour situer : le rapport doit
            // rester SOUS elle. Au-dessus, ce n'est plus une duplication de
            // lignes, c'est autre chose.
            echo '<p class="s" style="color:#7a7168">La colonne « articles / ticket » ne sert qu\'à situer : '
               . 'une ligne « 3 croissants » vaut une ligne et trois articles, donc un facteur de duplication '
               . 'par ligne reste toujours en dessous.</p>';
        }
        echo '</div>';
    }

    #[Route('GET', '/pnl-debug')]
    public function pnl(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $flag = $_SERVER['PNL_DIAG'] ?? $_ENV['PNL_DIAG'] ?? getenv('PNL_DIAG') ?: '0';
        if ($flag !== '1') {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $shop   = (int)($_GET['shop'] ?? 0);
        $period = $_GET['period'] ?? 'month';
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $period = 'month';
        }

        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<body style="font-family:ui-monospace,Menlo,monospace;max-width:820px;margin:24px auto;padding:0 16px;color:#241f1c;background:#F4EFE8;line-height:1.5">';
        echo '<h2 style="font-family:sans-serif">Diagnostic P&L</h2>';

        // Session / token
        $access     = $this->cookieManager->getAccessToken();
        $accExp     = $this->cookieManager->getAccessTokenExpiryTime();
        $refExp     = $this->cookieManager->getRefreshTokenExpiryTime();
        $accExpired = $accExp ? (strtotime($accExp) < time()) : null;
        echo '<h3 style="font-family:sans-serif">Session</h3><ul>';
        echo '<li>Access token présent : ' . ($access ? '✅ (' . strlen($access) . ' car.)' : '❌') . '</li>';
        echo '<li>Access expiry : ' . $esc($accExp ?: '—') . ($accExpired === true ? ' ⚠️ EXPIRÉ' : ($accExpired === false ? ' ✅ valide' : '')) . '</li>';
        echo '<li>Refresh expiry : ' . $esc($refExp ?: '—') . '</li>';
        echo '</ul>';

        // ── Vue « tous les magasins » : /pnl-debug?all=1 ──
        if (($_GET['all'] ?? '') === '1') {
            $shopsResp = $this->apiClient->get('/consultant/shops');
            $shops = ($shopsResp['success'] && isset($shopsResp['data'])) ? $shopsResp['data'] : [];
            echo '<h3 style="font-family:sans-serif">Tous les magasins — indicateurs Boutiques</h3>';
            echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
            echo '<tr style="background:#8D1D2C;color:#fff;text-align:left">'
               . '<th style="padding:6px">id</th><th>magasin</th><th>fenêtre</th><th>j</th>'
               . '<th>CA API</th><th>CA DB</th><th>ratio</th><th>tickets DB</th><th>produits DB</th>'
               . '<th>tickets redressés</th><th>t/j</th><th>panier</th><th>p/client</th></tr>';
            foreach ($shops as $s) {
                $sid  = (int)($s['id'] ?? 0);
                $name = $s['representative_name'] ?? $s['name'] ?? ('#' . $sid);
                $p    = $this->apiClient->get('/consultant/shops/' . $sid . '/pnl?period=month');
                $pd   = $p['data'] ?? [];
                $T    = isset($pd['turnover']['value']) ? (float)$pd['turnover']['value'] : 0.0;
                $fd   = isset($pd['date_from']) ? substr((string)$pd['date_from'], 0, 10) : '';
                $td   = isset($pd['date_to'])   ? substr((string)$pd['date_to'], 0, 10)   : '';
                $ticketsDb = 0; $productsDb = 0; $caDb = 0.0; $days = 1;
                if (\DateTimeImmutable::createFromFormat('!Y-m-d', $fd) && \DateTimeImmutable::createFromFormat('!Y-m-d', $td)) {
                    $sum = $this->shopSales->getSalesKpis($sid, $fd, $td);
                    $ticketsDb = $sum['tickets']; $productsDb = $sum['products']; $caDb = (float)$sum['ca'];
                    $toExcl = (new \DateTimeImmutable($td))->modify('+1 day');
                    $days = max(1, (int)(new \DateTimeImmutable($fd))->diff($toExcl)->days);
                }
                // Redressement au prorata du CA (même logique que ShopController).
                $scale   = ($caDb > 0 && $T > 0) ? $T / $caDb : 1.0;
                $tickets = (int)round($ticketsDb * $scale);
                $tj      = $tickets > 0 ? $tickets / $days : 0;
                $basket  = $tickets > 0 ? $T / $tickets : 0;
                $ppc     = $ticketsDb > 0 ? $productsDb / $ticketsDb : 0; // ratio insensible au redressement
                echo '<tr style="border-top:1px solid #eee">'
                   . '<td style="padding:6px">' . $esc($sid) . '</td>'
                   . '<td>' . $esc($name) . '</td>'
                   . '<td>' . $esc($fd) . '→' . $esc($td) . '</td>'
                   . '<td>' . $esc($days) . '</td>'
                   . '<td>' . $esc(number_format($T, 0, ',', ' ')) . '</td>'
                   . '<td>' . $esc(number_format($caDb, 0, ',', ' ')) . '</td>'
                   . '<td>' . $esc(number_format($scale, 3, ',', ' ')) . '</td>'
                   . '<td>' . $esc($ticketsDb) . '</td>'
                   . '<td>' . $esc($productsDb) . '</td>'
                   . '<td><b>' . $esc($tickets) . '</b></td>'
                   . '<td><b>' . $esc(number_format($tj, 1, ',', ' ')) . '</b></td>'
                   . '<td><b>' . $esc(number_format($basket, 2, ',', ' ')) . '</b></td>'
                   . '<td><b>' . $esc(number_format($ppc, 2, ',', ' ')) . '</b></td></tr>';
            }
            echo '</table></div>';

            // Tables de la base — pour repérer une table de lignes de ticket
            // (détail produits) permettant un vrai « produits / client ».
            $tables = $this->shopSales->listTables();
            echo '<h3 style="font-family:sans-serif">Tables de la base (' . count($tables) . ')</h3>';
            if ($tables === []) {
                echo '<p>⚠️ Base injoignable ou aucune table.</p>';
            } else {
                echo '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
                echo '<tr style="background:#241f1c;color:#fff;text-align:left"><th style="padding:4px 10px">table</th><th style="padding:4px 10px">lignes (≈)</th></tr>';
                foreach ($tables as $t => $rows) {
                    $hot = (bool)preg_match('/transaction|ticket|order|sale|line|item|product/i', $t);
                    echo '<tr style="border-top:1px solid #eee' . ($hot ? ';background:#fff7ec' : '') . '">'
                       . '<td style="padding:3px 10px">' . ($hot ? '<b>' : '') . $esc($t) . ($hot ? '</b>' : '') . '</td>'
                       . '<td style="padding:3px 10px">' . $esc(number_format($rows, 0, ',', ' ')) . '</td></tr>';
                }
                echo '</table></div>';
            }

            echo '<p style="font-family:sans-serif;color:#8D1D2C">⚠️ Diagnostic temporaire — retire <code>SetEnv PNL_DIAG 1</code> après usage.</p>';
            echo '</body>';
            return;
        }

        if ($shop === 0) {
            echo '<p>Ajoute <code>?shop=ID&period=month</code> (ou week / day) à l\'URL.</p>';
            echo '<p>Ex : <code>/pwa_consultant/pnl-debug?shop=1&period=month</code> — ou <code>?all=1</code> pour tous les magasins.</p>';
            echo '</body>';
            return;
        }

        $endpoint = '/consultant/shops/' . $shop . '/pnl?period=' . urlencode($period);
        $resp     = $this->apiClient->get($endpoint);
        $data     = $resp['data'] ?? [];

        echo '<p><b>Endpoint</b> : ' . $esc(API_BASE_URL . $endpoint) . '</p>';
        echo '<p><b>success</b> : ' . ($resp['success'] ? '✅' : '❌ (HTTP ' . $esc($resp['error'] ?? '?') . ')') . '</p>';

        // Clés de premier niveau (pour repérer un éventuel champ food cost).
        if (is_array($data)) {
            echo '<p><b>Clés racine</b> : ' . $esc(implode(', ', array_keys($data))) . '</p>';
        }

        // Dérivation actuelle du Food Cost.
        $num = function ($v) { return is_array($v) ? (float)($v['value'] ?? 0) : (float)$v; };
        $T = $num($data['turnover'] ?? 0);
        $L = $num($data['labour'] ?? 0);
        $OC = $num($data['overhead'] ?? 0);
        $R = $num($data['result'] ?? 0);
        echo '<h3 style="font-family:sans-serif">Valeurs lues</h3><ul>';
        echo '<li>TurnOver = ' . $esc($T) . '</li>';
        echo '<li>Labour = ' . $esc($L) . '</li>';
        echo '<li>Overhead = ' . $esc($OC) . '</li>';
        echo '<li>Result = ' . $esc($R) . '</li>';
        echo '<li><b>Food (dérivé = T−L−OC−R)</b> = ' . $esc($T - $L - $OC - $R) . '</li>';
        echo '</ul>';

        // ── Comparaison API (P&L) vs base (transaction) : origine de l'écart
        //    entre le split TurnOver et la ligne « CA du mois » des Boutiques. ──
        $fromDate = isset($data['date_from']) ? substr((string)$data['date_from'], 0, 10) : '';
        $toDate   = isset($data['date_to'])   ? substr((string)$data['date_to'], 0, 10)   : '';
        echo '<h3 style="font-family:sans-serif">API vs Base (transaction)</h3><ul>';
        echo '<li>Fenêtre P&L : <code>' . $esc($fromDate ?: '?') . '</code> → <code>' . $esc($toDate ?: '?') . '</code></li>';
        echo '<li>TurnOver (API) = <b>' . $esc(number_format($T, 2, ',', ' ')) . '</b></li>';

        $valid = fn($v) => (bool)\DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($valid($fromDate) && $valid($toDate)) {
            $win = $this->shopSales->getSalesKpis($shop, $fromDate, $toDate);
            echo '<li>DB sur la fenêtre P&L [' . $esc($fromDate) . ' → ' . $esc($toDate) . '] : '
               . '<b>CA=' . $esc(number_format($win['ca'], 2, ',', ' ')) . '</b>, tickets=' . $esc($win['tickets'])
               . ', produits=' . $esc($win['products']) . '</li>';
            $diff = $T - $win['ca'];
            echo '<li>Écart API − DB (même fenêtre) = <b>' . $esc(number_format($diff, 2, ',', ' ')) . '</b>'
               . ($win['ca'] > 0 ? ' (ratio ' . $esc(number_format($T / $win['ca'], 3, ',', ' ')) . ')' : '') . '</li>';
        } else {
            echo '<li>⚠️ date_from / date_to absents ou invalides dans la réponse P&L.</li>';
        }

        // Mois calendaire courant (repli).
        $cm = $this->shopSales->getSalesKpis($shop, date('Y-m-01'), date('Y-m-t'));
        echo '<li>DB mois calendaire courant [' . $esc(date('Y-m-01')) . ' → ' . $esc(date('Y-m-t')) . '] : '
           . '<b>CA=' . $esc(number_format($cm['ca'], 2, ',', ' ')) . '</b>, tickets=' . $esc($cm['tickets'])
           . ', produits=' . $esc($cm['products']) . '</li>';
        echo '</ul>';

        // ── Indicateurs Boutiques recalculés (1 ligne transaction = 1 produit) ──
        if ($valid($fromDate) && $valid($toDate)) {
            $s = $this->shopSales->getSalesKpis($shop, $fromDate, $toDate);

            // Nombre de jours de la fenêtre (date_from → date_to inclus).
            $toExObj = (new \DateTimeImmutable($toDate))->modify('+1 day');
            $days    = max(1, (int)(new \DateTimeImmutable($fromDate))->diff($toExObj)->days);

            $perDay = $s['tickets'] > 0 ? $s['tickets'] / $days : 0;
            $basket = $s['tickets'] > 0 ? $T / $s['tickets'] : 0;
            $ppc    = $s['tickets'] > 0 ? $s['products'] / $s['tickets'] : 0;

            echo '<h3 style="font-family:sans-serif">Indicateurs Boutiques</h3><ul>';
            echo '<li>Fenêtre : <code>' . $esc($fromDate) . '</code> → <code>' . $esc($toDate) . '</code>, jours = <b>' . $esc($days) . '</b></li>';
            echo '<li>Tickets (DISTINCT id_device, ticket_key) = <b>' . $esc($s['tickets']) . '</b></li>';
            echo '<li>Produits (COUNT lignes) = <b>' . $esc($s['products']) . '</b></li>';
            echo '<li>Tickets / jour = <b>' . $esc(number_format($perDay, 1, ',', ' ')) . '</b></li>';
            echo '<li>Panier moyen (TurnOver / tickets) = <b>' . $esc(number_format($basket, 2, ',', ' ')) . ' €</b></li>';
            echo '<li>Produits / client = <b>' . $esc(number_format($ppc, 1, ',', ' ')) . '</b></li>';

            // Repère sur la qualité des insert_timestamp.
            $dbg = $this->shopSales->getWindowDebug($shop, $fromDate, $toDate);
            echo '<li style="color:#7a7168">min / max insert_timestamp : <code>' . $esc($dbg['min_ts'] ?? '—') . '</code> / <code>' . $esc($dbg['max_ts'] ?? '—') . '</code> — lignes filtrées par ticket_key.</li>';
            echo '</ul>';
        }

        echo '<h3 style="font-family:sans-serif">Réponse brute</h3>';
        echo '<pre style="white-space:pre-wrap;background:#fff;padding:12px;border-radius:8px;font-size:12px">'
           . $esc(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</pre>';

        echo '<p style="font-family:sans-serif;color:#8D1D2C">⚠️ Diagnostic temporaire — retire <code>SetEnv PNL_DIAG 1</code> après usage.</p>';
        echo '</body>';
    }
}
