<?php
namespace App\Consultant\app\Services\Campaign;

use App\Consultant\app\Repositories\Campaign\CampaignRepository;

/**
 * Campagnes commerciales normalisées pour le rapport. Reprend l'extraction
 * tolérante de l'écran Targets (clés alias, première valeur non vide gagne) :
 * CA target vs CA réalisé (→ % d'atteinte), leads générés/ouverts (→ conversion).
 */
class CampaignService
{
    public function __construct(private CampaignRepository $repo) {}

    /** Campagnes d'un magasin sur la période, normalisées et triées par % décroissant. */
    public function forShop(int $shopId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->repo->getCampaigns($shopId, $from, $to) as $c) {
            $out[] = $this->normalize($c);
        }
        usort($out, fn($a, $b) => ($b['pct'] ?? -1) <=> ($a['pct'] ?? -1));
        return $out;
    }

    /**
     * Agrège plusieurs listes (une par boutique) par NOM de campagne : somme des
     * CA target/réalisé et des leads sur le réseau, % et conversion recalculés.
     *
     * @param array $lists  liste de listes de campagnes normalisées
     */
    public function mergeByName(array $lists): array
    {
        $acc = [];
        foreach ($lists as $list) {
            foreach ($list as $c) {
                $key = mb_strtolower(trim((string)$c['name']));
                if (!isset($acc[$key])) {
                    $acc[$key] = [
                        'name' => $c['name'], 'type' => $c['type'],
                        'target' => 0.0, 'actual' => 0.0,
                        'leads_generated' => 0, 'leads_opened' => 0,
                        'shops' => 0, 'has_target' => false, 'has_leads' => false,
                    ];
                }
                $a = &$acc[$key];
                if ($c['target'] !== null) { $a['target'] += $c['target']; $a['has_target'] = true; }
                if ($c['actual'] !== null) { $a['actual'] += $c['actual']; }
                if ($c['leads_generated'] !== null) { $a['leads_generated'] += $c['leads_generated']; $a['has_leads'] = true; }
                if ($c['leads_opened'] !== null) { $a['leads_opened'] += $c['leads_opened']; }
                $a['shops']++;
                unset($a);
            }
        }
        $out = [];
        foreach ($acc as $a) {
            $out[] = $this->withRates([
                'name' => $a['name'], 'type' => $a['type'],
                'target' => $a['has_target'] ? $a['target'] : null,
                'actual' => $a['actual'],
                'leads_generated' => $a['has_leads'] ? $a['leads_generated'] : null,
                'leads_opened' => $a['has_leads'] ? $a['leads_opened'] : null,
                'shops' => $a['shops'],
            ]);
        }
        usort($out, fn($x, $y) => ($y['pct'] ?? -1) <=> ($x['pct'] ?? -1));
        return $out;
    }

    /** Totaux réseau (CA target/réalisé cumulés, % global). */
    public function totals(array $campaigns): array
    {
        $target = 0.0; $actual = 0.0; $hasTarget = false;
        foreach ($campaigns as $c) {
            if ($c['target'] !== null) { $target += $c['target']; $hasTarget = true; }
            if ($c['actual'] !== null) { $actual += $c['actual']; }
        }
        return [
            'count'  => count($campaigns),
            'target' => $hasTarget ? $target : null,
            'actual' => $actual,
            'pct'    => ($hasTarget && $target > 0) ? ($actual / $target) * 100 : null,
        ];
    }

    private function normalize(array $c): array
    {
        return $this->withRates([
            'name'            => $this->str($c, ['name', 'title', 'label']) ?? '—',
            'type'            => $this->str($c, ['type', 'category', 'kind']) ?? '',
            'target'          => $this->num($c, ['target_revenue', 'ca_target', 'revenue_target', 'target']),
            'actual'          => $this->num($c, ['actual_revenue', 'revenue', 'ca', 'actual', 'realised_revenue']),
            'leads_generated' => $this->numInt($c, ['leads_generated', 'leads', 'leads_count']),
            'leads_opened'    => $this->numInt($c, ['leads_opened', 'opened_leads', 'leads_open']),
        ]);
    }

    /** Ajoute le % d'atteinte (réalisé/target) et la conversion (ouverts/générés). */
    private function withRates(array $c): array
    {
        $c['pct'] = ($c['target'] !== null && $c['target'] > 0 && $c['actual'] !== null)
            ? ($c['actual'] / $c['target']) * 100 : null;
        $c['conv'] = ($c['leads_generated'] !== null && $c['leads_generated'] > 0 && $c['leads_opened'] !== null)
            ? ($c['leads_opened'] / $c['leads_generated']) * 100 : null;
        return $c;
    }

    private function str(array $o, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($o[$k]) && is_string($o[$k]) && trim($o[$k]) !== '') {
                return trim($o[$k]);
            }
        }
        return null;
    }

    private function num(array $o, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (isset($o[$k]) && is_numeric($o[$k])) {
                return (float)$o[$k];
            }
        }
        return null;
    }

    private function numInt(array $o, array $keys): ?int
    {
        $v = $this->num($o, $keys);
        return $v === null ? null : (int)round($v);
    }
}
