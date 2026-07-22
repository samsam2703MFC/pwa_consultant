<?php
namespace App\Consultant\app\Repositories\Google;

use Throwable;

/**
 * Note Google d'un magasin (Google Business / Places).
 *
 * La clé API n'est PAS committée : lue depuis config/google.local.php
 * (hors Git, généré au déploiement depuis le secret GOOGLE_PLACES_KEY).
 * Clé absente → getRating() renvoie null (dégradation propre, le KPI
 * « Note Google » affiche simplement « données à venir »).
 *
 * Deux appels Google possibles selon l'API activée dans le projet Cloud :
 *   1) Places API (New) — POST places:searchText, renvoie note + nombre
 *      d'avis en UN appel (privilégié).
 *   2) Places API (legacy) — findplacefromtext puis details (repli).
 * Le résultat est mis en cache fichier (TTL long) pour rester très en
 * dessous du quota : une note Google ne bouge pas dans la journée.
 */
class GoogleRatingRepository
{
    private const CACHE_TTL = 43200;   // 12 h
    private const CACHE_DIR = 'pwa_consultant_google';

    private ?array $cfg = null;
    private bool $tried = false;

    /**
     * @param string $address adresse Google du magasin (champ google_address
     *               de la table shop) — plus fiable que nom+ville pour trouver
     *               la bonne fiche. Vide → repli sur nom+ville.
     * @param string|null $placeId Place ID explicite (champ google_place_id) —
     *               le plus fiable, court-circuite toute recherche.
     * @return array{rating:float, reviews:int}|null
     */
    public function getRating(int $shopId, string $name, string $city = '', string $address = '', ?string $placeId = null): ?array
    {
        $cfg = $this->config();
        if ($cfg === null || empty($cfg['places_key'])) {
            return null;
        }

        $cached = $this->cacheRead($shopId);
        if ($cached !== null) {
            return $cached;
        }

        $key = (string)$cfg['places_key'];
        // Priorité de résolution de la fiche : Place ID de la config >
        // Place ID du magasin > adresse Google du magasin > nom + ville.
        $placeId = $cfg['place_ids'][$shopId] ?? ($placeId !== null && $placeId !== '' ? $placeId : null);
        $query = trim($address) !== ''
            ? trim($address)
            : trim($name . ' ' . $city);

        $res = $this->fetchNew($key, $query, $placeId)
            ?? $this->fetchLegacy($key, $query, $placeId);

        if ($res !== null) {
            $this->cacheWrite($shopId, $res);
        }
        return $res;
    }

    // ── Places API (New) : un seul appel, note + nombre d'avis ──────────
    private function fetchNew(string $key, string $query, ?string $placeId): ?array
    {
        try {
            if ($placeId !== null) {
                $body = null;
                $url  = 'https://places.googleapis.com/v1/places/' . rawurlencode($placeId);
                $headers = [
                    'X-Goog-Api-Key: ' . $key,
                    'X-Goog-FieldMask: rating,userRatingCount',
                ];
                $json = $this->httpGet($url, $headers);
                if ($json === null) {
                    return null;
                }
                return $this->pickNew($json);
            }

            $url  = 'https://places.googleapis.com/v1/places:searchText';
            $headers = [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $key,
                'X-Goog-FieldMask: places.rating,places.userRatingCount,places.id',
            ];
            $json = $this->httpPost($url, json_encode(['textQuery' => $query]), $headers);
            if ($json === null || empty($json['places'][0])) {
                return null;
            }
            return $this->pickNew($json['places'][0]);
        } catch (Throwable $e) {
            error_log('[google] Places New échoué: ' . $e->getMessage());
            return null;
        }
    }

    private function pickNew(array $p): ?array
    {
        if (!isset($p['rating'])) {
            return null;
        }
        return [
            'rating'  => round((float)$p['rating'], 2),
            'reviews' => (int)($p['userRatingCount'] ?? 0),
        ];
    }

    // ── Places API (legacy) : findplacefromtext puis details ────────────
    private function fetchLegacy(string $key, string $query, ?string $placeId): ?array
    {
        try {
            if ($placeId === null) {
                $find = $this->httpGet(
                    'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
                    . '?input=' . rawurlencode($query)
                    . '&inputtype=textquery&fields=place_id&key=' . rawurlencode($key)
                );
                $placeId = $find['candidates'][0]['place_id'] ?? null;
                if ($placeId === null) {
                    return null;
                }
            }
            $det = $this->httpGet(
                'https://maps.googleapis.com/maps/api/place/details/json'
                . '?place_id=' . rawurlencode($placeId)
                . '&fields=rating,user_ratings_total&key=' . rawurlencode($key)
            );
            $r = $det['result'] ?? null;
            if ($r === null || !isset($r['rating'])) {
                return null;
            }
            return [
                'rating'  => round((float)$r['rating'], 2),
                'reviews' => (int)($r['user_ratings_total'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('[google] Places legacy échoué: ' . $e->getMessage());
            return null;
        }
    }

    // ── HTTP ─────────────────────────────────────────────────────────────
    private function httpGet(string $url, array $headers = []): ?array
    {
        return $this->request($url, 'GET', null, $headers);
    }
    private function httpPost(string $url, string $body, array $headers): ?array
    {
        return $this->request($url, 'POST', $body, $headers);
    }
    private function request(string $url, string $method, ?string $body, array $headers): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            return null;
        }
        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : null;
    }

    // ── Config ───────────────────────────────────────────────────────────
    private function config(): ?array
    {
        if ($this->tried) {
            return $this->cfg;
        }
        $this->tried = true;
        $file = __DIR__ . '/../../../../config/google.local.php';
        if (is_file($file)) {
            $c = require $file;
            if (is_array($c) && !empty($c['places_key']) && $c['places_key'] !== 'REMPLACER_CLE') {
                $this->cfg = $c;
            }
        }
        return $this->cfg;
    }

    // ── Cache fichier ────────────────────────────────────────────────────
    private function cachePath(int $shopId): string
    {
        $dir = sys_get_temp_dir() . '/' . self::CACHE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/shop_' . $shopId . '.json';
    }
    private function cacheRead(int $shopId): ?array
    {
        $raw = @file_get_contents($this->cachePath($shopId));
        if ($raw === false) {
            return null;
        }
        $e = json_decode($raw, true);
        if (!is_array($e) || !isset($e['t'], $e['r']) || (time() - (int)$e['t']) > self::CACHE_TTL) {
            return null;
        }
        return is_array($e['r']) ? $e['r'] : null;
    }
    private function cacheWrite(int $shopId, array $res): void
    {
        @file_put_contents($this->cachePath($shopId), json_encode(['t' => time(), 'r' => $res]));
    }
}
