<?php
namespace App\Consultant\app\Services\Shop;

use DateTimeImmutable;

/**
 * Ce qu'il y a DERRIÈRE une tuile de l'écran Boutiques.
 *
 * Les quatre tuiles — CA du mois, tickets/jour, panier moyen, produits/client
 * — donnent un chiffre nu. Un chiffre nu ne dit pas s'il est bon : 247 tickets
 * ne veut rien dire tant qu'on ignore ce que faisait la boutique l'an dernier
 * et ce que font les autres. Ce service ajoute les deux références, et rien de
 * plus.
 *
 * COÛT — c'est ce qui a décidé de la conception. La comparaison porte sur la
 * MÊME fenêtre l'an dernier, la même pour toutes les boutiques : ShopService
 * regroupe alors le lot en UN appel multi-boutiques
 * (/consultant/shops/sales-kpis) au lieu d'un par boutique. L'écran paie donc
 * une requête, quel que soit le nombre de boutiques.
 *
 * Ce qui n'a pas de référence n'en invente pas : une boutique ouverte cette
 * année n'a pas d'an dernier, et l'écran doit écrire « — », pas « +100 % ».
 */
class ShopKpiInsightService
{
    /**
     * Les quatre grandeurs, dans l'ordre des tuiles.
     * `weighted` : la moyenne réseau d'un RATIO ne s'obtient pas en moyennant
     * les ratios — le panier moyen du réseau, c'est le CA total sur les
     * tickets totaux. Sans cela, une petite boutique pèse autant qu'une grande.
     */
    private const METRICS = [
        'ca_month'            => ['unit' => '€',  'decimals' => 0, 'ratio' => null],
        'tickets_per_day'     => ['unit' => '',   'decimals' => 0, 'ratio' => null],
        'avg_basket'          => ['unit' => '€',  'decimals' => 2, 'ratio' => ['ca', 'tickets']],
        'products_per_client' => ['unit' => '',   'decimals' => 1, 'ratio' => ['products', 'tickets']],
    ];

    public function __construct(private ShopService $shopService) {}

    /** Diagnostic du dernier assemblage : ce qui a été demandé, ce qui manque. */
    private array $diag = [];

    public function diagnostics(): array
    {
        return $this->diag;
    }

    /**
     * @param array $shops boutiques déjà garnies par ShopController (ca_month,
     *                     tickets_per_day, avg_basket, products_per_client,
     *                     tickets_count, id, name)
     * @return array{shops: array, network: array}
     *         `shops`   : map id de boutique => métrique => {now, prev, delta_pct, rank}
     *         `network` : métrique => {avg, prev_avg, min, max, values, count}
     */
    public function build(array $shops, ?string $today = null): array
    {
        $ids = [];
        foreach ($shops as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            $this->diag = ['shops' => 0, 'prev' => 0];
            return ['shops' => [], 'network' => []];
        }

        [$from, $to, $jours, $libelle] = $this->fenetrePrecedente($today);

        // Une seule fenêtre pour tout le monde → un seul appel réseau.
        $lot = $this->shopService->getSalesKpisBatch(array_map(
            fn($id) => ['shop' => $id, 'from' => $from, 'to' => $to],
            $ids
        ));

        $par = [];      // id => métrique => ['now'=>?float,'prev'=>?float]
        $vus = 0;
        // Totaux réseau des GRANDEURS ADDITIVES, pour les ratios pondérés.
        $tot = ['ca' => 0.0, 'tickets' => 0, 'products' => 0];
        $totP = ['ca' => 0.0, 'tickets' => 0, 'products' => 0];

        foreach ($shops as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $k = $lot["{$id}|{$from}|{$to}"] ?? null;
            $aPrec = is_array($k) && ((int)($k['tickets'] ?? 0) > 0 || (float)($k['ca'] ?? 0) > 0);
            if ($aPrec) {
                $vus++;
            }

            $ppcPrec = null;
            if ($aPrec && ($k['products_per_ticket'] ?? null) !== null
                && (float)$k['products_per_ticket'] > 1.05) {
                $ppcPrec = (float)$k['products_per_ticket'];
            }

            $par[$id] = [
                'ca_month' => [
                    'now'  => $this->nombre($s['ca_month'] ?? null),
                    'prev' => $aPrec ? (float)($k['ca'] ?? 0) : null,
                ],
                'tickets_per_day' => [
                    'now' => $this->nombre($s['tickets_per_day'] ?? null),
                    // Chaque fenêtre est divisée par SES propres jours : la
                    // fenêtre courante par les jours réellement alimentés
                    // (l'alimentation a du retard), la précédente par ses jours
                    // écoulés — elle, est complète. Un diviseur commun
                    // gonflerait l'an dernier d'autant que la base est en retard.
                    'prev' => $aPrec ? (float)($k['tickets'] ?? 0) / max(1, $jours) : null,
                ],
                'avg_basket' => [
                    'now'  => $this->nombre($s['avg_basket'] ?? null),
                    'prev' => $aPrec ? $this->nombre($k['avg_basket'] ?? null) : null,
                ],
                'products_per_client' => [
                    'now'  => $this->nombre($s['products_per_client'] ?? null),
                    'prev' => $ppcPrec,
                ],
            ];

            $tot['ca']       += (float)($s['ca_month'] ?? 0);
            $tot['tickets']  += (int)($s['tickets_count'] ?? 0);
            $ppc = $this->nombre($s['products_per_client'] ?? null);
            $tot['products'] += $ppc !== null ? (int)round($ppc * (int)($s['tickets_count'] ?? 0)) : 0;

            if ($aPrec) {
                $totP['ca']       += (float)($k['ca'] ?? 0);
                $totP['tickets']  += (int)($k['tickets'] ?? 0);
                $totP['products'] += (int)($k['products'] ?? 0);
            }
        }

        $reseau = $this->reseau($par, $tot, $totP);
        $this->classer($par, $reseau);

        $this->diag = [
            'shops' => count($par), 'prev' => $vus,
            'window' => $from . ' → ' . $to, 'days' => $jours,
        ];

        return [
            'shops'   => $par,
            'network' => $reseau,
            'prev_label' => $libelle,
            'now_label'  => $this->libelle((new DateTimeImmutable($today ?: 'today'))->format('Y-m-01'),
                                           (new DateTimeImmutable($today ?: 'today'))->format('Y-m-d')),
            'window'  => ['from' => $from, 'to' => $to, 'days' => $jours],
        ];
    }

