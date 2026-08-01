<?php
namespace App\Consultant\app\Services\Checklist;

use App\Consultant\app\Repositories\Checklist\TaskReviewRepository;
use App\Consultant\app\Services\Param\ParamService;

/**
 * Toutes les tâches du réseau pour une journée, en arbre Boutique › Checklist
 * › Tâche.
 *
 * L'écran par boutique existait déjà ; il oblige à revenir en arrière entre
 * chaque contrôle. Ici, le consultant a la file entière sous les yeux et
 * évalue à la suite.
 *
 * Le coût réseau est la contrainte : trois familles d'appels sont nécessaires
 * (les tâches, les checklists, leur avancement) et seule la dernière porte la
 * note et l'avis. Chaque famille part en parallèle — ApiClient plafonne
 * lui-même le nombre de requêtes simultanées.
 */
class NetworkTaskListService
{
    public function __construct(
        private ChecklistService $checklists,
        private TaskReviewRepository $reviews,
        private ParamService $params
    ) {}

    /** Diagnostic du dernier appel : ce qui a été demandé, ce qui est revenu. */
    private array $diag = [];

    /** Périmètre d'évaluation, lu une fois par rendu (voir enrich()). */
    private bool $needsPhoto = true;
    private bool $needsMandatory = true;

    public function diagnostics(): array
    {
        return $this->diag;
    }

    /**
     * @param array $shops boutiques du classement réseau (shop_id, shop_name, …)
     * @return array{shops: array, totals: array}
     */
    public function forDate(array $shops, string $date): array
    {
        $order = [];
        foreach ($shops as $s) {
            $id = (int)($s['shop_id'] ?? $s['id'] ?? 0);
            if ($id > 0) {
                $order[$id] = [
                    'shop_id'    => $id,
                    'shop_name'  => (string)($s['shop_name'] ?? $s['name'] ?? ('#' . $id)),
                    'shop_city'  => (string)($s['shop_city'] ?? $s['city'] ?? ''),
                    'completion' => isset($s['completion_rate']) ? (float)$s['completion_rate'] : null,
                ];
            }
        }

        // Périmètre de ce que le consultant évalue — lu une fois par rendu.
        $this->needsPhoto     = $this->params->getInt('checklist_review_needs_photo', 1) === 1;
        $this->needsMandatory = $this->params->getInt('checklist_review_needs_mandatory', 1) === 1;

        // Garde-fou : un parc qui grandit ne doit pas transformer l'écran en
        // attente de trente secondes. Au-delà, on tronque et on le DIT.
        $max = max(1, $this->params->getInt('checklist_all_tasks_max_shops', 30));
        $tronque = max(0, count($order) - $max);
        if ($tronque > 0) {
            $order = array_slice($order, 0, $max, true);
        }

        $ids = array_keys($order);
        if ($ids === []) {
            $this->diag = ['shops' => 0, 'truncated' => 0, 'tasks' => 0, 'progress' => 0, 'journal' => 0];
            return ['shops' => [], 'totals' => $this->totals([])];
        }

        // 1) Les tâches — la source de vérité de ce qui était planifié.
        $tasksByShop = $this->checklists->getTasksForShops($ids, $date);

        // 2) Les checklists, puis 3) leur avancement : c'est le seul endroit où
        //    vivent la note et l'avis du consultant.
        $pairs = [];
        foreach ($this->checklists->getChecklistsForShops($ids, $date) as $shopId => $payload) {
            foreach ($this->checklistIds($payload) as $cid) {
                $pairs[] = ['shop_id' => (int)$shopId, 'checklist_id' => $cid];
            }
        }
        $progress = $pairs === [] ? [] : $this->checklists->getProgressForShopChecklists($pairs, $date);

        // Détail par (boutique, tâche) : identifiant de réalisation, pièce
        // jointe, note, avis.
        $detail = [];
        foreach ($progress as $key => $payload) {
            $shopId = (int)explode('|', $key)[0];
            $cid    = (int)($payload['checklist']['id'] ?? explode('|', $key)[1] ?? 0);
            foreach (($payload['tasks'] ?? []) as $t) {
                if (is_array($t) && !empty($t['task_id'])) {
                    $t['checklist_id'] = $cid;
                    $detail[$shopId . '|' . (int)$t['task_id']] = $t;
                }
            }
        }

        // Le journal local sait QUI a évalué — l'API ne l'expose pas — et
        // complète la note quand l'avancement ne l'a pas rendue.
        $journal = $this->reviews->forShopsDate($ids, $date);

        $out = [];
        foreach ($order as $shopId => $shop) {
            $groups = $this->group($tasksByShop[$shopId] ?? [], $shopId, $detail, $journal);
            if ($groups === []) {
                continue;
            }
            $shop['checklists'] = $groups;
            $shop['counts']     = $this->countShop($groups);
            $out[] = $shop;
        }

        $this->diag = [
            'shops'     => count($ids),
            'truncated' => $tronque,
            'tasks'     => array_sum(array_map('count', $tasksByShop)),
            'progress'  => count($detail),
            'journal'   => count($journal),
        ];

        return ['shops' => $out, 'totals' => $this->totals($out)];
    }

