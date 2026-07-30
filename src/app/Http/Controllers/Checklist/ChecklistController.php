<?php
namespace App\Consultant\app\Http\Controllers\Checklist;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Checklist\ChecklistService;

class ChecklistController extends Controller
{
    public function __construct(private ChecklistService $checklistService) {}

    public function index(): void
    {
        $date = $this->resolveDate();
        $data = $this->safeFetch(
            [$this->checklistService, 'getNetworkTasksRanking'],
            $this->errors,
            [$date],
            ['network' => [], 'shops' => []]
        );

        $this->view('checklist/index', [
            'data'          => $data,
            'selected_date' => $date,
            'date'          => $date,
            'today'         => date('Y-m-d'),
            'active_nav'    => 'checklists',
        ]);
    }

    public function shopTasks(int $shopId): void
    {
        $date = $this->resolveDate();
        $data = $this->safeFetch(
            [$this->checklistService, 'getShopTaskDetails'],
            $this->errors,
            [$shopId, $date],
            ['summary' => [], 'tasks' => [], 'trend' => []]
        );

        // L'endpoint /tasks ne porte PAS de quoi ouvrir une réalisation
        // (ni completion_id, ni pièce jointe). L'endpoint d'avancement des
        // checklists, lui, expose completion_id, attachment_id, la note et
        // l'avis. On l'interroge pour toutes les checklists du jour EN
        // PARALLÈLE et on complète les tâches par task_id.
        $tasks = $this->withCompletionDetails($shopId, $date, $data['tasks'] ?? []);

        $this->view('checklist/shop_tasks', [
            'data'             => $data,
            'checklist_groups' => $this->groupTasksByChecklist($tasks),
            'date'             => $date,
            'shop_id'          => $shopId,
            'id_shop'          => $shopId,
            'today'            => date('Y-m-d'),
            'active_nav'       => 'checklists',
            // ?fields=1 : liste les champs que l'API renvoie par tâche, avec un
            // exemple de tâche FAITE. Sert à savoir si la réalisation porte de
            // quoi ouvrir sa fiche (note, photo) sans avoir à interroger l'API
            // à la main.
            'field_probe'      => isset($_GET['fields']) ? $this->fieldProbe($tasks) : null,
            // ?fields=2 : sonde AUSSI les endpoints checklists / progress, qui
            // sont câblés mais qu'aucun écran n'utilise — l'un d'eux porte
            // peut-être la photo de réalisation.
            'other_probes'     => (($_GET['fields'] ?? '') === '2')
                ? $this->otherEndpointProbes($shopId, $date) : null,
        ]);
    }

    /**
     * Forme des endpoints checklists / progress (jamais consommés jusqu'ici) :
     * clés de premier niveau et clés du premier élément de chaque liste.
     * Chaque sonde est isolée — un endpoint absent ne casse pas la page.
     *
     * @return array<int, array{endpoint: string, lines: string[]}>
     */
    private function otherEndpointProbes(int $shopId, string $date): array
    {
        $out = [];

        $describe = function ($payload, string $prefix = '') use (&$describe): array {
            $lines = [];
            if (!is_array($payload)) {
                return [$prefix . ' = ' . mb_substr((string)$payload, 0, 80)];
            }
            $isList = $payload === [] || array_is_list($payload);
            if ($isList) {
                $lines[] = $prefix . '[] : ' . count($payload) . ' élément(s)';
                if (isset($payload[0]) && is_array($payload[0])) {
                    $lines[] = $prefix . '[0] → ' . implode(' · ', array_keys($payload[0]));
                    foreach ($payload[0] as $k => $v) {
                        if (is_array($v)) {
                            $lines = array_merge($lines, $describe($v, $prefix . '[0].' . $k));
                        }
                    }
                }
                return $lines;
            }
            $lines[] = ($prefix !== '' ? $prefix . ' → ' : 'clés : ') . implode(' · ', array_keys($payload));
            foreach ($payload as $k => $v) {
                if (is_array($v)) {
                    $lines = array_merge($lines, $describe($v, $prefix . ($prefix !== '' ? '.' : '') . $k));
                }
            }
            return $lines;
        };

        try {
            $cl = $this->checklistService->getChecklistsForShop($shopId, $date);
            $out[] = ['endpoint' => "/consultant/shops/{$shopId}/checklists", 'lines' => $describe($cl)];

            // Premier identifiant de checklist trouvé dans la réponse.
            $cid  = null;
            $list = is_array($cl['checklists'] ?? null) ? $cl['checklists'] : (array_is_list($cl) ? $cl : []);
            foreach ($list as $row) {
                foreach (['id', 'id_checklist', 'checklist_id'] as $k) {
                    if (is_array($row) && !empty($row[$k])) { $cid = (int)$row[$k]; break 2; }
                }
            }
            if ($cid !== null) {
                $pr = $this->checklistService->getChecklistProgress($shopId, $cid, $date);
                $out[] = [
                    'endpoint' => "/consultant/shops/{$shopId}/checklists/{$cid}/progress",
                    'lines'    => $describe($pr),
                ];
            } else {
                $out[] = ['endpoint' => 'progress', 'lines' => ['aucun identifiant de checklist dans la réponse précédente']];
            }
        } catch (\Throwable $e) {
            $out[] = ['endpoint' => 'erreur', 'lines' => [get_class($e) . ' — ' . $e->getMessage()]];
        }
        return $out;
    }

