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
     *
     * Le CA du mois provient de la MÊME source que le split « TurnOver » du P&L
     * (endpoint /consultant/shops/{id}/pnl?period=month, champ turnover.value),
     * pour que la ligne de synthèse et le P&L déplié affichent le même chiffre.
     * Le nombre de tickets est lu dans atelierby_db.transaction sur la fenêtre
     * exacte rapportée par le P&L (date_from/date_to) → panier moyen cohérent.
     * Repli sur le mois calendaire courant (DB) si le P&L est indisponible.
     */
    private function withSalesIndicators(array $shops): array
    {
        foreach ($shops as &$shop) {
            $id = (int)($shop['id'] ?? 0);

            $ca = 0.0;
            $tickets = 0;
            $days = 1;

            $pnl      = $id > 0 ? $this->shopService->getPnl($id, 'month') : [];
            $turnover = isset($pnl['turnover']['value']) ? (float)$pnl['turnover']['value'] : null;
            $fromDate = isset($pnl['date_from']) ? substr((string)$pnl['date_from'], 0, 10) : '';
            $toDate   = isset($pnl['date_to'])   ? substr((string)$pnl['date_to'], 0, 10)   : '';

            if ($turnover !== null && $this->isDate($fromDate) && $this->isDate($toDate)) {
                // Aligné sur le P&L : même CA, même fenêtre.
                $ca = $turnover;

                $fromDt = $fromDate . ' 00:00:00';
                // date_to du P&L = dernier jour inclus → borne exclusive = +1 jour.
                $toExclObj = (new \DateTimeImmutable($toDate))->modify('+1 day');
                $toExcl    = $toExclObj->format('Y-m-d 00:00:00');

                $tickets = $this->shopSales->getShopSummary($id, $fromDt, $toExcl)['tickets'];

                // Tickets et CA couvrent toute la fenêtre du P&L → la moyenne
                // par jour se divise par le NOMBRE DE JOURS DE LA FENÊTRE
                // (date_from → date_to inclus), pas par les jours écoulés.
                $days = max(1, (int)(new \DateTimeImmutable($fromDate))->diff($toExclObj)->days);
            } else {
                // Repli : mois calendaire courant, lu en base.
                $fromDt = date('Y-m-01 00:00:00');
                $toDt   = date('Y-m-01 00:00:00', strtotime('first day of next month'));
                $sum    = $this->shopSales->getShopSummary($id, $fromDt, $toDt);
                $ca      = (float)$sum['ca'];
                $tickets = (int)$sum['tickets'];
                $days    = max(1, (int)date('t')); // nombre de jours du mois
            }

            $shop['ca_month']        = $ca;
            $shop['tickets_count']   = $tickets;
            $shop['tickets_per_day'] = $tickets > 0 ? $tickets / $days : 0.0;
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

    /** Valide une date au format Y-m-d (et qu'elle existe réellement). */
    private function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
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
