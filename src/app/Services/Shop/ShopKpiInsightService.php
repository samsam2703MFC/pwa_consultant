<?php
namespace App\Consultant\app\Services\Shop;

use App\Consultant\app\Services\Param\ParamService;
use DateTimeImmutable;

/**
 * Ce qu'il y a DERRIÈRE une tuile de l'écran Boutiques.
 *
 * Les quatre tuiles donnent un chiffre nu. Un chiffre nu ne dit pas s'il est
 * bon : 247 tickets ne veut rien dire tant qu'on ignore ce que faisait la
 * boutique l'an dernier et ce que font les autres.
 *
 * JOURS CLOS — la règle qui commande tout ici. La journée en cours n'entre
 * dans AUCUN des deux termes de la comparaison. Sans cela, on opposait un
 * « 1 – 2 août 2026 » dont le 2 était à moitié écoulé à un « 1 – 2 août 2025 »
 * complet : le retard était surestimé mécaniquement, et d'autant plus en début
 * de mois où une demi-journée pèse la moitié du total. Les tuiles, elles,
 * restent en temps réel — c'est leur rôle ; la modale dit sur quoi elle porte.
 *
 * LA FENÊTRE DÉPEND DE LA TUILE. Un CUMUL et un TAUX ne supportent pas la même
 * fenêtre courte : le 2 août, un seul jour clos donne un « CA du mois »
 * dérisoire mais un « tickets/jour » parfaitement valable. Le cumul se replie
 * donc sur le mois précédent tant que le mois en cours n'a pas assez de jours
 * clos ; les taux se contentent d'un jour.
 *
 * COÛT — chaque fenêtre est la MÊME pour toutes les boutiques : ShopService
 * regroupe alors le lot en un appel multi-boutiques. Deux fenêtres la plupart
 * du temps (courante + an dernier), quatre pendant les premiers jours du mois
 * où cumuls et taux divergent. Jamais une par boutique.
 */
class ShopKpiInsightService
{
    /**
     * Les quatre grandeurs, dans l'ordre des tuiles.
     *
     * `cumul` : une somme sur la période — elle n'a de sens que sur une période
     * substantielle. `ratio` : la moyenne réseau ne s'obtient pas en moyennant
     * les ratios — le panier moyen du réseau, c'est le CA total sur les tickets
     * totaux, sinon une boutique à 100 tickets pèse autant qu'une à 2 000.
     */
    private const METRICS = [
        'ca_month'            => ['unit' => '€', 'decimals' => 0, 'cumul' => true,  'ratio' => null],
        'tickets_per_day'     => ['unit' => '',  'decimals' => 0, 'cumul' => false, 'ratio' => null],
        'avg_basket'          => ['unit' => '€', 'decimals' => 2, 'cumul' => false, 'ratio' => ['ca', 'tickets']],
        'products_per_client' => ['unit' => '',  'decimals' => 1, 'cumul' => false, 'ratio' => ['products', 'tickets']],
    ];

    public function __construct(
        private ShopService $shopService,
        private ParamService $params
    ) {}

    /** Diagnostic du dernier assemblage : les fenêtres lues, et ce qui manque. */
    private array $diag = [];

    public function diagnostics(): array
    {
        return $this->diag;
    }