    /**
     * Complète les tâches du jour avec le détail de leur réalisation
     * (identifiant de réalisation, pièce jointe, note, avis), lu dans
     * l'avancement des checklists.
     *
     * Ces champs n'existent pas sur /consultant/shops/{id}/tasks ; sans eux,
     * une tâche faite ne peut mener ni à sa photo ni à sa note. La jointure se
     * fait sur task_id. En cas d'indisponibilité, les tâches sont renvoyées
     * telles quelles : l'écran reste celui d'avant, jamais vide.
     *
     * @param array $tasks tâches renvoyées par /tasks
     * @return array mêmes tâches, complétées quand un avancement les décrit
     */
    private function withCompletionDetails(int $shopId, string $date, array $tasks): array
    {
        if ($tasks === []) {
            return $tasks;
        }
        try {
            $checklists = $this->checklistService->getChecklistsForShop($shopId, $date);
            $list = is_array($checklists['checklists'] ?? null)
                ? $checklists['checklists']
                : (array_is_list($checklists) ? $checklists : []);

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
            if ($ids === []) {
                return $tasks;
            }

            // Détail par task_id, toutes checklists confondues.
            $byTask = [];
            foreach ($this->checklistService->getChecklistProgressMany($shopId, $ids, $date) as $progress) {
                foreach (($progress['tasks'] ?? []) as $t) {
                    if (is_array($t) && !empty($t['task_id'])) {
                        $byTask[(int)$t['task_id']] = $t;
                    }
                }
            }
            if ($byTask === []) {
                return $tasks;
            }

            // Champs ajoutés. La note et l'auteur ne sont repris QUE s'ils
            // manquent : /tasks reste la source de l'affichage existant.
            $extra = ['completion_id', 'attachment_id', 'attachment_filename',
                      'review_id', 'review_is_accepted', 'review_rating', 'review_comment'];
            foreach ($tasks as &$task) {
                if (!is_array($task) || empty($task['task_id'])) {
                    continue;
                }
                $src = $byTask[(int)$task['task_id']] ?? null;
                if ($src === null) {
                    continue;
                }
                foreach ($extra as $k) {
                    if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
                        $task[$k] = $src[$k];
                    }
                }
                foreach (['note', 'completed_by', 'completed_at'] as $k) {
                    if ((string)($task[$k] ?? '') === '' && (string)($src[$k] ?? '') !== '') {
                        $task[$k] = $src[$k];
                    }
                }
            }
            unset($task);
        } catch (\Throwable $e) {
            error_log('[checklists] détail de réalisation : ' . $e->getMessage());
        }
        return $tasks;
    }

    /**
     * Champs disponibles par tâche + exemple d'une tâche faite (valeurs
     * tronquées). Diagnostic d'intégration, pas un écran métier.
     *
     * @return array{fields: string[], sample: array<string, string>, done_count: int, total: int}
     */
    private function fieldProbe(array $tasks): array
    {
        $fields = [];
        $sample = null;
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            foreach (array_keys($t) as $k) {
                $fields[(string)$k] = true;
            }
            if ($sample === null && (string)($t['status'] ?? '') === 'DONE') {
                $sample = $t;
            }
        }
        $flat = [];
        foreach (($sample ?? []) as $k => $v) {
            $flat[(string)$k] = is_scalar($v) || $v === null
                ? mb_substr((string)($v ?? 'null'), 0, 120)
                : mb_substr(json_encode($v, JSON_UNESCAPED_UNICODE) ?: '', 0, 120);
        }
        ksort($fields);
        return [
            'fields'     => array_keys($fields),
            'sample'     => $flat,
            'done_count' => count(array_filter($tasks, fn($t) => is_array($t) && ($t['status'] ?? '') === 'DONE')),
            'total'      => count($tasks),
        ];
    }

    /**
     * Regroupe les tâches du jour par checklist (champ checklist_name) pour
     * l'affichage en accordéon : chaque checklist se déplie sur ses tâches.
     * Tri : checklists par nom (les tâches sans checklist en dernier),
     * obligatoires en tête au sein d'un groupe. Chaque groupe porte ses
     * compteurs (faites / total, obligatoires en attente).
     *
     * @return array<int, array{name:string, tasks:array, total:int, done:int, mandatory_pending:int}>
     */
    private function groupTasksByChecklist(array $tasks): array
    {
        $groups = [];
        foreach ($tasks as $t) {
            $key = trim((string)($t['checklist_name'] ?? ''));
            $groups[$key]['name'] = $key;
            $groups[$key]['tasks'][] = $t;
        }

        uksort($groups, function (string $a, string $b): int {
            if ($a === '') return 1;   // « Sans checklist » toujours en dernier
            if ($b === '') return -1;
            return strnatcasecmp($a, $b);
        });

        foreach ($groups as &$g) {
            usort($g['tasks'], fn($x, $y) => empty($x['is_mandatory']) <=> empty($y['is_mandatory']));
            $g['total'] = count($g['tasks']);
            $g['done'] = count(array_filter($g['tasks'], fn($t) => ($t['status'] ?? '') === 'DONE'));
            $g['mandatory_pending'] = count(array_filter(
                $g['tasks'],
                fn($t) => !empty($t['is_mandatory']) && ($t['status'] ?? 'PENDING') !== 'DONE'
            ));
        }
        unset($g);

        return array_values($groups);
    }

    public function submitReview(): void
    {
        header('Content-Type: application/json');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw  = file_get_contents('php://input');
            $data = json_decode($raw, true) ?? [];
        } else {
            $data = $_POST;
        }

        $shopId = isset($data['shop_id']) ? (int)$data['shop_id'] : 0;

        if (!$shopId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'shop_id is required']);
            exit;
        }

        unset($data['shop_id']);

        $result = $this->checklistService->submitTaskReview($shopId, $data);

        if ($result['success'] ?? false) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $result['description'] ?? $result['error'] ?? 'Błąd zapisu oceny']);
        }
        exit;
    }

    private function resolveDate(): string
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            return date('Y-m-d');
        }

        return $date;
    }
}
