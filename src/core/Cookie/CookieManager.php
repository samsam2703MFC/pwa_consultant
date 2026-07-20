<?php
namespace App\Consultant\core\Cookie;

use App\Consultant\app\Models\Auth\JWTModel;

class CookieManager {

    public function setAuthCookie(JWTModel $jwtObj, string $expiry_token_date): bool
    {
        $res_access = setcookie('consultant_access_token', $jwtObj->getToken(), [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => true,
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
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_expiry) return false;

        return true;
    }

    public function setRefreshCookie(JWTModel $jwtObj, string $expiry_token_date): bool
    {
        $res_access = setcookie('consultant_refresh_token', $jwtObj->getRefreshToken(), [
            'expires'  => strtotime($expiry_token_date),
            'path'     => '/',
            'secure'   => true,
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
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$res_expiry) return false;

        return true;
    }

    public function unsetCookies(): void
    {
        $expired = ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];

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

