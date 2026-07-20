<?php
namespace App\Consultant\app\Services\Shop;

use App\Consultant\app\Repositories\Shop\ShopRepository;

class ShopService
{
    public function __construct(private ShopRepository $shopRepository) {}

    public function getAllShops(): array
    {
        return $this->shopRepository->getAllShops();
    }
}

