<?php
namespace App\Consultant\app\Http\Controllers\Shop;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Shop\ShopRepository;
use App\Consultant\app\Repositories\Shop\ShopSalesRepository;
use App\Consultant\app\Services\Shop\ShopService;

class ShopController extends Controller
{
    public function __construct(
        private ShopService $shopService,
        private ShopSalesRepository $shopSales,
        private ShopRepository $shopRepository,
    ) {}

    /**
     * KPI de vente d'un magasin sur une fenêtre : API BACKEND d'abord
     * (source de vérité), repli sur le calcul local identique tant que
     * l'endpoint backend n'est pas déployé.
     *
     * @return array{tickets:int, ca:float, products:int, avg_basket:?float, products_per_ticket:?float}
     */
    private function salesKpis(int $shopId, string $fromDate, string $toDate): array
    {
        return $this->shopRepository->getSalesKpisFromApi($shopId, $fromDate, $toDate)
            ?? $this->shopSales->getSalesKpis($shopId, $fromDate, $toDate);
    }

    /**
     * Tickets et CA lus dans la base locale (table transaction) sur une
     * fenêtre arbitraire — MÊME calcul (getSalesKpis) que les indicateurs
     * du module Boutiques, validés. Le tableau « état au moment T » de
     * l'accueil l'appelle avec la fenêtre du P&L mensuel : le panier
     * (CA base / tickets base) est alors identique à celui de la tuile
     * Boutiques, et clients du jour = CA API du jour / panier.
     */
    public function daySales(int $shopId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $re   = '/^\d{4}-\d{2}-\d{2}$/';
        $from = (string)($_GET['from'] ?? $_GET['date'] ?? date('Y-m-d'));
        $to   = (string)($_GET['to'] ?? $from);
        if (!preg_match($re, $from)) {
            $from = date('Y-m-d');
        }
        if (!preg_match($re, $to) || $to < $from) {
            $to = $from;
        }

        // Période standard optionnelle (?period=day|week|month) → fenêtre
        // résolue côté serveur ; sinon la fenêtre from/to explicite.
        $period = (string)($_GET['period'] ?? '');
        if (in_array($period, ['day', 'week', 'month'], true)) {
            [$from, $to] = ShopSalesRepository::periodWindow($period);
        }

        // Mode diagnostic (session connectée) : variantes de comptage +
        // échantillon brut, pour confronter aux chiffres POS réels.
        if (($_GET['debug'] ?? '') === '1') {
            return $this->json([
                'ok'    => true,
                'debug' => $this->shopSales->getSalesDebug($shopId, $from, $to),
                'api'   => $this->shopRepository->getSalesKpisFromApi($shopId, $from, $to),
            ]);
        }

        $kpi = $this->salesKpis($shopId, $from, $to);
        return $this->json([
            'ok'   => true,
            'data' => [
                'tickets'             => $kpi['tickets'],
                'ca'                  => $kpi['ca'],
                'products'            => $kpi['products'],
                'avg_basket'          => $kpi['avg_basket'],
                'products_per_ticket' => $kpi['products_per_ticket'],
            ],
        ]);
    }

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
            $basket = null;
            $ppt = null;
            $days = 1;

            $pnl      = $id > 0 ? $this->shopService->getPnl($id, 'month') : [];
            $turnover = isset($pnl['turnover']['value']) ? (float)$pnl['turnover']['value'] : null;
            $fromDate = isset($pnl['date_from']) ? substr((string)$pnl['date_from'], 0, 10) : '';
            $toDate   = isset($pnl['date_to'])   ? substr((string)$pnl['date_to'], 0, 10)   : '';

