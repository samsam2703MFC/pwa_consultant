<?php
namespace App\Consultant\app\Http\Controllers\Auth;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Http\Requests\LoginRequest;
use App\Consultant\app\Services\Auth\AuthService;
use App\Consultant\core\Http\ApiClient;
use App\Consultant\core\Support\Route;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private ApiClient $apiClient,
    ) {}

    #[Route('GET', '/auth')]
    public function index(): void
    {
        if (isset($_GET['diag']) && $this->diagEnabled()) {
            $this->diagnostics();
            return;
        }

        if ($this->authService->isAuthenticated()) {
            redirect('/dashboard');
            return;
        }

        $this->view('auth/login');
    }

    #[Route('POST', '/auth')]
    public function login(): void
    {
        if (isset($_GET['diag']) && $this->diagEnabled()) {
            $this->diagnostics();
            return;
        }

        $this->errors = LoginRequest::validateLogin($_POST);

        if (!empty($this->errors)) {
            $this->view('auth/login');
            return;
        }

        $result = $this->authService->login($_POST);

        if ($result['success']) {
            redirect('/dashboard');
            return;
        }

        $errorCode = $result['error_code'] ?? null;

        $this->errors['login_error'] = $errorCode;
        $this->view('auth/login');
    }

    #[Route('GET', '/logout')]
    public function logout(): void
    {
        $this->authService->logout();
        redirect('/auth');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Diagnostic du login — désactivé par défaut.
    //  Activer temporairement avec `SetEnv AUTH_DIAG 1` dans public/.htaccess,
    //  puis ouvrir /pwa_consultant/auth?diag=1. À RETIRER après diagnostic.
    // ─────────────────────────────────────────────────────────────────────
    private function diagEnabled(): bool
    {
        $flag = $_SERVER['AUTH_DIAG'] ?? $_ENV['AUTH_DIAG'] ?? getenv('AUTH_DIAG') ?: '0';
        return $flag === '1';
    }

    private function diagnostics(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $https  = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '')
               || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $esc    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $redact = function ($data) {
            if (!is_array($data)) return $data;
            foreach (['access_token', 'refresh_token', 'token'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                    $data[$k] = substr($data[$k], 0, 6) . '…[' . strlen($data[$k]) . ' chars]';
                }
            }
            return $data;
        };

        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<body style="font-family:ui-monospace,Menlo,monospace;max-width:760px;margin:24px auto;padding:0 16px;color:#241f1c;background:#F4EFE8;line-height:1.5">';
        echo '<h2 style="font-family:sans-serif">Diagnostic login</h2>';

        echo '<h3 style="font-family:sans-serif;margin-top:20px">Configuration</h3><ul>';
        echo '<li><b>API base</b>: ' . $esc(API_BASE_URL) . '</li>';
        echo '<li><b>Endpoint login</b>: ' . $esc(API_BASE_URL . '/consultant/auth/login') . '</li>';
        echo '<li><b>Connexion HTTPS ?</b> ' . ($https ? '✅ oui' : '❌ non (HTTP)') . '</li>';
        echo '<li><b>Cookies Secure poseront ?</b> ' . ($https ? '✅ oui' : '⚠️ non — sur HTTP les cookies Secure sont acceptés uniquement si l\'app les pose sans le flag Secure (corrigé).') . '</li>';
        echo '</ul>';

        // Sonde sans identifiants — statut de l'endpoint.
        $probe = $this->apiClient->loginDebug('/consultant/auth/login', ['phone' => '', 'password' => '']);
        echo '<h3 style="font-family:sans-serif;margin-top:20px">Sonde de l\'endpoint (sans identifiants)</h3><ul>';
        echo '<li><b>HTTP status</b>: ' . $esc($probe['status']) . '</li>';
        if ($probe['curl_error'] !== '') {
            echo '<li><b style="color:#8D1D2C">Erreur connexion</b>: ' . $esc($probe['curl_error']) . ' → backend injoignable / mauvais host.</li>';
        } else {
            echo '<li>' . ($probe['status'] == 404 ? '❌ 404 → l\'endpoint n\'existe pas à cette URL (routage/base API).' :
                 (in_array($probe['status'], [400,401,422]) ? '✅ endpoint atteint (rejette le corps vide, normal).' :
                 'ℹ️ statut ' . $esc($probe['status']))) . '</li>';
        }
        echo '</ul>';

        // Test réel si identifiants fournis (POST).
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['phone'])) {
            $res = $this->apiClient->loginDebug('/consultant/auth/login', [
                'phone'    => (string)($_POST['phone'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
            ]);
            $json    = $res['json'];
            $payload = is_array($json) ? ($json['data'] ?? $json['tokens'] ?? $json) : null;
            $hasTok  = is_array($payload) && !empty($payload['access_token']);
            echo '<h3 style="font-family:sans-serif;margin-top:20px">Test des identifiants</h3><ul>';
            echo '<li><b>HTTP status</b>: ' . $esc($res['status']) . '</li>';
            echo '<li><b>access_token trouvé ?</b> ' . ($hasTok ? '✅ OUI — identifiants OK' : '❌ non') . '</li>';
            echo '<li><b>Réponse (token masqué)</b>: <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:8px">'
               . $esc(json_encode(is_array($json) ? $redact($json) : $res['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></li>';
            echo '</ul>';
        } else {
            echo '<h3 style="font-family:sans-serif;margin-top:20px">Tester des identifiants</h3>';
            echo '<form method="POST" style="display:flex;flex-direction:column;gap:8px;max-width:320px">';
            echo '<input name="phone" placeholder="Téléphone" style="padding:10px;border:1px solid #ccc;border-radius:8px">';
            echo '<input name="password" type="password" placeholder="Mot de passe" style="padding:10px;border:1px solid #ccc;border-radius:8px">';
            echo '<button style="padding:10px;border:none;background:#8D1D2C;color:#fff;border-radius:8px;cursor:pointer">Tester</button>';
            echo '<small>Le test se fait en POST — les identifiants ne passent pas dans l\'URL.</small>';
            echo '</form>';
        }

        echo '<p style="font-family:sans-serif;color:#8D1D2C;margin-top:24px">⚠️ Diagnostic temporaire. Retire <code>SetEnv AUTH_DIAG 1</code> du .htaccess après usage.</p>';
        echo '</body>';
    }
}