    /**
     * @param array $shops boutiques (id, name) — les VALEURS ne viennent plus
     *                     d'elles : les tuiles portent le jour en cours, la
     *                     comparaison porte les jours clos.
     * @return array{shops: array, network: array}
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
            $this->diag = ['shops' => 0, 'windows' => []];
            return ['shops' => [], 'network' => [], 'prev_label' => '', 'now_label' => ''];
        }

        $cadres = $this->cadres($today);

        // Les fenêtres distinctes, chacune commune à toutes les boutiques.
        $fenetres = [];
        foreach ($cadres as $c) {
            foreach (['now', 'prev'] as $k) {
                if ($c[$k] !== null) {
                    $fenetres[$c[$k]['from'] . '|' . $c[$k]['to']] = $c[$k];
                }
            }
        }
        $lot = $this->lire($ids, $fenetres);

        $par = [];
        $totaux = [];   // clé de fenêtre => totaux réseau additifs
        foreach ($fenetres as $cle => $_) {
            $totaux[$cle] = ['ca' => 0.0, 'tickets' => 0, 'products' => 0, 'shops' => 0];
        }

        foreach ($ids as $id) {
            $par[$id] = [];
            foreach (self::METRICS as $m => $def) {
                $c = $cadres[$m];
                $par[$id][$m] = [
                    'now'        => $this->valeur($m, $def, $lot, $id, $c['now']),
                    'prev'       => $this->valeur($m, $def, $lot, $id, $c['prev']),
                    'now_label'  => $c['now']['label'] ?? '',
                    'prev_label' => $c['prev']['label'] ?? '',
                ];
            }
            // Totaux réseau, fenêtre par fenêtre : ils servent aux ratios.
            foreach ($fenetres as $cle => $f) {
                $k = $lot["{$id}|{$f['from']}|{$f['to']}"] ?? null;
                if (!$this->aDesDonnees($k)) {
                    continue;
                }
                $totaux[$cle]['ca']       += (float)($k['ca'] ?? 0);
                $totaux[$cle]['tickets']  += (int)($k['tickets'] ?? 0);
                $totaux[$cle]['products'] += (int)($k['products'] ?? 0);
                $totaux[$cle]['shops']++;
            }
        }

        $reseau = $this->reseau($par, $cadres, $totaux);
        $this->classer($par, $reseau);

        $this->diag = [
            'shops'   => count($par),
            'windows' => array_map(fn($f) => $f['from'] . ' → ' . $f['to'] . ' (' . $f['days'] . 'j)',
                                   array_values($fenetres)),
        ];

        return [
            'shops'      => $par,
            'network'    => $reseau,
            // Les libellés dominants — ceux du CA, la tuile de tête. Chaque
            // métrique porte de toute façon les siens.
            'now_label'  => $cadres['ca_month']['now']['label'] ?? '',
            'prev_label' => $cadres['ca_month']['prev']['label'] ?? '',
        ];
    }

    /**
     * La fenêtre de chaque grandeur, et sa jumelle de l'an dernier.
     *
     * Trois cas, et c'est le nombre de jours CLOS du mois en cours qui décide :
     *   — assez de jours clos : le mois à date, arrêté hier ;
     *   — un à quelques jours : les TAUX s'en contentent, le CUMUL se replie
     *     sur le mois précédent entier ;
     *   — aucun (le 1er du mois) : tout se replie sur le mois précédent.
     *
     * @return array<string, array{now: ?array, prev: ?array}>
     */
    private function cadres(?string $today): array
    {
        $t     = new DateTimeImmutable($today ?: 'today');
        $hier  = $t->modify('-1 day');
        $debut = $t->modify('first day of this month');

        // Mois à date, arrêté au dernier jour CLOS.
        $mtd = null;
        if ($hier >= $debut) {
            $mtd = $this->fenetre($debut->format('Y-m-d'), $hier->format('Y-m-d'), true);
        }
        $joursClos = $mtd === null ? 0 : $mtd['days'];

        // Mois précédent, entier — le repli.
        $pm      = $t->modify('first day of last month');
        $precedent = $this->fenetre($pm->format('Y-m-01'),
                                    $pm->modify('last day of this month')->format('Y-m-d'), true);

        $min = max(1, $this->params->getInt('kpi_min_closed_days', 7));

        $out = [];
        foreach (self::METRICS as $m => $def) {
            // Un cumul sur un ou deux jours affiché comme « CA du mois » est un
            // chiffre juste sous un titre faux : il attend son quota de jours.
            $seuil = $def['cumul'] ? $min : 1;
            $cadre = ($mtd !== null && $joursClos >= $seuil) ? $mtd : $precedent;
            $out[$m] = ['now' => $cadre, 'prev' => $this->anDernier($cadre)];
        }
        return $out;
    }