            if ($turnover !== null && $this->isDate($fromDate) && $this->isDate($toDate)) {
                // CA aligné sur le P&L (API) ; les 3 KPI tickets/panier/
                // produits viennent d'UNE requête (getSalesKpis) — même
                // périmètre. Le nombre de tickets est le comptage BRUT
                // COUNT(DISTINCT ticket_key) de la base, SANS redressement :
                // même source et même formule que le tableau de l'accueil,
                // pour des chiffres identiques partout.
                $ca  = $turnover;
                $kpi = $this->salesKpis($id, $fromDate, $toDate);
                $tickets = $kpi['tickets'];
                $basket  = $kpi['avg_basket'];
                $ppt     = $kpi['products_per_ticket'];

                // Moyenne par jour = tickets / jours RÉELLEMENT COUVERTS par
                // la base sur la fenêtre (days_with_data) : l'alimentation
                // peut être en retard de plusieurs jours (constaté : 7 jours)
                // — diviser par les jours calendaires sous-évaluerait
                // tickets/jour. Repli : jours écoulés de la fenêtre.
                $days = (int)($kpi['days_with_data'] ?? 0);
                if ($days < 1) {
                    $endObj = new \DateTimeImmutable($toDate);
                    $today  = new \DateTimeImmutable('today');
                    if ($today < $endObj) {
                        $endObj = $today;
                    }
                    $days = max(1, (int)(new \DateTimeImmutable($fromDate))->diff($endObj->modify('+1 day'))->days);
                }
            } else {
                // Repli : mois calendaire courant.
                $kpi     = $this->salesKpis($id, date('Y-m-01'), date('Y-m-t'));
                $ca      = $kpi['ca'];
                $tickets = $kpi['tickets'];
                $basket  = $kpi['avg_basket'];
                $ppt     = $kpi['products_per_ticket'];
                $days    = max(1, (int)($kpi['days_with_data'] ?? 0) ?: (int)date('j'));
            }

            // Comparatif N vs N-1. Colonne N : CA temps réel de l'API quand il
            // existe (mois à date = turnover déjà chargé ; semaine = P&L week),
            // car la base locale est alimentée avec du retard. L'HISTORIQUE
            // (N-1, portion passée de l'année) est lu en base SANS redressement :
            // la base est complète pour les périodes anciennes — seul le récent
            // manque. Le prorata ne vaut que pour le mois en cours.
            $pnlWeek  = $id > 0 ? $this->shopService->getPnl($id, 'week') : [];
            $weekApi  = isset($pnlWeek['turnover']['value']) ? (float)$pnlWeek['turnover']['value'] : null;
            $shop['compare'] = $this->buildComparison($id, $compareWindows, [
                'mtd'  => $turnover,
                'week' => $weekApi,
            ]);

            $shop['ca_month']        = $ca;
            $shop['tickets_count']   = $tickets;
            $shop['tickets_per_day'] = $tickets > 0 ? $tickets / $days : 0.0;
            // Panier et produits/ticket : ratios OBSERVÉS en base (getSalesKpis),
            // insensibles au redressement de volume (le prorata s'annule).
            $shop['avg_basket']      = $basket ?? 0.0;
            // Produits/client : calculable seulement si la base contient le
            // détail des lignes (produits > tickets). Sinon null → « — ».
            $shop['products_per_client'] = ($ppt !== null && $ppt > 1.05) ? $ppt : null;
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
     *
     * Valeurs N-1 : base locale telles quelles (historique complet — AUCUN
     * redressement : le prorata API/DB ne vaut que pour le mois en cours).
     * Colonne N : API temps réel pour mois à date et semaine ; année à date =
     * base (portion passée, complète) − mois courant en base (en retard)
     * + mois à date API.
     */
    private function buildComparison(int $shopId, array $windows, array $apiN = []): array
    {
        $sums = $shopId > 0 ? $this->shopSales->getWindowSums($shopId, $windows) : [];

        // Année à date N : remplace la portion mois-courant (base en retard)
        // par le chiffre temps réel de l'API.
        if (isset($apiN['mtd']) && $apiN['mtd'] !== null) {
            $apiN['ytd'] = ((float)($sums['ytd_n'] ?? 0)) - ((float)($sums['mtd_n'] ?? 0)) + (float)$apiN['mtd'];
        }

        $rows = [];
        foreach (['ytd', 'mtd', 'week'] as $key) {
            $n = isset($apiN[$key]) && $apiN[$key] !== null
                ? (float)$apiN[$key]
                : (float)($sums[$key . '_n'] ?? 0);
            $n1 = (float)($sums[$key . '_p'] ?? 0);

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
