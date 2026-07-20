<?php
namespace App\Consultant\app\Http\Middleware;

use App\Consultant\app\Services\Auth\AuthService;
use App\Consultant\app\Services\Auth\JwtService;
use App\Consultant\core\Cookie\CookieManager;
use App\Consultant\core\Support\GlobalRegistry;

class AuthMiddleware {

    public function __construct(
        private AuthService   $authService,
        private JwtService    $jwtService,
        private CookieManager $cookieManager,
    ) {}

    public function handle(?string $requiredPerm = null): void
    {
        if (!$this->authService->ensureValidSession()) {
            redirect("auth");
            exit;
        }

        // --- Buduj kontekst z claims JWT ---
        $access = $this->cookieManager->getAccessToken();

        if ($access) {
            $claims = $this->jwtService->getClaimsRaw($access);

            GlobalRegistry::set('user', [
                'id'           => (int)($claims['usr_id'] ?? 0),
                'display_name' => (string)($claims['usr_dn'] ?? ''),
                'lang_code'    => (string)($claims['usr_ln'] ?? 'pl'),
                'permissions'  => (array)($claims['perms'] ?? []),
                'scope_type'   => (string)($claims['scope_type'] ?? ''),
                'scope_id'     => isset($claims['scope_id']) ? (int)$claims['scope_id'] : null,
                'membership_id'=> isset($claims['mid']) ? (int)$claims['mid'] : null,
            ]);

            GlobalRegistry::set('lang_code', (string)($claims['usr_ln'] ?? getUserLanguage()));
        }

        // --- Sprawdzenie uprawnienia per-trasa (opcjonalne) ---
        if ($requiredPerm !== null) {
            $user  = GlobalRegistry::get('user');
            $perms = (array)($user['permissions'] ?? []);

            if (!in_array($requiredPerm, $perms, true)) {
                http_response_code(403);
                echo 'Brak uprawnień';
                exit;
            }
        }
    }
}
