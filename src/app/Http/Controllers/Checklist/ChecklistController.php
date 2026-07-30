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
        ]);
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

        // shop_id reste dans le corps : la boutique est déjà dans l'URL, mais
        // rien ne dit que l'API ne l'attend pas aussi — et un champ en trop est
        // sans effet là où un champ manquant fait échouer l'appel.
        $result = $this->checklistService->submitTaskReview($shopId, $data);

        if ($result['success'] ?? false) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(422);
            // `detail` = corps brut renvoyé par l'API. Le format attendu par
            // /task-reviews n'est pas documenté : afficher la réponse telle
            // quelle évite d'avancer par essais successifs.
            echo json_encode([
                'success' => false,
                'error'   => $result['description'] ?? $result['error'] ?? 'Erreur d\'enregistrement',
                'detail'  => $result['response'] ?? null,
                'sent'    => array_keys($data),
            ], JSON_UNESCAPED_UNICODE);
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
