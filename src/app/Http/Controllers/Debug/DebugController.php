<?php
namespace App\Consultant\app\Http\Controllers\Debug;

use App\Consultant\app\Http\Controllers\Controller;
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
    ) {}

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

        // Session / token
        $access   = $this->cookieManager->getAccessToken();
        $accExp   = $this->cookieManager->getAccessTokenExpiryTime();
        $refExp   = $this->cookieManager->getRefreshTokenExpiryTime();
        $accExpired = $accExp ? (strtotime($accExp) < time()) : null;
        echo '<h3 style="font-family:sans-serif">Session</h3><ul>';
        echo '<li>Access token présent : ' . ($access ? '✅ (' . strlen($access) . ' car.)' : '❌') . '</li>';
        echo '<li>Access expiry : ' . $esc($accExp ?: '—') . ($accExpired === true ? ' ⚠️ EXPIRÉ' : ($accExpired === false ? ' ✅ valide' : '')) . '</li>';
        echo '<li>Refresh expiry : ' . $esc($refExp ?: '—') . '</li>';
        echo '</ul>';

        $shop   = (int)($_GET['shop'] ?? 0);
        $period = $_GET['period'] ?? 'month';
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $period = 'month';
        }

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<body style="font-family:ui-monospace,Menlo,monospace;max-width:820px;margin:24px auto;padding:0 16px;color:#241f1c;background:#F4EFE8;line-height:1.5">';
        echo '<h2 style="font-family:sans-serif">Diagnostic P&L</h2>';

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

        echo '<h3 style="font-family:sans-serif">Réponse brute</h3>';
        echo '<pre style="white-space:pre-wrap;background:#fff;padding:12px;border-radius:8px;font-size:12px">'
           . $esc(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</pre>';

        echo '<p style="font-family:sans-serif;color:#8D1D2C">⚠️ Diagnostic temporaire — retire <code>SetEnv PNL_DIAG 1</code> après usage.</p>';
        echo '</body>';
    }
}
