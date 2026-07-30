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
    /**
     * Cache serveur des GET (par utilisateur, TTL court) : la page de
     * chargement post-login préchauffe ces entrées en parallèle via le
     * proxy, puis les écrans rendus côté serveur les trouvent déjà prêtes
     * → navigation sans attente API. Toute mutation (POST/PATCH/PUT/DELETE)
     * purge le cache de l'utilisateur pour ne jamais montrer d'état périmé.
     */
    private const CACHE_TTL    = 60;   // secondes (défaut)
    private const CACHE_PREFIX = 'pwacons_';

    /**
     * TTL par endpoint (préfixe → secondes). Les données quasi statiques (liste
     * des magasins) vivent plus longtemps ; le P&L / les stats du jour restent
     * courts. Le premier préfixe qui matche gagne → ranger du plus spécifique au
     * plus général. Ce qui n'est pas listé prend CACHE_TTL. Toute mutation purge
     * de toute façon le cache de l'utilisateur.
     */
    private const CACHE_TTL_MAP = [
        '/consultant/shops/' => 60,    // .../pnl, .../stats… — volatil
        '/consultant/shops'  => 300,   // liste des magasins — stable
        '/shops/'            => 120,
    ];

    /**
     * TTL par MOTIF (sous-chaîne) — pour les endpoints paramétrés par id, hors
     * de portée des préfixes. Testé AVANT le préfixe. Les fenêtres de ventes
     * passées et les targets d'un mois sont quasi statiques : un TTL long évite
     * de re-marteler l'API sur les vues multi-mois (Tendances : ~300 fenêtres).
     */
    private const CACHE_TTL_CONTAINS = [
        '/statistics/sales/kpis' => 1800,
        '/targets?'              => 900,
    ];

    /** Poignée cURL réutilisée dans la requête → keep-alive (pas de handshake TCP/TLS par appel). */
    private ?\CurlHandle $handle = null;

    public function __construct(
        private string $baseUrl,
        private CookieManager $cookieManager,
        private UserHeaderProvider $userHeaderProvider
    ) {
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
            $this->handle = null;
        }
    }

    private function getHeaders(): array
    {
        $headers = [];

        // Odczytuj token PRZY KAŻDYM wywołaniu — middleware auth może odświeżyć
        // token (nowy cookie) już po skonstruowaniu ApiClienta; buforowanie w
        // konstruktorze wysyłałoby wtedy stary/wygasły token → 401.
        $token = $this->cookieManager->getAccessToken();
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
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
        // Cache court par utilisateur — uniquement les réponses JSON (les
        // téléchargements binaires ne passent pas par le cache).
        if ($decode_json) {
            $cached = $this->cacheRead($endpoint);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Poignée réutilisée (keep-alive) : la connexion TCP/TLS vers l'API est
        // conservée entre les appels d'une même requête au lieu d'être
        // renégociée à chaque fois.
        $ch = $this->sharedHandle();
        $this->configureGet($ch, $this->baseUrl . $endpoint);

        $result        = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header        = substr((string)$result, 0, $header_size);
        $body          = substr((string)$result, $header_size);
        // Pas de curl_close : la poignée est réutilisée (fermée au __destruct).

        $response = ['data' => [], 'success' => false, 'error' => [], 'filename' => ''];

        if (in_array($response_code, [200, 201, 204])) {
            $response['data']    = $decode_json ? json_decode($body, true) : $body;
            $response['success'] = true;

            if (preg_match('/content-disposition:.*filename="([^"]+)"/i', $header, $matches)) {
                $response['filename'] = $matches[1];
            }

            if ($decode_json) {
                $this->cacheWrite($endpoint, $response);
            }
        } else {
            $response['error'] = $response_code;
        }

        return $response;
    }

    /**
     * Récupère PLUSIEURS endpoints GET EN PARALLÈLE (curl_multi).
     *
     * C'est le remède au N+1 séquentiel : au lieu de payer N × (latence réseau)
     * en bouclant `get()`, on lance tous les appels manquants en même temps →
     * ~1 × latence. Les endpoints déjà en cache ne repartent pas sur le réseau ;
     * les réponses fraîches sont écrites en cache comme via `get()`.
     *
     * @param string[] $endpoints
     * @return array<string, array> map endpoint => réponse (même forme que get()).
     */
    public function getMany(array $endpoints): array
    {
        $endpoints = array_values(array_unique($endpoints));
        $out     = [];
        $toFetch = [];

        foreach ($endpoints as $ep) {
            $cached = $this->cacheRead($ep);
            if ($cached !== null) {
                $out[$ep] = $cached;
            } else {
                $toFetch[] = $ep;
            }
        }

        if ($toFetch === []) {
            return $out;
        }

        // Un seul manquant : le parallélisme n'apporte rien, on réutilise get()
        // (et sa poignée keep-alive).
        if (count($toFetch) === 1) {
            $out[$toFetch[0]] = $this->get($toFetch[0]);
            return $out;
        }

        $mh      = curl_multi_init();
        $handles = [];
        foreach ($toFetch as $ep) {
            $ch = curl_init();
            $this->configureGet($ch, $this->baseUrl . $ep);
            curl_multi_add_handle($mh, $ch);
            $handles[$ep] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $ep => $ch) {
            $result        = curl_multi_getcontent($ch);
            $response_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header        = substr((string)$result, 0, $header_size);
            $body          = substr((string)$result, $header_size);

            $response = ['data' => [], 'success' => false, 'error' => [], 'filename' => ''];
            if (in_array($response_code, [200, 201, 204], true)) {
                $response['data']    = json_decode($body, true);
                $response['success'] = true;
                if (preg_match('/content-disposition:.*filename="([^"]+)"/i', $header, $m)) {
                    $response['filename'] = $m[1];
                }
                $this->cacheWrite($ep, $response);
            } else {
                $response['error'] = $response_code;
            }

            $out[$ep] = $response;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }

    /** Poignée cURL partagée pour la requête (keep-alive). */
    private function sharedHandle(): \CurlHandle
    {
        if ($this->handle === null) {
            $this->handle = curl_init();
        } else {
            curl_reset($this->handle);
        }
        return $this->handle;
    }

    /** Options communes à tous les GET (timeouts, en-têtes, gzip, keep-alive). */
    private function configureGet(\CurlHandle $ch, string $url): void
    {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');       // gzip/deflate/br négociés → réponses compressées
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    }

    // ── Cache GET par utilisateur ───────────────────────────────────────────

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/pwa_consultant_api_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Identité STABLE de l'utilisateur pour la clé de cache : lue dans le
     * payload du JWT (le token lui-même est rafraîchi par le middleware —
     * sa signature change, pas l'identité). Repli : hash du token.
     */
    private function cacheUserKey(): string
    {
        $token = $this->cookieManager->getAccessToken();
        if (!$token) {
            return 'anon';
        }
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);
            if (is_array($claims)) {
                foreach (['membership_id', 'usr_membership', 'sub', 'user_id', 'id'] as $k) {
                    if (!empty($claims[$k]) && is_scalar($claims[$k])) {
                        return 'u' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$claims[$k]);
                    }
                }
            }
        }
        return substr(md5($token), 0, 16);
    }

    private function cachePath(string $endpoint): string
    {
        return $this->cacheDir() . '/' . self::CACHE_PREFIX . $this->cacheUserKey() . '_' . md5($endpoint) . '.json';
    }

    /** TTL applicable à un endpoint (cf. CACHE_TTL_MAP), défaut CACHE_TTL. */
    private function ttlFor(string $endpoint): int
    {
        foreach (self::CACHE_TTL_CONTAINS as $needle => $ttl) {
            if (str_contains($endpoint, $needle)) {
                return $ttl;
            }
        }
        foreach (self::CACHE_TTL_MAP as $prefix => $ttl) {
            if (str_starts_with($endpoint, $prefix)) {
                return $ttl;
            }
        }
        return self::CACHE_TTL;
    }

    private function cacheRead(string $endpoint): ?array
    {
        $path = $this->cachePath($endpoint);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        $entry = $raw !== false ? json_decode($raw, true) : null;
        // TTL lu dans l'entrée (écrit au moment du cache) ; repli sur CACHE_TTL
        // pour les anciennes entrées sans champ ttl.
        $ttl = isset($entry['ttl']) ? (int)$entry['ttl'] : self::CACHE_TTL;
        if (!is_array($entry) || !isset($entry['t'], $entry['r']) || (time() - (int)$entry['t']) > $ttl) {
            @unlink($path);
            return null;
        }
        return is_array($entry['r']) ? $entry['r'] : null;
    }

    private function cacheWrite(string $endpoint, array $response): void
    {
        @file_put_contents(
            $this->cachePath($endpoint),
            json_encode(['t' => time(), 'ttl' => $this->ttlFor($endpoint), 'r' => $response]),
            LOCK_EX
        );
    }

    /** Purge toutes les entrées de l'utilisateur courant (après mutation). */
    public function purgeCache(): void
    {
        $files = glob($this->cacheDir() . '/' . self::CACHE_PREFIX . $this->cacheUserKey() . '_*.json') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
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

        $this->purgeCache(); // données modifiées → les écrans refetchent
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

        $this->purgeCache(); // données modifiées → les écrans refetchent

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
        $this->purgeCache(); // données modifiées → les écrans refetchent
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

        $this->purgeCache(); // données modifiées → les écrans refetchent
        return $response;
    }
}

