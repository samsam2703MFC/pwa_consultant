<?php
namespace App\Consultant\app\Http\Controllers\Shop;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Shop\ShopService;

class ShopController extends Controller
{
    public function __construct(private ShopService $shopService) {}

    public function index(): void
    {
        $shops = $this->shopService->getAllShops();

        $this->view('shop/list', [
            'shops'      => $shops,
            'active_nav' => 'shops',
        ]);
    }
}

