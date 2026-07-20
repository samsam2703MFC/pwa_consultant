<?php
namespace App\Consultant\app\Models\Auth;

class JWTModel {
    private string  $token;
    private ?string $refresh_token;
    private ?int    $expires_in;

    public function __construct(array $data)
    {
        $this->token         = $data['access_token'] ?? ($data['token'] ?? '');
        $this->refresh_token = $data['refresh_token'] ?? null;
        $this->expires_in    = isset($data['expires_in']) ? (int)$data['expires_in'] : null;
    }

    public function getToken(): string        { return $this->token; }
    public function getRefreshToken(): ?string { return $this->refresh_token; }
    public function getExpiresIn(): ?int       { return $this->expires_in; }
}

