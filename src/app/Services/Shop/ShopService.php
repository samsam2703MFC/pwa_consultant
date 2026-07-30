<?php
namespace App\Consultant\app\Services\Shop;

use App\Consultant\app\Repositories\Shop\ShopRepository;
use App\Consultant\app\Repositories\Shop\ShopSalesRepository;

class ShopService
{
    public function __construct(
        private ShopRepository $shopRepository,
        private ShopSalesRepository $shopSales,
    ) {}

    /**
     * Magasins ACTIFS uniquement (shop actif = 1), pour toute l'app.
     * Ordre de décision par magasin :
     *   1. champ « actif » renvoyé par l'API (active / is_active / enabled…) ;
     *   2. sinon, colonne « actif » de la table locale `shops` ;
     *   3. sinon (aucun indicateur), le magasin reste visible.
     */
    public function getAllShops(): array
    {
        $shops = $this->shopRepository->getAllShops();
        if ($shops === []) {
            return $shops;
        }

        $dbFlags = null;          // chargé au premier besoin seulement
        $dbFlagsLoaded = false;

        $filtered = [];
        foreach ($shops as $shop) {
            $active = null;

            foreach (['active', 'is_active', 'enabled', 'is_enabled', 'shop_active'] as $key) {
                if (array_key_exists($key, $shop)) {
                    $active = (int)$shop[$key] === 1;
                    break;
                }
            }

            if ($active === null) {
                if (!$dbFlagsLoaded) {
                    $dbFlags = $this->shopSales->getActiveShopIds();
                    $dbFlagsLoaded = true;
                }
                $id = (int)($shop['id'] ?? 0);
                if ($dbFlags !== null && array_key_exists($id, $dbFlags)) {
                    $active = $dbFlags[$id];
                }
            }

            if ($active !== false) {
                $filtered[] = $shop;
            }
        }

        return $filtered;
    }

    public function getPnl(int $shopId, string $period = 'day'): array
    {
        return $this->shopRepository->getPnl($shopId, $period);
    }

    /**
     * KPI de vente d'un magasin sur une FENÊTRE DE DATES [from, to] (Y-m-d,
     * inclusives) — pour les rapports hebdo/mensuel sur une période passée.
     * Source de vérité l'API backend ; repli sur le calcul local identique si
     * l'endpoint n'est pas disponible (même mécanisme que Boutiques/day-sales).
     *
     * @return array{tickets:int, ca:float, products:int, avg_basket:?float, products_per_ticket:?float}
     */
    public function getSalesKpis(int $shopId, string $from, string $to): array
    {
        $api = $this->shopRepository->getSalesKpisFromApi($shopId, $from, $to);
        if ($api !== null) {
            return $api;
        }
        return $this->shopSales->getSalesKpis($shopId, $from, $to);
    }

