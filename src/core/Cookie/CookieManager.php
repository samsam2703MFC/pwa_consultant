<?php
namespace App\Consultant\core\Cookie;

use App\Consultant\app\Models\Auth\JWTModel;

class CookieManager {

    /**
     * Czy bieżące połączenie jest HTTPS. Cookie z flagą Secure NIE są
     * zapisywane przez przeglądarkę na zwykłym HTTP — na serwerze HTTP
     * (np. http://185.180.206.46) powodowałoby to pętlę logowania.
     */
    private function isSecure(): bool
    {
        if (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
    }

    public function setAuthCookie(JWTModel $jwtObj, string $expiry_token_date): bool
    {
        $secure = $this->isSecure();

        $res_access = setcookie('consultant_access_token', $jwtObj->getToken(), [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_access) return false;

        // Aktualizuj $_COOKIE natychmiast — setcookie() tylko kolejkuje nagłówek,
        // nie aktualizuje superglobala w bieżącym request.
        $_COOKIE['consultant_access_token']        = $jwtObj->getToken();
        $_COOKIE['consultant_access_token_expiry'] = $expiry_token_date;

        $res_expiry = setcookie('consultant_access_token_expiry', $expiry_token_date, [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_expiry) return false;

        return true;
    }

    public function setRefreshCookie(JWTModel $jwtObj, string $expiry_token_date): bool
    {
        $secure = $this->isSecure();
        $res_access = setcookie('consultant_refresh_token', $jwtObj->getRefreshToken(), [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_access) return false;

        // Aktualizuj $_COOKIE natychmiast — setcookie() tylko kolejkuje nagłówek,
        // nie aktualizuje superglobala w bieżącym request.
        $_COOKIE['consultant_refresh_token']        = $jwtObj->getRefreshToken();
        $_COOKIE['consultant_refresh_token_expiry'] = $expiry_token_date;

        $res_expiry = setcookie('consultant_refresh_token_expiry', $expiry_token_date, [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_expiry) return false;

        return true;
    }

    public function unsetCookies(): void
    {
        $expired = ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isSecure(), 'httponly' => true, 'samesite' => 'Lax'];

        setcookie('consultant_refresh_token',          '', $expired);
        setcookie('consultant_refresh_token_expiry',   '', $expired);
        setcookie('consultant_access_token',           '', $expired);
        setcookie('consultant_access_token_expiry',    '', $expired);

        unset(
            $_COOKIE['consultant_refresh_token'],
            $_COOKIE['consultant_refresh_token_expiry'],
            $_COOKIE['consultant_access_token'],
            $_COOKIE['consultant_access_token_expiry'],
        );
    }

    public function getAccessToken(): ?string
    {
        return $_COOKIE['consultant_access_token'] ?? null;
    }

    public function getRefreshToken(): ?string
    {
        return $_COOKIE['consultant_refresh_token'] ?? null;
    }

    public function getAccessTokenExpiryTime(): ?string
    {
        return $_COOKIE['consultant_access_token_expiry'] ?? null;
    }

    public function getRefreshTokenExpiryTime(): ?string
    {
        return $_COOKIE['consultant_refresh_token_expiry'] ?? null;
    }
}

