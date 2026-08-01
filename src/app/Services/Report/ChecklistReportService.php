<?php
namespace App\Consultant\app\Services\Report;

use App\Consultant\app\Repositories\Checklist\ChecklistSnapshotRepository;
use App\Consultant\app\Repositories\Param\ParamRepository;
use App\Consultant\app\Services\Checklist\ChecklistService;
use App\Consultant\app\Services\Checklist\ReviewRating;
use App\Consultant\app\Services\Shop\ShopService;
use DateTimeImmutable;

/**
 * Rapport « Checklist Tâches », hebdomadaire et mensuel.
 *
 * Service SÉPARÉ de ReportService (1 700 lignes, rapport de gestion) : les deux
 * ne partagent ni source ni période, et mêler les deux rendrait chaque
 * évolution risquée pour l'autre.
 *
 * COÛT RÉSEAU — le point dur. L'API ne répond que jour par jour : il n'existe
 * aucun endpoint par plage pour les checklists. En séquence, un mois coûterait
 * ~50 attentes à 10 s de délai chacune. Deux mesures :
 *   1. les jours CLOS sont figés en base (mac_checklist_day_snapshot) et ne
 *      sont plus jamais redemandés ;
 *   2. les jours manquants partent en DEUX appels groupés, quel que soit leur
 *      nombre — les checklists de tous les jours, puis leurs avancements.
 * Un rapport mensuel déjà figé ne coûte donc aucun aller-retour.
 */
class ChecklistReportService
{
    /** Jours ouvrés du commerce : lundi → samedi. Le dimanche est fermé. */
    private const RETAIL_DAYS = 6;

    /**
     * Combien de jours ont été relus à l'API lors de la dernière génération.
     * Publié avec le rapport : un rapport à zéro doit pouvoir dire s'il n'a
     * rien trouvé ou s'il n'a rien demandé.
     */
    private int $fetched = 0;

    /**
     * L'avancement des checklists a-t-il répondu, et combien d'avis en
     * a-t-on tirés ? Note moyenne, taux de conformité et conformité par
     * checklist en dépendent TOUS : sans ces deux compteurs, un rapport sans
     * conformité ne sait pas dire si personne n'a évalué ou si l'endpoint
     * s'est tu.
     */
    private int $progressPayloads = 0;
    private int $reviewsSeen = 0;

    /**
     * Combien de checklists la liste du jour a rendues. Sans ce compteur, deux
     * pannes distinctes se ressemblent : « /checklists ne rend aucune
     * checklist » et « il en rend, mais leur avancement est vide ». Elles
     * n'appellent pas la même correction côté backend.
     */
    private int $checklistsFound = 0;

    /**
     * Recalcul forcé : les jours figés sont ignorés et tout est redemandé.
     *
     * Indispensable pour deux raisons. Un jour peut avoir été figé alors qu'un
     * endpoint était muet — sa conformité restera vide à jamais sans ce
     * levier. Et un avis consultant peut être posé DAYS après la journée
     * concernée : le jour figé ne le verrait pas.
     */
    private bool $fresh = false;

    public function forceRefresh(bool $on = true): void
    {
        $this->fresh = $on;
    }

    public function __construct(
        private ChecklistService $checklists,
        private ChecklistSnapshotRepository $snapshots,
        private ShopService $shopService,
        private ParamRepository $params,
        private ReviewRating $notes,
    ) {}

    /**
     * Rapport hebdomadaire d'une boutique.
     *
     * @param string $monday lundi de la semaine ('Y-m-d')
     */
    public function week(int $shopId, string $monday): array
    {
        $start = new DateTimeImmutable($monday);
        $dates = [];
        for ($i = 0; $i < self::RETAIL_DAYS; $i++) {
            $dates[] = $start->modify("+{$i} days")->format('Y-m-d');
        }

        $days = $this->days($shopId, $dates);
        $this->markLifted($days);

        return [
            'type'       => 'week',
            'shop'       => $this->shopHeader($shopId),
            'from'       => $dates[0],
            'to'         => $dates[count($dates) - 1],
            'week_no'    => (int)$start->format('W'),
            'days'       => array_values($days),
            'totals'     => $this->totals($days),
            'checklists' => $this->checklistNames($days),
            'feed'       => $this->feed($days),
            'thresholds' => $this->thresholds(),
            'diag'       => $this->diagnostics(),
        ];
    }

