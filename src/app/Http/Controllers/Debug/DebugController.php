<?php
namespace App\Consultant\app\Http\Controllers\Debug;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopSalesRepository;
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
    ) {}

    /**
     * Sonde générique : /api-debug?endpoint=/consultant/shops/summary
     * Affiche la réponse brute de n'importe quel endpoint /consultant/*.
     */
    #[Route('GET', '/api-debug')]
    public function probe(): void
    {
        header('Content-Type: text/html; charset=utf-8');
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
            echo '</body>';
            return;
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
               . '<th>CA API</th><th>CA DB</th><th>ratio</th><th>tickets DB</th><th>tickets redressés</th>'
               . '<th>t/j</th><th>panier</th></tr>';
            foreach ($shops as $s) {
                $sid  = (int)($s['id'] ?? 0);
                $name = $s['representative_name'] ?? $s['name'] ?? ('#' . $sid);
                $p    = $this->apiClient->get('/consultant/shops/' . $sid . '/pnl?period=month');
                $pd   = $p['data'] ?? [];
                $T    = isset($pd['turnover']['value']) ? (float)$pd['turnover']['value'] : 0.0;
                $fd   = isset($pd['date_from']) ? substr((string)$pd['date_from'], 0, 10) : '';
                $td   = isset($pd['date_to'])   ? substr((string)$pd['date_to'], 0, 10)   : '';
                $ticketsDb = 0; $caDb = 0.0; $days = 1;
                if (\DateTimeImmutable::createFromFormat('!Y-m-d', $fd) && \DateTimeImmutable::createFromFormat('!Y-m-d', $td)) {
                    $sum = $this->shopSales->getShopSummary($sid, $fd, $td);
                    $ticketsDb = $sum['tickets']; $caDb = (float)$sum['ca'];
                    $toExcl = (new \DateTimeImmutable($td))->modify('+1 day');
                    $days = max(1, (int)(new \DateTimeImmutable($fd))->diff($toExcl)->days);
                }
                // Redressement au prorata du CA (même logique que ShopController).
                $scale   = ($caDb > 0 && $T > 0) ? $T / $caDb : 1.0;
                $tickets = (int)round($ticketsDb * $scale);
                $tj      = $tickets > 0 ? $tickets / $days : 0;
                $basket  = $tickets > 0 ? $T / $tickets : 0;
                echo '<tr style="border-top:1px solid #eee">'
                   . '<td style="padding:6px">' . $esc($sid) . '</td>'
                   . '<td>' . $esc($name) . '</td>'
                   . '<td>' . $esc($fd) . '→' . $esc($td) . '</td>'
                   . '<td>' . $esc($days) . '</td>'
                   . '<td>' . $esc(number_format($T, 0, ',', ' ')) . '</td>'
                   . '<td>' . $esc(number_format($caDb, 0, ',', ' ')) . '</td>'
                   . '<td>' . $esc(number_format($scale, 3, ',', ' ')) . '</td>'
                   . '<td>' . $esc($ticketsDb) . '</td>'
                   . '<td><b>' . $esc($tickets) . '</b></td>'
                   . '<td><b>' . $esc(number_format($tj, 1, ',', ' ')) . '</b></td>'
                   . '<td><b>' . $esc(number_format($basket, 2, ',', ' ')) . '</b></td></tr>';
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
            $win = $this->shopSales->getShopSummary($shop, $fromDate, $toDate);
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
        $cm = $this->shopSales->getShopSummary($shop, date('Y-m-01'), date('Y-m-t'));
        echo '<li>DB mois calendaire courant [' . $esc(date('Y-m-01')) . ' → ' . $esc(date('Y-m-t')) . '] : '
           . '<b>CA=' . $esc(number_format($cm['ca'], 2, ',', ' ')) . '</b>, tickets=' . $esc($cm['tickets'])
           . ', produits=' . $esc($cm['products']) . '</li>';
        echo '</ul>';

        // ── Indicateurs Boutiques recalculés (1 ligne transaction = 1 produit) ──
        if ($valid($fromDate) && $valid($toDate)) {
            $s = $this->shopSales->getShopSummary($shop, $fromDate, $toDate);

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