    /** Une fenêtre : bornes, nombre de jours, libellé. */
    private function fenetre(string $from, string $to, bool $clos): array
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        return [
            'from'  => $from,
            'to'    => $to,
            'days'  => max(1, (int)$a->diff($b)->days + 1),
            'label' => $this->libelle($from, $to) . ($clos ? ' · ' . $this->motClos($from, $to) : ''),
        ];
    }

    /** La même fenêtre, un an plus tôt. */
    private function anDernier(array $f): array
    {
        $a = (new DateTimeImmutable($f['from']))->modify('-1 year');
        $b = (new DateTimeImmutable($f['to']))->modify('-1 year');
        return $this->fenetre($a->format('Y-m-d'), $b->format('Y-m-d'), false);
    }

    /** « jours clos » — ou « mois clos » quand la fenêtre est un mois entier. */
    private function motClos(string $from, string $to): string
    {
        $b = new DateTimeImmutable($to);
        $entier = (new DateTimeImmutable($from))->format('j') === '1'
            && $b->format('Y-m-d') === $b->format('Y-m-t');
        return $entier ? 'mois clos' : 'jours clos';
    }

    /**
     * Les fenêtres, lues en un minimum d'allers-retours.
     *
     * @return array<string, array> map "shop|from|to" => KPIs
     */
    private function lire(array $ids, array $fenetres): array
    {
        $w = [];
        foreach ($fenetres as $f) {
            foreach ($ids as $id) {
                $w[] = ['shop' => $id, 'from' => $f['from'], 'to' => $f['to']];
            }
        }
        return $w === [] ? [] : $this->shopService->getSalesKpisBatch($w);
    }

    /** Une réponse vide n'est pas une valeur : c'est une absence de donnée. */
    private function aDesDonnees(mixed $k): bool
    {
        return is_array($k) && ((int)($k['tickets'] ?? 0) > 0 || (float)($k['ca'] ?? 0) > 0);
    }

    /** La valeur d'une grandeur sur une fenêtre — null si rien n'a été lu. */
    private function valeur(string $m, array $def, array $lot, int $id, ?array $f): ?float
    {
        if ($f === null) {
            return null;
        }
        $k = $lot["{$id}|{$f['from']}|{$f['to']}"] ?? null;
        if (!$this->aDesDonnees($k)) {
            return null;
        }
        switch ($m) {
            case 'ca_month':
                return (float)($k['ca'] ?? 0);
            case 'tickets_per_day':
                // Chaque fenêtre divisée par SES propres jours : c'est ce qui
                // rend deux périodes de longueurs différentes comparables.
                return (float)($k['tickets'] ?? 0) / max(1, $f['days']);
            case 'avg_basket':
                return $this->nombre($k['avg_basket'] ?? null);
            case 'products_per_client':
                $p = $this->nombre($k['products_per_ticket'] ?? null);
                // Sous 1,05, la base ne contient pas le détail des lignes :
                // c'est une absence de donnée, pas un client à un produit.
                return ($p !== null && $p > 1.05) ? $p : null;
        }
        return null;
    }

    /** Les repères du réseau : moyenne, minimum, maximum, et toutes les valeurs. */
    private function reseau(array $par, array $cadres, array $totaux): array
    {
        $out = [];
        foreach (self::METRICS as $cle => $def) {
            $vals = [];
            $prevs = [];
            foreach ($par as $m) {
                if (($m[$cle]['now'] ?? null) !== null)  { $vals[]  = (float)$m[$cle]['now']; }
                if (($m[$cle]['prev'] ?? null) !== null) { $prevs[] = (float)$m[$cle]['prev']; }
            }

            $c = $cadres[$cle];
            $kNow  = $c['now']  ? $c['now']['from']  . '|' . $c['now']['to']  : null;
            $kPrev = $c['prev'] ? $c['prev']['from'] . '|' . $c['prev']['to'] : null;

            if ($def['ratio'] !== null) {
                [$num, $den] = $def['ratio'];
                $tn = $totaux[$kNow]  ?? null;
                $tp = $totaux[$kPrev] ?? null;
                $moy  = ($tn && $tn[$den] > 0) ? $tn[$num] / $tn[$den] : null;
                $moyP = ($tp && $tp[$den] > 0) ? $tp[$num] / $tp[$den] : null;
            } else {
                $moy  = $vals  !== [] ? array_sum($vals) / count($vals) : null;
                // La moyenne de l'an dernier ne porte QUE sur les boutiques qui
                // avaient une valeur : compter les autres pour zéro ferait
                // baisser la référence à chaque ouverture de boutique.
                $moyP = $prevs !== [] ? array_sum($prevs) / count($prevs) : null;
            }

            $out[$cle] = [
                'avg'        => $moy,
                'prev_avg'   => $moyP,
                'min'        => $vals !== [] ? min($vals) : null,
                'max'        => $vals !== [] ? max($vals) : null,
                'values'     => $vals,
                'count'      => count($vals),
                'unit'       => $def['unit'],
                'decimals'   => $def['decimals'],
                'now_label'  => $c['now']['label']  ?? '',
                'prev_label' => $c['prev']['label'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Le rang de chaque boutique, et l'écart à l'an dernier.
     *
     * Un rang sur une grandeur absente n'existe pas : « produits/client » n'est
     * calculable que si la base contient le détail des lignes, et classer une
     * boutique sur une valeur nulle la mettrait dernière à tort.
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

    /**
     * Dire à quoi on se compare, exactement.
     *
     * « août 2025 » sur une fenêtre qui s'arrête au 15 laisserait croire à un
     * mois entier — et l'écart paraîtrait catastrophique. On écrit donc la
     * fenêtre réelle, et le nom du mois seul quand c'est vraiment le mois.
     */
    private function libelle(string $from, string $to): string
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $mois = $noms[((int)$b->format('n')) - 1] . ' ' . $b->format('Y');

        if ($a->format('j') === '1' && $b->format('Y-m-d') === $b->format('Y-m-t')) {
            return $mois;
        }
        if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
            return $b->format('j') . ' ' . $mois;
        }
        return $a->format('j') . ' – ' . $b->format('j') . ' ' . $mois;
    }

    /** Un nombre, ou rien — jamais un zéro tenant lieu d'absence. */
    private function nombre(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }
}
