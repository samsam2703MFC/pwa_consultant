<?php
namespace App\Consultant\app\Repositories\Target;

use App\Consultant\core\Http\ApiClient;

class ShopMetricTargetRepository
{
    public function __construct(
        private ApiClient $apiClient,
        private \App\Consultant\app\Repositories\Shop\ShopRepository $shops
    ) {}

    public function getTargets(int $shopId, int $year, int $month): array
    {
        $response = $this->apiClient->get(
            "/consultant/shops/{$shopId}/targets?year={$year}&month={$month}"
        );
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }

    /**
     * Targets de PLUSIEURS couples (magasin, année, mois) en parallèle
     * (curl_multi) — pour les vues multi-mois (Tendances).
     *
     * @param array $reqs liste de ['shop'=>int,'year'=>int,'month'=>int]
     * @return array<string, array> map "shop|year|month" => targets ([] si indisponible)
     */
    public function getTargetsMany(array $reqs): array
    {
        $out = [];
        if ($reqs === []) {
            return $out;
        }

        // P6b : une boutique sur plusieurs mois → un seul appel « range ».
        // P6a : plusieurs boutiques sur un même mois → un seul appel global.
        $shops  = array_unique(array_map(fn($q) => (int)($q['shop'] ?? 0), $reqs));
        $months = array_unique(array_map(fn($q) => sprintf('%04d-%02d', (int)($q['year'] ?? 0), (int)($q['month'] ?? 0)), $reqs));
        if (count($shops) === 1 && count($months) > 1) {
            $sid = (int)reset($shops);
            sort($months);
            $range = $this->shops->getTargetsRange($sid, (string)reset($months), (string)end($months));
            if ($range !== null) {
                foreach ($reqs as $q) {
                    $ym = sprintf('%04d-%02d', (int)$q['year'], (int)$q['month']);
                    $out["{$sid}|" . (int)$q['year'] . '|' . (int)$q['month']] = $range[$ym] ?? [];
                }
                return $out;
            }
        }
        // P6a, mois par mois : plusieurs boutiques sur N mois → un appel global
        // PAR MOIS (12 appels pour les tendances) au lieu d'un appel par couple
        // (boutique, mois), soit plusieurs centaines. Le disjoncteur de
        // ShopRepository fait que l'absence de P6a ne coûte qu'une seule sonde.
        $served = [];
        if (count($shops) > 1) {
            foreach ($months as $ym) {
                [$y, $m] = array_map('intval', explode('-', (string)$ym));
                $all = $this->shops->getTargetsAllShops($y, $m);
                if ($all === null) {
                    break;   // endpoint indisponible → repli pour tous les mois
                }
                foreach ($shops as $sid) {
                    $sid = (int)$sid;
                    $out["{$sid}|{$y}|{$m}"] = $all[$sid] ?? [];
                    $served["{$sid}|{$y}|{$m}"] = true;
                }
            }
        }

        $byKey = [];
        foreach ($reqs as $q) {
            $s = (int)($q['shop'] ?? 0);
            $y = (int)($q['year'] ?? 0);
            $m = (int)($q['month'] ?? 0);
            if (isset($served["$s|$y|$m"])) {
                continue;
            }
            $out["$s|$y|$m"] = [];
            $byKey["$s|$y|$m"] = "/consultant/shops/{$s}/targets?year={$y}&month={$m}";
        }
        if ($byKey === []) {
            return $out;
        }
        $responses = [];
        foreach (array_chunk(array_values(array_unique($byKey)), 24) as $chunk) {
            $responses += $this->apiClient->getMany($chunk);
        }
        foreach ($byKey as $key => $ep) {
            $r = $responses[$ep] ?? null;
            if (is_array($r) && !empty($r['success']) && is_array($r['data'] ?? null)) {
                $out[$key] = $r['data'];
            }
        }
        return $out;
    }

    public function saveTargets(int $shopId, array $payload): array
    {
        return $this->apiClient->put("/consultant/shops/{$shopId}/targets", $payload);
    }

    public function copyFromPreviousMonth(int $shopId, array $payload): array
    {
        return $this->apiClient->post("/consultant/shops/{$shopId}/targets/copy", $payload);
    }

    public function getMetricDefinitions(): array
    {
        $response = $this->apiClient->get('/consultant/metric-definitions');
        return ($response['success'] && isset($response['data'])) ? $response['data'] : [];
    }
}
