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

        if ($shop === 0) {
            echo '<p>Ajoute <code>?shop=ID&period=month</code> (ou week / day) à l\'URL.</p>';
            echo '<p>Ex : <code>/pwa_consultant/pnl-debug?shop=1&period=month</code></p>';
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
            $fromDt = $fromDate . ' 00:00:00';
            $toExcl = (new \DateTimeImmutable($toDate))->modify('+1 day')->format('Y-m-d 00:00:00');
            $win    = $this->shopSales->getShopSummary($shop, $fromDt, $toExcl);
            echo '<li>DB sur la fenêtre P&L [' . $esc($fromDate) . ' → ' . $esc($toDate) . '] : '
               . '<b>CA=' . $esc(number_format($win['ca'], 2, ',', ' ')) . '</b>, tickets=' . $esc($win['tickets']) . '</li>';
            $diff = $T - $win['ca'];
            echo '<li>Écart API − DB (même fenêtre) = <b>' . $esc(number_format($diff, 2, ',', ' ')) . '</b>'
               . ($win['ca'] > 0 ? ' (ratio ' . $esc(number_format($T / $win['ca'], 3, ',', ' ')) . ')' : '') . '</li>';
        } else {
            echo '<li>⚠️ date_from / date_to absents ou invalides dans la réponse P&L.</li>';
        }

        // Mois calendaire courant (ancienne source de la ligne « CA du mois »).
        $cmFrom = date('Y-m-01 00:00:00');
        $cmTo   = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $cm     = $this->shopSales->getShopSummary($shop, $cmFrom, $cmTo);
        echo '<li>DB mois calendaire courant [' . $esc(date('Y-m-01')) . ' → ' . $esc(date('Y-m-01', strtotime('first day of next month'))) . '] : '
           . '<b>CA=' . $esc(number_format($cm['ca'], 2, ',', ' ')) . '</b>, tickets=' . $esc($cm['tickets']) . '</li>';
        echo '</ul>';

        // ── Diagnostic « tickets/jour » : comptage insert_timestamp vs ticket_key ──
        if ($valid($fromDate) && $valid($toDate)) {
            $dbg = $this->shopSales->getWindowDebug($shop, $fromDate, $toDate);

            // Nombre de jours de la fenêtre P&L (date_from → date_to inclus),
            // même logique que ShopController.
            $toExObj = (new \DateTimeImmutable($toDate))->modify('+1 day');
            $days    = max(1, (int)(new \DateTimeImmutable($fromDate))->diff($toExObj)->days);

            $perTs  = $dbg['tickets_ts']  > 0 ? $dbg['tickets_ts']  / $days : 0;
            $perKey = $dbg['tickets_key'] > 0 ? $dbg['tickets_key'] / $days : 0;

            echo '<h3 style="font-family:sans-serif">Tickets / jour</h3><ul>';
            echo '<li>Fenêtre : <code>' . $esc($fromDate) . '</code> → <code>' . $esc($toDate) . '</code>, jours de la fenêtre = <b>' . $esc($days) . '</b></li>';
            echo '<li>Tickets (insert_timestamp) = <b>' . $esc($dbg['tickets_ts']) . '</b> → ' . $esc(number_format($perTs, 1, ',', ' ')) . ' / jour</li>';
            echo '<li>Tickets (ticket_key = date métier) = <b>' . $esc($dbg['tickets_key']) . '</b> → ' . $esc(number_format($perKey, 1, ',', ' ')) . ' / jour</li>';
            echo '<li>min / max insert_timestamp (magasin) : <code>' . $esc($dbg['min_ts'] ?? '—') . '</code> / <code>' . $esc($dbg['max_ts'] ?? '—') . '</code></li>';
            echo '<li>lignes totales pour ce magasin : ' . $esc($dbg['total_rows']) . '</li>';
            if ($dbg['tickets_ts'] !== $dbg['tickets_key']) {
                echo '<li style="color:#8D1D2C"><b>⚠️ Écart insert_timestamp vs ticket_key</b> = '
                   . $esc($dbg['tickets_key'] - $dbg['tickets_ts'])
                   . ' → insert_timestamp est bruité, préférer ticket_key.</li>';
            }
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
