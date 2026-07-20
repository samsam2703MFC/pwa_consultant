<?php
namespace App\Consultant\app\Services\Auth;

use App\Consultant\app\Models\Auth\JWTModel;
use App\Consultant\app\Repositories\Auth\LoginRepository;
use App\Consultant\core\Cookie\CookieManager;
use DateTime;

class AuthService {

    public function __construct(
        private LoginRepository $loginRepository,
        private CookieManager   $cookieManager,
        private JwtService      $jwtService
    ) {}

    /**
     * @return array{success: bool, error_code: string|null}
     */
    public function login(array $data): array
    {
        $result = $this->loginRepository->login($data);

        if (!$result['success'] || $result['jwt'] === null) {
            return ['success' => false, 'error_code' => $result['error_code']];
        }

        $ok = $this->setCookiesProcedure($result['jwt']);
        return ['success' => $ok, 'error_code' => $ok ? null : 'COOKIE_ERROR'];
    }

    private function setCookiesProcedure(JWTModel $jwtObj): bool
    {
        $expiryTokenDate        = $this->jwtService->getExpiryTokenDate($jwtObj->getToken());
        $expiryRefreshTokenDate = $this->jwtService->getExpiryRefreshTokenDate($jwtObj->getRefreshToken() ?? '');

        if (!$this->cookieManager->setAuthCookie($jwtObj, $expiryTokenDate)) return false;
        if (!$this->cookieManager->setRefreshCookie($jwtObj, $expiryRefreshTokenDate)) return false;

        return true;
    }

    public function logout(): void
    {
        $refreshToken = $this->cookieManager->getRefreshToken();
        if ($refreshToken) {
            $this->loginRepository->logout($refreshToken);
        }
        $this->cookieManager->unsetCookies();
    }

    public function refreshTokens(string $refreshToken): bool
    {
        $jwtObj = $this->loginRepository->refresh($refreshToken);
        if ($jwtObj === null) return false;

        return $this->setCookiesProcedure($jwtObj);
    }

    public function isAuthenticated(): bool
    {
        $accessExpiry  = $this->cookieManager->getAccessTokenExpiryTime();
        $refreshExpiry = $this->cookieManager->getRefreshTokenExpiryTime();

        if (!$accessExpiry || !$refreshExpiry) {
            return false;
        }

        $now = new DateTime();

        if (new DateTime($accessExpiry) > $now) {
            return true;
        }

        if (new DateTime($refreshExpiry) > $now) {
            return $this->refreshTokens($this->cookieManager->getRefreshToken() ?? '');
        }

        return false;
    }

    private function hasValidAccessToken(): bool
    {
        $expiry = $this->cookieManager->getAccessTokenExpiryTime();
        return $expiry && new DateTime($expiry) > new DateTime();
    }

    private function canRefreshToken(): bool
    {
        $expiry = $this->cookieManager->getRefreshTokenExpiryTime();
        return $expiry && new DateTime($expiry) > new DateTime();
    }

    public function ensureValidSession(): bool
    {
        if ($this->hasValidAccessToken()) {
            return true;
        }

        if ($this->canRefreshToken()) {
            return $this->refreshTokens($this->cookieManager->getRefreshToken() ?? '');
        }

        return false;
    }
}