    /**
     * La fenêtre de référence : le MÊME nombre de jours du même mois, l'an
     * dernier. Comparer un mois à date à un mois entier ferait passer toute
     * boutique pour en recul le 3 du mois.
     *
     * @return array{0:string,1:string,2:int,3:string}
     */
    private function fenetrePrecedente(?string $today): array
    {
        $t  = new DateTimeImmutable($today ?: 'today');
        $an = $t->modify('-1 year');
        $from = $an->format('Y-m-01');
        $to   = $an->format('Y-m-d');
        $jours = max(1, (int)$an->format('j'));

        return [$from, $to, $jours, $this->libelle($from, $to)];
    }

    /**
     * Dire à quoi on se compare, exactement.
     *
     * « août 2025 » sur une fenêtre qui s'arrête au 15 laisserait croire à un
     * mois entier — et l'écart paraîtrait catastrophique. On écrit donc la
     * fenêtre réelle, et « août 2025 » seulement quand c'est vraiment le mois.
     */
    private function libelle(string $from, string $to): string
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $mois = $noms[((int)$b->format('n')) - 1] . ' ' . $b->format('Y');

        if ($a->format('j') === '1' && $b->format('Y-m-d') === $b->format('Y-m-t')) {
            return $mois;                       // le mois entier
        }
        if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
            return $b->format('j') . ' ' . $mois;
        }
        return $a->format('j') . ' – ' . $b->format('j') . ' ' . $mois;
    }

    /**
     * Les repères du réseau : moyenne, minimum, maximum, et la liste des
     * valeurs pour placer les autres boutiques sur la règle.
     */
    private function reseau(array $par, array $tot, array $totP): array
    {
        $out = [];
        foreach (self::METRICS as $cle => $def) {
            $vals = [];
            $prevs = [];
            foreach ($par as $m) {
                if (($m[$cle]['now'] ?? null) !== null)  { $vals[]  = (float)$m[$cle]['now']; }
                if (($m[$cle]['prev'] ?? null) !== null) { $prevs[] = (float)$m[$cle]['prev']; }
            }

            // Un ratio se pondère par les totaux ; une grandeur additive se
            // moyenne par boutique.
            if ($def['ratio'] !== null) {
                [$num, $den] = $def['ratio'];
                $moy  = $tot[$den]  > 0 ? $tot[$num]  / $tot[$den]  : null;
                $moyP = $totP[$den] > 0 ? $totP[$num] / $totP[$den] : null;
            } else {
                $moy  = $vals  !== [] ? array_sum($vals) / count($vals) : null;
                // La moyenne de l'an dernier ne porte QUE sur les boutiques qui
                // avaient une valeur : compter les autres pour zéro ferait
                // baisser la référence à chaque ouverture de boutique.
                $moyP = $prevs !== [] ? array_sum($prevs) / count($prevs) : null;
            }

            $out[$cle] = [
                'avg'      => $moy,
                'prev_avg' => $moyP,
                'min'      => $vals !== [] ? min($vals) : null,
                'max'      => $vals !== [] ? max($vals) : null,
                'values'   => $vals,
                'count'    => count($vals),
                'unit'     => $def['unit'],
                'decimals' => $def['decimals'],
            ];
        }
        return $out;
    }

    /**
     * Le rang de chaque boutique, et l'écart à l'an dernier.
     *
     * Un rang sur une grandeur absente n'existe pas : « produits/client »
     * n'est calculable que si la base contient le détail des lignes, et
     * classer une boutique sur une valeur nulle la mettrait dernière à tort.
     */
    private function classer(array &$par, array $reseau): void
    {
        foreach (self::METRICS as $cle => $_) {
            $vals = $reseau[$cle]['values'] ?? [];
            rsort($vals);
            foreach ($par as $id => &$m) {
                $now  = $m[$cle]['now'] ?? null;
                $prev = $m[$cle]['prev'] ?? null;

                $m[$cle]['delta_pct'] = ($prev !== null && $prev > 0 && $now !== null)
                    ? round((($now - $prev) / $prev) * 100, 1)
                    : null;

                $rang = null;
                if ($now !== null) {
                    foreach ($vals as $i => $v) {
                        if (abs($v - (float)$now) < 1e-9) { $rang = $i + 1; break; }
                    }
                }
                $m[$cle]['rank']  = $rang;
                $m[$cle]['total'] = count($vals);
            }
            unset($m);
        }
    }

    /** Un nombre, ou rien — jamais un zéro tenant lieu d'absence. */
    private function nombre(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }
}
