<?php
namespace App\Consultant\core\Http;

use App\Consultant\core\Cookie\CookieManager;
use App\Consultant\core\Support\UserHeaderProvider;

/**
 * Klient HTTP do komunikacji z API.
 * Token JWT pobierany jest z ciasteczka przez CookieManager (Iteracja 2).
 */
class ApiClient
{
    private ?string $jwtToken;

    public function __construct(
        private string $baseUrl,
        CookieManager $cookieManager,
        private UserHeaderProvider $userHeaderProvider
    ) {
        $this->jwtToken = $cookieManager->getAccessToken();
    }

    private function getHeaders(): array
    {
        $headers = [];

        if ($this->jwtToken) {
            $headers[] = 'Authorization: Bearer ' . $this->jwtToken;
        }

        $language = $this->userHeaderProvider->getLanguage();
        if ($language) {
            $headers[] = 'Accept-Language: ' . $language;
        }

        return $headers;
    }

    public function where(string $endpoint, array $params, bool $decode_json = true): array
    {
        $queryString = http_build_query($params);
        return $this->get($endpoint . '?' . $queryString, $decode_json);
    }

    public function get(string $endpoint, bool $decode_json = true): array
    {
        $url  = $this->baseUrl . $endpoint;
        $ch   = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_HEADER, true);

        $result        = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header        = substr($result, 0, $header_size);
        $body          = substr($result, $header_size);
        curl_close($ch);
        $response = ['data' => [], 'success' => false, 'error' => [], 'filename' => ''];

        if (in_array($response_code, [200, 201, 204])) {
            $response['data']    = $decode_json ? json_decode($body, true) : $body;
            $response['success'] = true;

            if (preg_match('/content-disposition:.*filename="([^"]+)"/i', $header, $matches)) {
                $response['filename'] = $matches[1];
            }
        } else {
            $response['error'] = $response_code;
        }

        return $response;
    }

    public function login(string $endpoint, array $data): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->getHeaders(), ['Content-Type: application/json']));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Błąd połączenia (backend nieosiągalny, timeout, DNS/SSL) — nie mylić
        // z błędnymi danymi logowania. Logujemy powód po stronie serwera.
        if ($response === false || $curlErr !== '') {
            error_log("[auth] POST {$url} — connection error: {$curlErr}");
            return ['error_code' => 'CONNECTION_ERROR'];
        }

        $decoded = json_decode($response, true);

        // Niepowodzenie HTTP (np. 404 = zły endpoint, 500 = błąd backendu,
        // 401/422 = złe dane). Logujemy status i skrócone ciało do diagnozy.
        if (!in_array($status, [200, 201], true)) {
            error_log("[auth] POST {$url} — HTTP {$status} body=" . substr((string)$response, 0, 300));
        }

        return is_array($decoded) ? $decoded : ['error_code' => 'BAD_RESPONSE'];
    }

    /**
     * Wariant diagnostyczny logowania: zwraca surowy status HTTP, treść i błąd
     * curl (bez ukrywania) — do ekranu diagnostycznego /auth?diag=1.
     * NIE używać w normalnym przepływie.
     */
    public function loginDebug(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->getHeaders(), ['Content-Type: application/json']));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return [
            'url'        => $url,
            'status'     => $status,
            'curl_error' => $err,
            'body'       => $body === false ? '' : (string)$body,
            'json'       => is_string($body) ? json_decode($body, true) : null,
        ];
    }

    public function post(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->getHeaders(), ['Content-Type: application/json']));

        $result        = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = ['message' => '', 'inserted_id' => -1, 'success' => false, 'error' => [], 'code' => $response_code];

        if (in_array($response_code, [200, 201, 204])) {
            $decoded               = json_decode($result, true);
            $response['message']   = $decoded['message'] ?? null;
            $response['inserted_id'] = $decoded['inserted_id'] ?? null;
            $response['success']   = true;
        } else {
            $decoded                 = json_decode($result, true);
            $response['description'] = $decoded['description'] ?? null;
        }

        return $response;
    }

    public function patch(string $endpoint, array $data): array
    {
        return $this->sendWithMethod('PATCH', $endpoint, $data);
    }

    public function put(string $endpoint, array $data): array
    {
        return $this->sendWithMethod('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        $response      = curl_exec($ch);
        $response_code = curl_getinfo($ch)['http_code'];
        curl_close($ch);

        if (in_array($response_code, [200, 201, 204])) {
            return ['success' => true, 'message' => ''];
        }

        $decoded = json_decode($response, true);
        return ['success' => false, 'description' => $decoded['description'] ?? ''];
    }

    public function postMultipart(string $endpoint, array $fields, array $files = []): array
    {
        $url     = $this->baseUrl . $endpoint;
        $headers = array_filter($this->getHeaders(), fn($h) => stripos($h, 'Content-Type:') !== 0);

        $payload = $fields;
        foreach ($files as $fieldName => $fileArr) {
            if (!$fileArr || ($fileArr['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp  = $fileArr['tmp_name'] ?? '';
            if (!$tmp || !is_file($tmp)) continue;
            $mime = $fileArr['type'] ?? 'application/octet-stream';
            $name = $fileArr['name'] ?? ('upload_' . time());
            $payload[$fieldName] = new \CURLFile($tmp, $mime, $name);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result        = curl_exec($ch);
        $response_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $response = ['message' => null, 'inserted_id' => null, 'success' => false, 'error' => [], 'code' => $response_code, 'description' => null];

        if ($result === false) {
            $response['description'] = 'cURL error: ' . curl_error($ch);
            curl_close($ch);
            return $response;
        }

        if (in_array($response_code, [200, 201, 204])) {
            $decoded               = json_decode($result, true);
            $response['message']   = $decoded['message'] ?? null;
            $response['inserted_id'] = $decoded['inserted_id'] ?? null;
            $response['success']   = true;
        } else {
            $decoded                 = json_decode($result, true);
            $response['description'] = $decoded['description'] ?? $result;
        }

        curl_close($ch);
        return $response;
    }

    private function sendWithMethod(string $method, string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->getHeaders(), ['Content-Type: application/json']));

        $result        = curl_exec($ch);
        $response_code = curl_getinfo($ch)['http_code'];

        $response = ['message' => '', 'inserted_id' => -1, 'success' => false, 'error' => [], 'code' => $response_code];

        if (in_array($response_code, [200, 201, 204])) {
            $decoded               = json_decode($result, true);
            $response['message']   = $decoded['message'] ?? null;
            $response['inserted_id'] = $decoded['inserted_id'] ?? null;
            $response['success']   = true;
        } else {
            $decoded                 = json_decode($result, true);
            $response['description'] = $decoded['description'] ?? null;
        }

        return $response;
    }
}

