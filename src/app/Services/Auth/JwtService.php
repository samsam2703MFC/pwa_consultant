<?php
namespace App\Consultant\app\Services\Auth;

use App\Consultant\app\Models\Me\LoggedUserModel;
use App\Consultant\core\Cookie\CookieManager;

class JwtService {

    /**
     * Dekoduje payload JWT (bez weryfikacji podpisu — weryfikuje API).
     */
    public function getTokenData(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true) ?? [];
        $payload['expiry_date'] = $this->getTokenExpireDate($payload['exp'] ?? null);

        return [
            'logged_user'       => new LoggedUserModel($payload),
            'expiry_token_date' => $payload['expiry_date'],
        ];
    }

    public function getExpiryTokenDate(string $token): string
    {
        $data = $this->getTokenData($token);
        return $data['expiry_token_date'] ?? date('Y-m-d H:i:s');
    }

    /**
     * Refresh token może być opaque (nie-JWT) — wyliczamy expiry na 30 dni.
     */
    public function getExpiryRefreshTokenDate(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode($this->base64UrlDecode($parts[1]), true) ?? [];
            if (isset($payload['exp'])) {
                return $this->getTokenExpireDate($payload['exp']);
            }
        }

        // Opaque refresh token — 30 dni
        return date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
    }

    public function getLoggedUserObj(): ?LoggedUserModel
    {
        $cookieManager = new CookieManager();
        $token = $cookieManager->getAccessToken();
        if (!$token) return null;

        $data = $this->getTokenData($token);
        return $data['logged_user'] ?? null;
    }

    /**
     * Zwraca surowy payload JWT (bez weryfikacji podpisu — weryfikuje API).
     * Analogicznie do admin JwtService::getClaimsUnsafe().
     */
    public function getClaimsRaw(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        return json_decode($this->base64UrlDecode($parts[1]), true) ?? [];
    }

    private function base64UrlDecode(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $data = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($data);
    }

    public function getTokenExpireDate(?int $unix_timestamp): string
    {
        if ($unix_timestamp === null) return date('Y-m-d H:i:s');
        return date('Y-m-d H:i:s', $unix_timestamp);
    }
}

