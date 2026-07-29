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
        $api = $this->shopRepository->getSalesKpisManyFromApi($windows);
        $out = [];
        foreach ($windows as $w) {
            $key = (int)($w['shop'] ?? 0) . '|' . ($w['from'] ?? '') . '|' . ($w['to'] ?? '');
            $out[$key] = $api[$key]
                ?? $this->shopSales->getSalesKpis((int)($w['shop'] ?? 0), (string)($w['from'] ?? ''), (string)($w['to'] ?? ''));
        }
        return $out;
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
    public function getMarginHeatmap(int $shopId, string $from, string $to): ?array
    {
        return $this->shopRepository->getMarginHeatmap($shopId, $from, $to);
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
}
