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
        $compareWindows = $this->compareWindows();

        foreach ($shops as &$shop) {
            $id = (int)($shop['id'] ?? 0);

            $ca = 0.0;
            $tickets = 0;
            $products = 0;
            $days = 1;
            $scale = 1.0;

            $pnl      = $id > 0 ? $this->shopService->getPnl($id, 'month') : [];
            $turnover = isset($pnl['turnover']['value']) ? (float)$pnl['turnover']['value'] : null;
            $fromDate = isset($pnl['date_from']) ? substr((string)$pnl['date_from'], 0, 10) : '';
            $toDate   = isset($pnl['date_to'])   ? substr((string)$pnl['date_to'], 0, 10)   : '';

            if ($turnover !== null && $this->isDate($fromDate) && $this->isDate($toDate)) {
                // Aligné sur le P&L : même CA, même fenêtre.
                $ca  = $turnover;
                $sum = $this->shopSales->getShopSummary($id, $fromDate, $toDate);
                $tickets  = $sum['tickets'];
                $products = $sum['products'];

                // La base locale peut être PARTIELLE (CA_DB < CA_API) tout en
                // étant représentative (même panier moyen). On redresse donc
                // les comptages au prorata du CA de l'API : le panier affiché
                // reste celui observé en base, et tickets/jour retrouve le
                // périmètre complet. Base complète → ratio 1 (aucun effet).
                $caDb = (float)$sum['ca'];
                if ($caDb > 0 && $turnover > 0) {
                    $scale    = $turnover / $caDb;
                    $tickets  = (int)round($tickets * $scale);
                    $products = (int)round($products * $scale);
                }

                // Tickets et CA couvrent toute la fenêtre du P&L → la moyenne
                // par jour se divise par le NOMBRE DE JOURS DE LA FENÊTRE
                // (date_from → date_to inclus), pas par les jours écoulés.
                $toExclObj = (new \DateTimeImmutable($toDate))->modify('+1 day');
                $days = max(1, (int)(new \DateTimeImmutable($fromDate))->diff($toExclObj)->days);
            } else {
                // Repli : mois calendaire courant, lu en base.
                $sum      = $this->shopSales->getShopSummary($id, date('Y-m-01'), date('Y-m-t'));
                $ca       = (float)$sum['ca'];
                $tickets  = (int)$sum['tickets'];
                $products = (int)$sum['products'];
                $days     = max(1, (int)date('t')); // nombre de jours du mois
            }

            // Comparatif N vs N-1. Colonne N : CA temps réel de l'API quand il
            // existe (mois à date = turnover déjà chargé ; semaine = P&L week),
            // car la base locale peut être alimentée avec du retard — sinon
            // les derniers jours (donc la ligne « Semaine ») resteraient à 0.
            // N-1 et année à date : base locale au prorata du CA API.
            $pnlWeek  = $id > 0 ? $this->shopService->getPnl($id, 'week') : [];
            $weekApi  = isset($pnlWeek['turnover']['value']) ? (float)$pnlWeek['turnover']['value'] : null;
            $shop['compare'] = $this->buildComparison($id, $compareWindows, $scale, [
                'mtd'  => $turnover,
                'week' => $weekApi,
            ]);

            $shop['ca_month']        = $ca;
            $shop['tickets_count']   = $tickets;
            $shop['tickets_per_day'] = $tickets > 0 ? $tickets / $days : 0.0;
            $shop['avg_basket']      = $tickets > 0 ? $ca / $tickets : 0.0;
            // Produits/client : calculable seulement si la base contient le
            // détail des lignes (produits > tickets). Sinon null → « — ».
            $ppc = $tickets > 0 ? $products / $tickets : 0.0;
            $shop['products_per_client'] = $ppc > 1.05 ? $ppc : null;
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
     * Bornes des trois paires de fenêtres N vs N-1, à période équivalente :
     *  - année à date  : 1er janvier → aujourd'hui inclus, vs même plage N-1 ;
     *  - mois à date   : 1er du mois → aujourd'hui inclus, vs même plage N-1 ;
     *  - semaine       : lundi → aujourd'hui inclus, vs -364 jours (52 semaines
     *    pile) pour comparer les mêmes jours de semaine.
     * Bornes hautes exclusives (lendemain 00:00).
     */
    private function compareWindows(): array
    {
        $today    = new \DateTimeImmutable('today');
        $toExcl   = $today->modify('+1 day')->format('Y-m-d 00:00:00');
        $lastY    = $today->modify('-1 year');
        $lastYEnd = $lastY->modify('+1 day')->format('Y-m-d 00:00:00');
        $monday   = $today->modify('monday this week');

        return [
            'ytd_n'  => [$today->format('Y-01-01 00:00:00'),  $toExcl],
            'ytd_p'  => [$lastY->format('Y-01-01 00:00:00'),  $lastYEnd],
            'mtd_n'  => [$today->format('Y-m-01 00:00:00'),   $toExcl],
            'mtd_p'  => [$lastY->format('Y-m-01 00:00:00'),   $lastYEnd],
            'week_n' => [$monday->format('Y-m-d 00:00:00'),   $toExcl],
            'week_p' => [
                $monday->modify('-364 days')->format('Y-m-d 00:00:00'),
                $today->modify('-364 days')->modify('+1 day')->format('Y-m-d 00:00:00'),
            ],
        ];
    }

    /**
     * Lignes du tableau comparatif : N-1, N, écart %, statut.
     *  ok   : ≥ année passée (vert) · warn : 0 % > écart ≥ −5 % (orange)
     *  late : < −5 % (rouge)        · na   : pas de référence N-1 (gris).
     */
    private function buildComparison(int $shopId, array $windows, float $scale, array $apiN = []): array
    {
        $sums = $shopId > 0 ? $this->shopSales->getWindowSums($shopId, $windows) : [];

        $rows = [];
        foreach (['ytd', 'mtd', 'week'] as $key) {
            // Colonne N : valeur API temps réel si disponible, sinon base×prorata.
            $n = isset($apiN[$key]) && $apiN[$key] !== null
                ? (float)$apiN[$key]
                : ((float)($sums[$key . '_n'] ?? 0)) * $scale;
            $n1 = ((float)($sums[$key . '_p'] ?? 0)) * $scale;

            $pct = $n1 > 0 ? (($n - $n1) / $n1) * 100 : null;
            $status = 'na';
            if ($pct !== null) {
                $status = $pct >= 0 ? 'ok' : ($pct >= -5 ? 'warn' : 'late');
            }

            $rows[] = ['key' => $key, 'n1' => $n1, 'n' => $n, 'pct' => $pct, 'status' => $status];
        }

        return $rows;
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
