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
}
