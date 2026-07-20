<?php
namespace App\Consultant\app\Repositories\Auth;

use App\Consultant\app\Models\Auth\JWTModel;
use App\Consultant\core\Http\ApiClient;

class LoginRepository {

    public function __construct(private ApiClient $apiClient) {}

    /**
     * Logowanie konsultanta.
     * Zwraca ['success' => bool, 'jwt' => JWTModel|null, 'error_code' => string|null]
     */
    public function login(array $data): array
    {
        $response = $this->apiClient->login('/consultant/auth/login', $data);

        if (isset($response['access_token'])) {
            return ['success' => true, 'jwt' => new JWTModel($response), 'error_code' => null];
        }

        return [
            'success'    => false,
            'jwt'        => null,
            'error_code' => $response['error_code'] ?? null,
        ];
    }

    public function refresh(string $refreshToken): ?JWTModel
    {
        $response = $this->apiClient->login('/consultant/auth/refresh', ['refresh_token' => $refreshToken]);

        if (isset($response['access_token'])) {
            return new JWTModel($response);
        }

        return null;
    }

    public function logout(string $refreshToken): void
    {
        $this->apiClient->login('/consultant/auth/logout', ['refresh_token' => $refreshToken]);
    }
}

