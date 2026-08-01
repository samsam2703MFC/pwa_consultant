<?php
namespace App\Consultant\app\Services\Checklist;

use App\Consultant\app\Repositories\Checklist\NetworkDayRepository;
use App\Consultant\app\Services\Param\ParamService;
use DateTimeImmutable;

/**
 * Ce qui n'a PAS été fait dans le réseau, depuis le début du mois.
 *
 * L'accueil comptait ce qu'il y avait à valider. Sur les données réelles, ce
 * compteur affiche « 0 » — non pas parce que tout va bien, mais parce que les
 * tâches n'ont pas été faites : une boutique a réalisé 17 tâches sur 923 en un
 * mois. Un écran qui répond « 0 » à cette situation dit le contraire de la
 * vérité. Ce service calcule donc le MANQUE, qui est le vrai travail.
 *
 * Le coût est la contrainte. `/consultant/network/tasks/ranking` couvre tout le
 * réseau pour UN jour : un mois coûte autant d'appels qu'il a de jours, pas
 * autant que boutiques × jours. Les journées passées ne changeant plus, elles
 * sont conservées (NetworkDayRepository) et l'accueil finit par ne payer que la
 * journée en cours.
 *
 * Ce qui n'a pas pu être lu n'est jamais compté comme zéro : il est déclaré
 * manquant, et l'écran dit sur combien de jours il parle.
 */
class NetworkShortfallService
{
    public function __construct(
        private ChecklistService $checklists,
        private NetworkDayRepository $days,
        private ParamService $params
    ) {}

    /** Ce qui a été lu, ce qui a été relu, ce qui manque encore. */
    private array $diag = [];

    public function diagnostics(): array
    {
        return $this->diag;
    }

    /**
     * @return array{from:string, to:string, days_covered:int, days_missing:int,
     *               persisted:bool, planned:int, done:int, missing:int, pct:?float,
     *               shops:array, idle_total:int}
     */
    public function monthToDate(string $date): array
    {
        $aujourdhui = date('Y-m-d');
        $to   = $date > $aujourdhui ? $aujourdhui : $date;

        // Le mois est la période naturelle — sauf les premiers jours, où il ne
        // contient presque rien. Le 1er du mois, « 2 tâches sur 8 » ne dit rien
        // de personne, et le retard réel du mois écoulé disparaîtrait de
        // l'écran le jour où il compte le plus. On glisse alors sur une fenêtre
        // roulante, et on le DIT (`period`).
        $fin     = new DateTimeImmutable($to);
        $minJours = max(1, $this->params->getInt('dashboard_shortfall_min_days', 7));
        $roule   = ((int)$fin->format('j')) < $minJours;
        $periode = $roule ? 'rolling' : 'month';
        $from    = $roule
            ? $fin->modify('-' . (max(2, $this->params->getInt('dashboard_shortfall_rolling_days', 30)) - 1) . ' days')->format('Y-m-d')
            : $fin->modify('first day of this month')->format('Y-m-d');

        $dates = $this->jours($from, $to);
        if ($dates === []) {
            return $this->vide($from, $to) + ['period' => $periode];
        }

        // 1) Ce qu'on sait déjà. La journée en cours n'est jamais conservée :
        //    elle bouge encore.
        $garde     = $this->days->available();
        $conserves = $garde ? $this->days->range($from, $to) : [];
        unset($conserves[$aujourdhui]);

        // 2) Ce qui reste à lire. Sans base pour conserver, l'accueil relirait
        //    tout le mois à CHAQUE ouverture : on se limite alors à une fenêtre
        //    courte — et l'écran dira sur quoi il parle.
        //
        //    Une journée dont l'endpoint n'a rien rendu N'EST PAS conservée :
        //    elle sera redemandée à la prochaine ouverture. C'est voulu — une
        //    panne d'API gravée en « zéro tâche » a déjà figé des rapports à
        //    0/0 ailleurs dans ce code. Le coût de cette relecture est borné
        //    par le plafond ci-dessous, et disparaît avec T11.
        $aLire = array_values(array_diff($dates, array_keys($conserves)));
        $max   = $garde
            ? max(1, $this->params->getInt('dashboard_shortfall_days_max', 31))
            : max(1, $this->params->getInt('dashboard_shortfall_days_nodb', 7));
        if (count($aLire) > $max) {
            // Les jours les plus RÉCENTS d'abord : c'est d'eux qu'on parle le
            // matin, et le mois se complétera aux ouvertures suivantes.
            $aLire = array_slice($aLire, -$max);
        }

        $lus = $aLire !== [] ? $this->checklists->getNetworkRankingForDates($aLire) : [];

        // 3) Normalisation + conservation des journées CLOSES.
        $parJour = $conserves;
        foreach ($lus as $d => $payload) {
            $lignes = $this->normaliser($payload);
            if ($lignes === []) {
                continue;                       // endpoint muet : jour manquant, pas jour vide
            }
            $parJour[$d] = $lignes;
            if ($garde && $d < $aujourdhui) {
                $this->days->putDay($d, $lignes);
            }
        }

        $this->diag = [
            'from' => $from, 'to' => $to,
            'wanted' => count($dates),
            'from_db' => count($conserves),
            'fetched' => count($lus),
            'covered' => count($parJour),
            'persisted' => $garde,
            'db_error' => $this->days->lastError,
        ];

        return $this->agreger($from, $to, $dates, $parJour, $garde) + ['period' => $periode];
    }