    /**
     * KPI de vente pour PLUSIEURS fenêtres [shop, from, to] en un minimum
     * d'allers-retours (API en parallèle, repli local par fenêtre manquante) —
     * même sémantique que getSalesKpis(), pour les vues multi-mois (Tendances).
     *
     * @param array $windows liste de ['shop'=>int,'from'=>'Y-m-d','to'=>'Y-m-d']
     * @return array<string, array> map "shop|from|to" => KPIs
     */
    public function getSalesKpisBatch(array $windows): array
    {
        // P3 : si plusieurs boutiques partagent LA MÊME fenêtre, un seul appel
        // multi-boutiques remplace autant d'appels unitaires.
        $api = [];
        $byWindow = [];
        foreach ($windows as $w) {
            $byWindow[($w['from'] ?? '') . '|' . ($w['to'] ?? '')][] = (int)($w['shop'] ?? 0);
        }
        $remaining = [];
        // P3 n'est sondé qu'UNE fois : une réponse vide n'est pas un échec HTTP,
        // donc le disjoncteur ne la voit pas — sans ce garde-fou, chaque fenêtre
        // paierait sa propre sonde (24 fenêtres = 24 attentes en série).
        $p3Dead = false;
        foreach ($byWindow as $win => $shopIds) {
            [$f, $t] = explode('|', $win);
            $all = (!$p3Dead && count($shopIds) > 1)
                ? $this->shopRepository->getSalesKpisAllShops($f, $t)
                : null;
            if ($all === null && count($shopIds) > 1) {
                $p3Dead = true;
            }
            if ($all !== null) {
                foreach ($shopIds as $sid) {
                    if (isset($all[$sid])) {
                        $api["{$sid}|{$f}|{$t}"] = $all[$sid];
                    }
                }
                continue;
            }
            foreach ($shopIds as $sid) {
                $remaining[] = ['shop' => $sid, 'from' => $f, 'to' => $t];
            }
        }
        $api += $this->shopRepository->getSalesKpisManyFromApi($remaining);
        $out = [];

        // Repli local : les fenêtres « mois civil complet » d'une même
        // boutique sont regroupées en UNE requête SQL (GROUP BY mois) — une
        // requête par fenêtre ferait exploser le temps de réponse des vues
        // multi-mois. Les fenêtres partielles gardent le repli unitaire.
        $monthly = [];   // shopId => ['min'=>Y-m-d, 'max'=>Y-m-d, 'wins'=>[key => 'YYYY-MM']]
        $others  = [];
        foreach ($windows as $w) {
            $sid  = (int)($w['shop'] ?? 0);
            $from = (string)($w['from'] ?? '');
            $to   = (string)($w['to'] ?? '');
            $key  = "$sid|$from|$to";
            if (($api[$key] ?? null) !== null) {
                $out[$key] = $api[$key];
                continue;
            }
            if ($this->isFullMonth($from, $to)) {
                $monthly[$sid]['wins'][$key] = substr($from, 0, 7);
                $monthly[$sid]['min'] = isset($monthly[$sid]['min']) ? min($monthly[$sid]['min'], $from) : $from;
                $monthly[$sid]['max'] = isset($monthly[$sid]['max']) ? max($monthly[$sid]['max'], $to) : $to;
            } else {
                $others[] = [$sid, $from, $to, $key];
            }
        }
        foreach ($monthly as $sid => $info) {
            $byYm = $this->shopSales->getMonthlyKpis($sid, $info['min'], $info['max']);
            foreach ($info['wins'] as $key => $ym) {
                $k = $byYm[$ym] ?? ['tickets' => 0, 'ca' => 0.0];
                $t = (int)$k['tickets'];
                $out[$key] = [
                    'tickets'             => $t,
                    'ca'                  => (float)$k['ca'],
                    'products'            => 0,
                    'avg_basket'          => $t > 0 ? (float)$k['ca'] / $t : null,
                    'products_per_ticket' => null,
                ];
            }
        }
        foreach ($others as [$sid, $from, $to, $key]) {
            $out[$key] = $this->shopSales->getSalesKpis($sid, $from, $to);
        }
        return $out;
    }

