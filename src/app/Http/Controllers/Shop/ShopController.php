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

        // Zawartość poglądowa gdy backend nie zwrócił sklepów (DEV_NO_AUTH / brak API).
        // Karty sklepów się renderują; P&L pozostaje realny (ładowany po tapnięciu).
        if (empty($shops)) {
            $shops = $this->demoShops();
        }

        $this->view('shop/list', [
            'shops'      => $shops,
            'active_nav' => 'shops',
        ]);
    }

    /**
     * Zawartość poglądowa odwzorowująca makietę „Boutiques".
     */
    private function demoShops(): array
    {
        return [
            ['id' => 1, 'name' => 'Châtelain', 'representative_name' => 'Châtelain', 'city' => 'Bruxelles', 'address' => 'Rue du Bailli 2'],
            ['id' => 2, 'name' => 'Flagey',    'representative_name' => 'Flagey',    'city' => 'Ixelles',   'address' => 'Place Flagey 12'],
            ['id' => 3, 'name' => 'Sablon',    'representative_name' => 'Sablon',    'city' => 'Bruxelles', 'address' => 'Grand Sablon 8'],
        ];
    }
}