    /** Les dates d'une plage, bornes incluses. */
    private function jours(string $from, string $to): array
    {
        $out = [];
        $d = new DateTimeImmutable($from);
        $fin = new DateTimeImmutable($to);
        while ($d <= $fin) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $out;
    }

    /**
     * Une réponse de classement → une ligne par boutique.
     *
     * `tasks_total` est la référence : le pourcentage de l'API se calcule sur
     * un dénominateur qu'on veut pouvoir additionner. Une boutique sans
     * `tasks_total` est ignorée — additionner un pourcentage seul n'a pas de
     * sens et donnerait un total inventé.
     */
    private function normaliser(array $payload): array
    {
        $out = [];
        foreach (($payload['shops'] ?? []) as $s) {
            $id = (int)($s['shop_id'] ?? $s['id'] ?? 0);
            if ($id <= 0 || !isset($s['tasks_total'])) {
                continue;
            }
            $out[$id] = [
                'shop_id'          => $id,
                'shop_name'        => trim((string)($s['shop_name'] ?? $s['name'] ?? '')),
                'shop_city'        => trim((string)($s['shop_city'] ?? $s['city'] ?? '')),
                'planned'          => max(0, (int)$s['tasks_total']),
                'done'             => max(0, (int)($s['tasks_done'] ?? 0)),
                'mandatory_missed' => max(0, (int)($s['mandatory_missed'] ?? 0)),
                'tasks_failed'     => max(0, (int)($s['tasks_failed'] ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array<int, array>> $parJour
     */
    private function agreger(string $from, string $to, array $dates, array $parJour, bool $garde): array
    {
        $boutiques = [];
        ksort($parJour);

        foreach ($parJour as $jour => $lignes) {
            foreach ($lignes as $id => $l) {
                if (!isset($boutiques[$id])) {
                    $boutiques[$id] = [
                        'shop_id'   => $id,
                        'name'      => $l['shop_name'],
                        'city'      => $l['shop_city'],
                        'planned'   => 0,
                        'done'      => 0,
                        'missed'    => 0,
                        'idle_days' => 0,
                        'last_full' => null,
                        'last_any'  => null,
                    ];
                }
                $b =& $boutiques[$id];
                // Le nom le plus récemment connu : une boutique renommée ne
                // doit pas rester sous son ancien nom tout le mois.
                if ($l['shop_name'] !== '') { $b['name'] = $l['shop_name']; }
                if ($l['shop_city'] !== '') { $b['city'] = $l['shop_city']; }

                $b['planned'] += $l['planned'];
                $b['done']    += $l['done'];
                $b['missed']  += $l['mandatory_missed'];

                if ($l['planned'] > 0) {
                    if ($l['done'] === 0) {
                        $b['idle_days']++;
                    } else {
                        $b['last_any'] = $jour;
                    }
                    if ($l['done'] >= $l['planned']) {
                        $b['last_full'] = $jour;
                    }
                }
                unset($b);
            }
        }

        $planne = 0;
        $faites = 0;
        $idle   = 0;
        foreach ($boutiques as &$b) {
            $b['pct'] = $b['planned'] > 0 ? round($b['done'] / $b['planned'] * 100, 1) : null;
            $planne  += $b['planned'];
            $faites  += $b['done'];
            $idle    += $b['idle_days'];
        }
        unset($b);

        // Les plus en retard d'abord. Une boutique sans rien de planifié n'a
        // rien à se reprocher : elle passe en fin de liste, pas en tête.
        $liste = array_values($boutiques);
        usort($liste, function ($a, $z) {
            if (($a['planned'] > 0) !== ($z['planned'] > 0)) {
                return $a['planned'] > 0 ? -1 : 1;
            }
            return ($a['pct'] ?? 101) <=> ($z['pct'] ?? 101);
        });

        return [
            'from'         => $from,
            'to'           => $to,
            'days_covered' => count($parJour),
            'days_missing' => max(0, count($dates) - count($parJour)),
            'persisted'    => $garde,
            'planned'      => $planne,
            'done'         => $faites,
            'missing'      => max(0, $planne - $faites),
            'pct'          => $planne > 0 ? round($faites / $planne * 100, 1) : null,
            'idle_total'   => $idle,
            'shops'        => $liste,
        ];
    }

    private function vide(string $from, string $to): array
    {
        return [
            'from' => $from, 'to' => $to, 'days_covered' => 0, 'days_missing' => 0,
            'persisted' => false, 'planned' => 0, 'done' => 0, 'missing' => 0,
            'pct' => null, 'idle_total' => 0, 'shops' => [],
        ];
    }
}