    /** Fenêtre = un mois civil complet (du 1er au dernier jour du même mois) ? */
    private function isFullMonth(string $from, string $to): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-01$/', $from)) {
            return false;
        }
        try {
            return $to === (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Coût matière total sur [from, to] (Y-m-d) — pour le levier Food Cost. */
    public function getMaterialCost(int $shopId, string $from, string $to): ?float
    {
        return $this->shopRepository->getMaterialCost($shopId, $from, $to);
    }

    /**
     * P&L de plusieurs magasins en parallèle (un seul aller-retour réseau).
     *
     * @param int[] $shopIds
     * @return array<int, array> map shopId => données P&L.
     */
    public function getPnlMany(array $shopIds, string $period = 'day'): array
    {
        return $this->shopRepository->getPnlMany($shopIds, $period);
    }

    /** Carte de marge (jours + heures) d'un magasin sur [from, to] (≤ 31 j). */
    public function getMarginHeatmap(int $shopId, string $from, string $to, bool $weekly = false): ?array
    {
        return $this->shopRepository->getMarginHeatmap($shopId, $from, $to, $weekly);
    }

    /** Labour réel par jour ('Y-m-d' => €), ou null si l'endpoint est absent. */
    public function getDailyLabour(int $shopId, string $from, string $to): ?array
    {
        return $this->shopRepository->getDailyLabour($shopId, $from, $to);
    }

    /**
     * Cartes de marge pour plusieurs fenêtres (magasin, from, to) en parallèle.
     *
     * @param array $windows liste de ['shop'=>int,'from'=>'Y-m-d','to'=>'Y-m-d']
     * @return array<string, ?array> map "shop|from|to" => données ou null
     */
    public function getMarginHeatmapMany(array $windows): array
    {
        return $this->shopRepository->getMarginHeatmapMany($windows);
    }

    /** Ventes par catégorie d'un magasin sur [from, to] (nom => CA), ou null. */
    public function getCategorySales(int $shopId, string $from, string $to): ?array
    {
        return $this->shopRepository->getCategorySales($shopId, $from, $to);
    }

    /**
     * Ventes par catégorie de plusieurs magasins (même fenêtre) en parallèle.
     *
     * @param int[] $shopIds
     * @return array<int, ?array> map shopId => (nom => CA) ou null
     */
    public function getCategorySalesMany(array $shopIds, string $from, string $to): array
    {
        return $this->shopRepository->getCategorySalesMany($shopIds, $from, $to);
    }

    // ── Endpoints batch backend (spec P0–P8, déployés le 30/07/2026) ──
    // Chaque méthode renvoie null si l'endpoint est absent : l'appelant garde
    // alors son mécanisme actuel (parallélisme + repli local).

    /** P0 — P&L journalier ('Y-m-d' => postes), ou null. */
    public function getDailyPnl(int $shopId, string $from, string $to): ?array
    {
        return $this->shopRepository->getDailyPnl($shopId, $from, $to);
    }

    /** P2 — P&L mensuel ('YYYY-MM' => postes), ou null. */
    public function getMonthlyPnl(int $shopId, string $from, string $to): ?array
    {
        return $this->shopRepository->getMonthlyPnl($shopId, $from, $to);
    }

    /**
     * P2 en lot — P&L mensuel de plusieurs boutiques en parallèle.
     *
     * @param int[] $shopIds
     * @return array<int, array> map shopId => (map 'YYYY-MM' => postes)
     */
    public function getMonthlyPnlMany(array $shopIds, string $from, string $to): array
    {
        return $this->shopRepository->getMonthlyPnlMany($shopIds, $from, $to);
    }

    /**
     * P1 sur plusieurs plages en un aller-retour.
     *
     * @param array<int, array{0: string, 1: string}> $ranges couples [from, to]
     * @return array<string, array|null> map 'from|to' => série
     */
    public function getMonthlySalesRanges(array $ranges): array
    {
        return $this->shopRepository->getMonthlySalesRanges($ranges);
    }

    /** P1 — ventes mensuelles de toutes les boutiques, ou null. */
    public function getMonthlySalesAllShops(string $from, string $to): ?array
    {
        return $this->shopRepository->getMonthlySalesAllShops($from, $to);
    }

    /** P3 — KPI de ventes de toutes les boutiques sur une fenêtre, ou null. */
    public function getSalesKpisAllShops(string $from, string $to): ?array
    {
        return $this->shopRepository->getSalesKpisAllShops($from, $to);
    }

    /** P7 — ventes par catégorie de toutes les boutiques, ou null. */
    public function getCategorySalesAllShops(string $from, string $to): ?array
    {
        return $this->shopRepository->getCategorySalesAllShops($from, $to);
    }

    /** P8 — P&L (période courante) de toutes les boutiques, ou null. */
    public function getPnlSummaryAllShops(string $period = 'month'): ?array
    {
        return $this->shopRepository->getPnlSummaryAllShops($period);
    }
}