    /** Identifiants de checklist d'une réponse, quelle que soit sa forme. */
    private function checklistIds(array $payload): array
    {
        $list = is_array($payload['checklists'] ?? null)
            ? $payload['checklists']
            : (array_is_list($payload) ? $payload : []);
        $ids = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['id', 'id_checklist', 'checklist_id'] as $k) {
                if (!empty($row[$k])) {
                    $ids[] = (int)$row[$k];
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Regroupe les tâches d'une boutique par checklist et les complète de leur
     * avis. Même ordre que l'écran par boutique : checklists par nom, sans
     * checklist en dernier, obligatoires en tête.
     */
    private function group(array $tasks, int $shopId, array $detail, array $journal): array
    {
        $groups = [];
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            // Le nom de checklist se lit sur la tâche BRUTE : c'est /tasks qui
            // le porte, et lui seul. Le lire après mise à plat rangeait tout
            // le réseau sous « Sans checklist ».
            $name = trim((string)($t['checklist_name'] ?? ''));
            $t = $this->enrich($t, $shopId, $detail, $journal);
            $key  = $name !== '' ? $name : "\u{FFFF}";      // sans nom → en fin de tri
            if (!isset($groups[$key])) {
                $groups[$key] = ['name' => $name, 'tasks' => [], 'total' => 0, 'done' => 0,
                                 'to_review' => 0, 'nc' => 0, 'mandatory_pending' => 0];
            }
            $groups[$key]['tasks'][] = $t;
            $groups[$key]['total']++;
            if ($t['is_done']) {
                $groups[$key]['done']++;
                if ($t['review_state'] === 'todo') { $groups[$key]['to_review']++; }
                if ($t['review_state'] === 'ko')   { $groups[$key]['nc']++; }
            } elseif ($t['is_mandatory']) {
                $groups[$key]['mandatory_pending']++;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        // Obligatoires en tête, puis l'ordre de la checklist — le même que sur
        // l'écran d'une boutique. Le tri de PHP 8 est stable : à égalité,
        // l'ordre d'origine tient. Ranger « à évaluer » en premier ferait deux
        // écrans qui ne présentent pas la même checklist dans le même ordre ;
        // ce travail-là est celui des filtres.
        foreach ($groups as &$g) {
            usort($g['tasks'], fn(array $a, array $b): int
                => ((int)$b['is_mandatory']) <=> ((int)$a['is_mandatory']));
        }
        unset($g);
        return array_values($groups);
    }

    /** Une tâche, mise à plat pour l'affichage et pour la modale d'évaluation. */
    private function enrich(array $t, int $shopId, array $detail, array $journal): array
    {
        $taskId = (int)($t['task_id'] ?? 0);
        $src    = $detail[$shopId . '|' . $taskId] ?? [];
        $j      = $journal[$shopId . '|' . $taskId] ?? [];

        $rating   = $t['review_rating']      ?? $src['review_rating']      ?? $j['rating']      ?? null;
        $accepted = $t['review_is_accepted'] ?? $src['review_is_accepted'] ?? $j['is_accepted'] ?? null;
        $status   = strtoupper((string)($t['status'] ?? ''));
        $done     = $status === 'DONE';
        $photo    = !empty($t['requires_photo']);
        $obligatoire = !empty($t['is_mandatory']);

        // Ce que le consultant évalue : une tâche qui exige une PHOTO — sans
        // preuve, il n'y a rien à juger — et qui est OBLIGATOIRE. Une tâche
        // faite hors de ce périmètre s'affiche « Faite » : elle n'a rien à
        // réclamer et ne doit pas gonfler la file d'attente.
        $evaluable = (!$this->needsPhoto || $photo) && (!$this->needsMandatory || $obligatoire);

        // Un avis existe dès qu'une note OU un verdict est posé. Sans note, la
        // gravité serait indéterminée — mais l'avis, lui, a bien eu lieu. Un
        // avis déjà posé s'affiche TOUJOURS, même hors périmètre : le cacher
        // ferait disparaître un jugement réellement rendu.
        $state = 'na';
        if ($done) {
            if ($rating !== null || $accepted !== null) {
                $state = ((int)$accepted === 0 && $accepted !== null) ? 'ko' : 'ok';
            } else {
                $state = $evaluable ? 'todo' : 'done';
            }
        }

        return [
            'task_id'       => $taskId,
            'name'          => (string)($t['task_name'] ?? $t['name'] ?? ''),
            'status'        => $status,
            'is_done'       => $done,
            'is_mandatory'  => $obligatoire,
            'requires_photo' => $photo,
            'completed_by'  => (string)($t['completed_by'] ?? $src['completed_by'] ?? ''),
            'completed_at'  => (string)($t['completed_at'] ?? $src['completed_at'] ?? ''),
            'note'          => (string)($t['note'] ?? $src['note'] ?? ''),
            'checklist_id'  => (int)($t['checklist_id'] ?? $src['checklist_id'] ?? 0),
            'completion_id' => (int)($t['completion_id'] ?? $src['completion_id'] ?? 0) ?: null,
            'attachment_id' => (int)($t['attachment_id'] ?? $src['attachment_id'] ?? 0) ?: null,
            'attachment_filename' => (string)($t['attachment_filename'] ?? $src['attachment_filename'] ?? ''),
            'review_rating'  => $rating === null ? null : (int)$rating,
            'review_accepted' => $accepted === null ? null : (int)$accepted === 1,
            'review_comment' => (string)($t['review_comment'] ?? $src['review_comment'] ?? $j['comment'] ?? ''),
            'review_by'      => (string)($j['consultant_name'] ?? ''),
            'evaluable'      => $evaluable,
            'review_state'   => $state,
        ];
    }

    private function countShop(array $groups): array
    {
        $c = ['total' => 0, 'done' => 0, 'to_review' => 0, 'nc' => 0, 'mandatory_pending' => 0];
        foreach ($groups as $g) {
            foreach ($c as $k => $_) {
                $c[$k] += (int)($g[$k] ?? 0);
            }
        }
        return $c;
    }

    private function totals(array $shops): array
    {
        $t = ['shops' => count($shops), 'total' => 0, 'done' => 0, 'to_review' => 0,
              'nc' => 0, 'mandatory_pending' => 0, 'rated' => 0, 'rating_sum' => 0];
        foreach ($shops as $s) {
            foreach (['total', 'done', 'to_review', 'nc', 'mandatory_pending'] as $k) {
                $t[$k] += (int)($s['counts'][$k] ?? 0);
            }
            foreach ($s['checklists'] as $g) {
                foreach ($g['tasks'] as $task) {
                    if ($task['review_rating'] !== null) {
                        $t['rated']++;
                        $t['rating_sum'] += $task['review_rating'];
                    }
                }
            }
        }
        // Note moyenne du jour : la même mesure que dans les rapports, pour que
        // les deux écrans ne racontent pas deux histoires.
        $t['avg_rating'] = $t['rated'] > 0 ? round($t['rating_sum'] / $t['rated'], 1) : null;
        return $t;
    }
}
