<?php
namespace App\Consultant\app\Http\Controllers\Shop;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopSalesRepository;
use App\Consultant\app\Services\Shop\ShopService;

class ShopController extends Controller
{
    public function __construct(
        private ShopService $shopService,
        private ShopSalesRepository $shopSales,
    ) {}

    public function index(): void
    {
        $shops = $this->shopService->getAllShops();

        // Zawartość poglądowa gdy backend nie zwrócił sklepów (DEV_NO_AUTH / brak API).
        // Karty sklepów się renderują; P&L pozostaje realny (ładowany po tapnięciu).
        if (empty($shops)) {
            $shops = $this->demoShops();
        }

        $shops = $this->withSalesIndicators($shops);

        $this->view('shop/list', [
            'shops'      => $shops,
            'active_nav' => 'shops',
        ]);
    }

    /**
     * Enrichit chaque magasin avec : CA du mois, tickets/jour, panier moyen ;
     * trie par CA décroissant et attribue le rang (1 = plus gros CA).
     * Les indicateurs viennent de atelierby_db.transaction (mois en cours).
     */
    private function withSalesIndicators(array $shops): array
    {
        $from = date('Y-m-01 00:00:00');
        $to   = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $daysElapsed = max(1, (int)date('j'));

        $summaries = $this->shopSales->getSummaries($from, $to); // [id_shop => [tickets, ca]]

        foreach ($shops as &$shop) {
            $id      = (int)($shop['id'] ?? 0);
            $tickets = (int)($summaries[$id]['tickets'] ?? 0);
            $ca      = (float)($summaries[$id]['ca'] ?? 0);

            $shop['ca_month']        = $ca;
            $shop['tickets_count']   = $tickets;
            $shop['tickets_per_day'] = $tickets > 0 ? $tickets / $daysElapsed : 0.0;
            $shop['avg_basket']      = $tickets > 0 ? $ca / $tickets : 0.0;
        }
        unset($shop);

        // Tri par CA décroissant, puis rang 1..N.
        usort($shops, fn($a, $b) => ($b['ca_month'] ?? 0) <=> ($a['ca_month'] ?? 0));
        $rank = 0;
        foreach ($shops as &$shop) {
            $shop['rank'] = ++$rank;
        }
        unset($shop);

        return $shops;
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