    /**
     * Rapport mensuel d'une boutique, agrégé par semaine.
     *
     * @param string $ym 'YYYY-MM'
     */
    public function month(int $shopId, string $ym): array
    {
        $first = new DateTimeImmutable($ym . '-01');
        $last  = $first->modify('last day of this month');

        $dates = [];
        for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
            if ((int)$d->format('N') !== 7) {   // dimanche : boutique fermée
                $dates[] = $d->format('Y-m-d');
            }
        }

        $days = $this->days($shopId, $dates);
        $this->markLifted($days);

        // Mois précédent : uniquement ce qui est déjà figé. Aller le chercher
        // à l'API doublerait le coût du rapport pour une flèche de tendance.
        $pFirst = $first->modify('-1 month');
        $prev   = $this->snapshots->range(
            $shopId,
            $pFirst->format('Y-m-01'),
            $pFirst->modify('last day of this month')->format('Y-m-d')
        );

        return [
            'type'       => 'month',
            'shop'       => $this->shopHeader($shopId),
            'ym'         => $ym,
            'from'       => $first->format('Y-m-d'),
            'to'         => $last->format('Y-m-d'),
            'weeks'      => $this->byWeek($days),
            'totals'     => $this->totals($days),
            'previous'   => $prev !== [] ? $this->totals($prev) : null,
            'checklists' => $this->conformityByChecklist($days),
            'top_nc'     => $this->topRecurring($days),
            'feed'       => $this->feed($days),
            'thresholds' => $this->thresholds(),
            'diag'       => $this->diagnostics(),
        ];
    }

    /**
     * Avancement des checklists d'une boutique sur une plage QUELCONQUE.
     *
     * Ni semaine ni mois : la plage du rapport de gestion, qui a ses propres
     * bornes. Même moteur, mêmes snapshots, donc aucun aller-retour
     * supplémentaire quand les jours sont déjà figés.
     *
     * @return array{days: array, totals: array, nc: array}
     */
    public function range(int $shopId, string $from, string $to): array
    {
        $dates = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            if ((int)$d->format('N') !== 7) {   // dimanche : boutique fermée
                $dates[] = $d->format('Y-m-d');
            }
        }
        if ($dates === []) {
            return ['days' => [], 'totals' => $this->totals([]), 'nc' => []];
        }

        $days = $this->days($shopId, $dates);
        $this->markLifted($days);

        // Les non-conformités à plat, les plus graves d'abord : c'est ce qui
        // demande une action, pas la liste des tâches réussies.
        $nc = [];
        foreach ($days as $date => $day) {
            foreach ($day['nc'] ?? [] as $one) {
                $nc[] = $one + ['date' => $date];
            }
        }
        usort($nc, function ($a, $b) {
            $rank = fn($x) => !empty($x['lifted']) ? 2 : ($x['severity'] === 'major' ? 0 : 1);
            return [$rank($a), $a['rating'] ?? 9, $b['date']] <=> [$rank($b), $b['rating'] ?? 9, $a['date']];
        });

        return [
            'days'   => array_values($days),
            'totals' => $this->totals($days),
            'nc'     => $nc,
            // Un rapport vide n'est pas une réponse : il faut pouvoir dire si
            // l'API a été interrogée et ce qu'elle a rendu.
            'diag'   => ['days_asked' => count($dates)] + $this->diagnostics(),
        ];
    }

    /**
     * De quoi expliquer un rapport sans conformité : ce qui a été demandé,
     * relu, et ce que l'avancement des checklists a rendu.
     */
    public function diagnostics(): array
    {
        return [
            'days_fetched'      => $this->fetched,
            'checklists_found'  => $this->checklistsFound,
            'progress_payloads' => $this->progressPayloads,
            'reviews_seen'      => $this->reviewsSeen,
        ];
    }

    /**
     * Les jours demandés : ceux déjà figés viennent de la base, les autres de
     * l'API en deux appels groupés.
     *
     * @param string[] $dates
     * @return array<string, array> map date => jour, dans l'ordre
     */
    private function days(int $shopId, array $dates): array
    {
        sort($dates);
        $known = $this->fresh
            ? []
            : $this->snapshots->range($shopId, $dates[0], $dates[count($dates) - 1]);
        $missing = array_values(array_diff($dates, array_keys($known)));

        if ($missing !== []) {
            foreach ($this->fetchDays($shopId, $missing) as $date => $day) {
                $known[$date] = $day;
                $this->fetched++;

                // Un jour est figé à DEUX conditions : il est clos (plus aucune
                // tâche ne s'y ajoutera), ET il porte quelque chose.
                //
                // Figer un jour VIDE serait le piège classique : « zéro tâche
                // planifiée » et « l'API n'a pas répondu » se ressemblent, et
                // graver le second rendrait la panne définitive — le rapport
                // afficherait 0/0 pour toujours sans plus jamais réinterroger.
                // C'est exactement ce qui s'est produit ici. Un jour vide est
                // donc relu à chaque génération ; comme tous les jours manquants
                // partent dans le même appel groupé, cela ne coûte rien de plus.
                $this->snapshots->put(
                    $shopId,
                    $day,
                    $date < date('Y-m-d') && (int)$day['planned'] > 0
                );
            }
        }

        // Un jour sans donnée reste présent, à zéro : un trou dans la semaine
        // doit se voir, pas disparaître du tableau.
        $out = [];
        foreach ($dates as $d) {
            $out[$d] = $known[$d] ?? $this->emptyDay($d);
        }
        return $out;
    }

    /**
     * Construit les jours manquants depuis l'API. DEUX attentes réseau au
     * total, quel que soit le nombre de jours.
     *
     * @param string[] $dates
     * @return array<string, array>
     */
    private function fetchDays(int $shopId, array $dates): array
    {
        // SOURCE PRIMAIRE : les tâches du jour. C'est l'endpoint sur lequel
        // l'écran des checklists s'appuie, et le seul dont la défaillance se
        // verrait immédiatement. Bâtir le rapport sur /checklists seul le
        // rendait dépendant d'un appel que l'écran, lui, enveloppe dans un
        // try/catch — d'où un rapport à « 0 tâche planifiée » là où l'écran
        // en affichait trente-quatre.
        $byDateTasks = $this->checklists->getShopTasksForDates($shopId, $dates);

        // SOURCE SECONDAIRE : l'avancement des checklists, qui seul porte la
        // note et la conformité. Son absence dégrade le rapport (plus de
        // conformité) sans l'effacer.
        $lists = $this->checklists->getChecklistsForDates($shopId, $dates);
        $pairs = [];
        foreach ($lists as $date => $payload) {
            foreach ($this->extractChecklists($payload) as $cl) {
                $pairs[] = ['date' => $date, 'checklist_id' => $cl['id']];
            }
        }
        $this->checklistsFound += count($pairs);
        $progress = $pairs !== [] ? $this->checklists->getProgressForPairs($shopId, $pairs) : [];
        $this->progressPayloads += count(array_filter($progress));

        // Avis par (date, tâche) : la jointure se fait sur task_id, comme sur
        // l'écran du jour.
        $reviewBy = [];
        foreach ($progress as $key => $prog) {
            $date = explode('|', $key)[0];
            foreach (($prog['tasks'] ?? []) as $t) {
                if (is_array($t) && !empty($t['task_id'])) {
                    $reviewBy[$date][(int)$t['task_id']] = $t;
                    if (($t['review_is_accepted'] ?? null) !== null) {
                        $this->reviewsSeen++;
                    }
                }
            }
        }

        $out = [];
        foreach ($dates as $date) {
            $day = $this->emptyDay($date);
            $this->foldTasks($day, $byDateTasks[$date] ?? [], $reviewBy[$date] ?? []);
            $out[$date] = $day;
        }
        return $out;
    }

    /**
     * Normalise la liste des checklists d'une journée : l'API l'enveloppe
     * parfois dans `checklists`, parfois non, et l'identifiant porte trois
     * noms selon les versions.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function extractChecklists($payload): array
    {
        $list = [];
        if (is_array($payload)) {
            $list = is_array($payload['checklists'] ?? null)
                ? $payload['checklists']
                : (array_is_list($payload) ? $payload : []);
        }
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = 0;
            foreach (['id', 'id_checklist', 'checklist_id'] as $k) {
                if (!empty($row[$k])) {
                    $id = (int)$row[$k];
                    break;
                }
            }
            if ($id > 0) {
                $out[] = ['id' => $id, 'name' => (string)($row['name'] ?? $row['checklist_name'] ?? ('#' . $id))];
            }
        }
        return $out;
    }

    /**
     * Agrège les tâches d'une journée, enrichies de leur avis quand il existe.
     *
     * @param array $tasks   tâches du jour (/consultant/shops/{id}/tasks)
     * @param array $reviews avis par task_id (/checklists/{cid}/progress)
     */
    private function foldTasks(array &$day, array $tasks, array $reviews): void
    {
        if ($tasks === []) {
            return;
        }
        $maxMajor = $this->thresholds()['nc_major_max_rating'];
        $byChecklist = [];

        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            $taskId = (int)($t['task_id'] ?? 0);
            $rev    = $reviews[$taskId] ?? [];
            $name   = (string)($t['checklist_name'] ?? $rev['checklist_name'] ?? '—');

            $byChecklist[$name] ??= ['name' => $name, 'planned' => 0, 'done' => 0,
                                     'reviewed' => 0, 'conform' => 0];
            $cl = &$byChecklist[$name];

            $done = (string)($t['status'] ?? 'PENDING') === 'DONE';
            $day['planned']++; $cl['planned']++;
            if ($done) { $day['done']++; $cl['done']++; }

            // Une tâche OBLIGATOIRE non faite est bloquante. Elle n'a pas de
            // note, donc pas de gravité : comptée à part, jamais fondue dans
            // les non-conformités où elle disparaîtrait.
            if (!$done && !empty($t['is_mandatory'])) {
                $day['blocking_missed']++;
            }

            $accepted = $t['review_is_accepted'] ?? $rev['review_is_accepted'] ?? null;
            if (!$done || $accepted === null) {
                continue;   // pas d'avis : ni conforme, ni non conforme
            }

            $day['reviewed']++; $cl['reviewed']++;
            $raw = $t['review_rating'] ?? $rev['review_rating'] ?? null;
            // Un verdict sans note vaut la note qu'aurait posée le bouton. Sans
            // cette déduction, une non-conformité sans note était comptée
            // MINEURE — alors que « non conforme » vaut 2, donc majeure.
            $rating = $this->notes->of($raw, ReviewRating::verdict($accepted));
            if ($rating !== null) {
                $day['rating_sum'] += $rating;
                $day['rating_count']++;
            }

            if ((int)$accepted === 1) {
                $day['conform']++; $cl['conform']++;
                // Retenu pour savoir plus tard si une non-conformité
                // antérieure sur CETTE tâche a été levée. Sans la liste des
                // tâches repassées conformes, « NC levée » ne serait qu'une
                // déduction par absence — et une tâche simplement retirée de
                // la checklist passerait pour une correction.
                if ($taskId > 0) {
                    $day['conform_ids'][] = $taskId;
                }
                continue;
            }

            // Non-conformité. La gravité vient de la NOTE du consultant. Sans
            // note, on ne peut pas décider : « mineure » par défaut, et le
            // rapport affiche « — » plutôt qu'une note inventée.
            $major = $rating !== null && $rating <= $maxMajor;
            $major ? $day['nc_major']++ : $day['nc_minor']++;

            $day['nc'][] = [
                'task_id'   => $taskId,
                'title'     => (string)($t['task_name'] ?? $t['name'] ?? ''),
                'checklist' => $name,
                'severity'  => $major ? 'major' : 'minor',
                'rating'    => $rating,
                'comment'   => (string)($t['review_comment'] ?? $rev['review_comment'] ?? ''),
                'photo'     => !empty($t['attachment_id']) ? (int)$t['attachment_id']
                             : (!empty($rev['attachment_id']) ? (int)$rev['attachment_id'] : null),
                'lifted'    => false,
            ];
            unset($cl);
        }

        $day['checklists'] = array_values($byChecklist);
    }

    /**
     * Marque « levée » toute non-conformité dont la MÊME tâche est ensuite
     * conforme dans la période. Sans ce passage, une NC corrigée le lendemain
     * pèserait aussi lourd qu'une NC laissée en l'état tout le mois.
     *
     * @param array<string, array> $days modifié sur place
     */
    private function markLifted(array &$days): void
    {
        // DERNIER jour où chaque tâche a été jugée conforme. Le dernier, pas
        // le premier : une NC du 20 est levée par un contrôle conforme du 25,
        // même si la tâche était déjà conforme le 4.
        $lastOk = [];
        foreach ($days as $date => $day) {
            foreach ($day['conform_ids'] ?? [] as $id) {
                $id = (int)$id;
                if (!isset($lastOk[$id]) || $date > $lastOk[$id]) {
                    $lastOk[$id] = $date;
                }
            }
        }
        // Levée = jugée conforme APRÈS la non-conformité. Pas avant : une tâche
        // conforme lundi puis non conforme jeudi n'est pas levée, elle a
        // régressé.
        foreach ($days as $date => $day) {
            foreach ($day['nc'] ?? [] as $i => $nc) {
                $id = (int)$nc['task_id'];
                $days[$date]['nc'][$i]['lifted'] =
                    $id > 0 && isset($lastOk[$id]) && $lastOk[$id] > $date;
            }
        }
    }

    /** Cumuls d'un ensemble de jours. */
    private function totals(array $days): array
    {
        $t = ['planned' => 0, 'done' => 0, 'reviewed' => 0, 'conform' => 0,
              'nc_major' => 0, 'nc_minor' => 0, 'blocking_missed' => 0,
              'rating_sum' => 0, 'rating_count' => 0, 'lifted' => 0];
        foreach ($days as $d) {
            foreach (['planned', 'done', 'reviewed', 'conform', 'nc_major', 'nc_minor',
                      'blocking_missed', 'rating_sum', 'rating_count'] as $k) {
                $t[$k] += (int)($d[$k] ?? 0);
            }
            foreach ($d['nc'] ?? [] as $nc) {
                if (!empty($nc['lifted'])) { $t['lifted']++; }
            }
        }
        $t['nc']        = $t['nc_major'] + $t['nc_minor'];
        $t['pct_done']  = $this->pct($t['done'], $t['planned']);
        $t['pct_conf']  = $this->pct($t['conform'], $t['reviewed']);
        $t['pct_lift']  = $this->pct($t['lifted'], $t['nc']);
        $t['avg_rating'] = $t['rating_count'] > 0
            ? round($t['rating_sum'] / $t['rating_count'], 1) : null;
        return $t;
    }

    /** Agrégation par semaine ISO, pour le tableau S1 → S5 du mensuel. */
    private function byWeek(array $days): array
    {
        $groups = [];
        foreach ($days as $date => $day) {
            $w = (new DateTimeImmutable($date))->format('o-W');
            $groups[$w][$date] = $day;
        }
        $out = [];
        foreach ($groups as $w => $set) {
            $dates = array_keys($set);
            sort($dates);
            $out[] = [
                'label'  => 'S' . ltrim(substr($w, 5), '0'),
                'from'   => $dates[0],
                'to'     => $dates[count($dates) - 1],
                'totals' => $this->totals($set),
            ];
        }
        return $out;
    }

    /** Taux de conformité par checklist, sur toute la période. */
    private function conformityByChecklist(array $days): array
    {
        $acc = [];
        foreach ($days as $day) {
            foreach ($day['checklists'] ?? [] as $cl) {
                $k = $cl['name'];
                $acc[$k] ??= ['name' => $k, 'planned' => 0, 'done' => 0, 'reviewed' => 0, 'conform' => 0];
                foreach (['planned', 'done', 'reviewed', 'conform'] as $f) {
                    $acc[$k][$f] += (int)($cl[$f] ?? 0);
                }
            }
        }
        $out = [];
        foreach ($acc as $cl) {
            $cl['pct_conf'] = $this->pct($cl['conform'], $cl['reviewed']);
            $cl['pct_done'] = $this->pct($cl['done'], $cl['planned']);
            $out[] = $cl;
        }
        usort($out, fn($a, $b) => ($a['pct_conf'] ?? 100) <=> ($b['pct_conf'] ?? 100));
        return $out;
    }

    /** Non-conformités les plus fréquentes du mois, les pires d'abord. */
    private function topRecurring(array $days, int $limit = 6): array
    {
        $acc = [];
        foreach ($days as $day) {
            foreach ($day['nc'] ?? [] as $nc) {
                $k = $nc['task_id'] > 0 ? 't' . $nc['task_id'] : 'n' . $nc['title'];
                $acc[$k] ??= ['title' => $nc['title'], 'checklist' => $nc['checklist'],
                              'count' => 0, 'lifted' => 0, 'major' => 0];
                $acc[$k]['count']++;
                if (!empty($nc['lifted'])) { $acc[$k]['lifted']++; }
                if ($nc['severity'] === 'major') { $acc[$k]['major']++; }
            }
        }
        usort($acc, fn($a, $b) => [$b['count'], $b['major']] <=> [$a['count'], $a['major']]);
        return array_slice(array_values($acc), 0, $limit);
    }

    /**
     * Constats terrain : les non-conformités les plus parlantes, photo et
     * commentaire du consultant à l'appui. Une NC sans commentaire ET sans
     * photo n'apprend rien de plus que le tableau — elle est écartée.
     */
    private function feed(array $days, int $limit = 12): array
    {
        $out = [];
        foreach ($days as $date => $day) {
            foreach ($day['nc'] ?? [] as $nc) {
                if ($nc['comment'] === '' && $nc['photo'] === null) {
                    continue;
                }
                $out[] = $nc + ['date' => $date];
            }
        }
        // Le plus grave d'abord ; à gravité égale, la note la plus basse ;
        // puis le plus récent — un constat d'hier prime sur celui du 3.
        usort($out, function ($a, $b) {
            $rank = fn($x) => !empty($x['lifted']) ? 2 : ($x['severity'] === 'major' ? 0 : 1);
            return [$rank($a), $a['rating'] ?? 9, $b['date']]
               <=> [$rank($b), $b['rating'] ?? 9, $a['date']];
        });
        return array_slice($out, 0, $limit);
    }

    /** Noms des checklists suivies sur la période (puces de l'en-tête). */
    private function checklistNames(array $days): array
    {
        $names = [];
        foreach ($days as $day) {
            foreach ($day['checklists'] ?? [] as $cl) {
                $names[$cl['name']] = true;
            }
        }
        return array_keys($names);
    }

    /** En-tête : boutique, consultant, franchisé. */
    private function shopHeader(int $shopId): array
    {
        foreach ($this->shopService->getAllShops() as $s) {
            if ((int)($s['id'] ?? 0) === $shopId) {
                return [
                    'id'         => $shopId,
                    'name'       => (string)($s['representative_name'] ?? $s['name'] ?? ('#' . $shopId)),
                    'franchisee' => (string)($s['owner_name'] ?? $s['franchisee_name'] ?? $s['contact_name'] ?? ''),
                ];
            }
        }
        return ['id' => $shopId, 'name' => '#' . $shopId, 'franchisee' => ''];
    }

    /**
     * Seuils d'affichage et règle de gravité. Tous réglables en configuration :
     * la limite entre « majeure » et « mineure » est une décision de méthode,
     * pas une constante du code.
     */
    public function thresholds(): array
    {
        $num = function (string $key, float $fallback): float {
            $v = $this->params->get($key);
            return ($v !== null && $v !== '' && is_numeric($v)) ? (float)$v : $fallback;
        };
        return [
            'nc_major_max_rating' => (int)$num('checklist_nc_major_max_rating', 2),
            'green_pct'           => $num('checklist_green_pct', 90),
            'orange_pct'          => $num('checklist_orange_pct', 75),
        ];
    }

    private function emptyDay(string $date): array
    {
        return ['date' => $date, 'planned' => 0, 'done' => 0, 'reviewed' => 0, 'conform' => 0,
                'nc_major' => 0, 'nc_minor' => 0, 'blocking_missed' => 0,
                'rating_sum' => 0, 'rating_count' => 0,
                'nc' => [], 'checklists' => [], 'conform_ids' => []];
    }

    /** null quand le dénominateur est nul : « 0 % » serait un jugement faux. */
    private function pct(int $n, int $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 1) : null;
    }
}
