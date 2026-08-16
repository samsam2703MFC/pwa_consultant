<?php
namespace App\Consultant\app\Http\Controllers\Cockpit;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Cockpit\CockpitTaskRepository;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\app\Services\Cockpit\CockpitTaskService;
use App\Consultant\core\Support\GlobalRegistry;

/**
 * « Mes tâches réseau » — les tâches confiées au consultant par la direction.
 *
 * Elles vivent dans le back office CEO (`ceo_project_task`), et le consultant
 * n'avait aucune vue dessus : la direction créait la tâche, cochait « rendue »
 * en son nom, puis la notait. Le seul geste rendu ici est celui qui manquait —
 * annoncer soi-même la remise, et y joindre un mot.
 *
 * Juger reste à la direction : cet écran ne touche jamais à la note.
 */
class CockpitTaskController extends Controller
{
    public function __construct(
        private CockpitTaskService $service,
        private CockpitTaskRepository $repo,
        private ConsultantUserRepository $consultantUsers
    ) {}

    /** GET /mes-taches-reseau */
    public function index(): void
    {
        $membershipId = $this->membershipId();
        $donnees = $membershipId > 0
            ? $this->service->forConsultant($membershipId, date('Y-m-d'))
            : ['groupes' => [], 'compteurs' => ['a_faire' => 0, 'retard' => 0, 'attente' => 0, 'evaluees' => 0]];

        $this->view('cockpit/my_tasks', [
            'groupes'    => $donnees['groupes'],
            'compteurs'  => $donnees['compteurs'],
            // Un écran vide a deux causes très différentes : le cockpit n'est
            // pas installé sur cette base, ou le consultant n'a simplement
            // aucune tâche. Les confondre envoie chercher une panne qui
            // n'existe pas.
            'cockpit_ok' => $this->repo->disponible(),
            'identifie'  => $membershipId > 0,
            'active_nav' => 'network_tasks',
        ]);
    }

    /** POST /mes-taches-reseau/remise — le consultant annonce la remise. */
    public function remise(): void
    {
        $membershipId = $this->membershipId();
        $taskId = trim((string)($_POST['task_id'] ?? ''));
        $mot    = trim((string)($_POST['mot'] ?? ''));

        if ($membershipId <= 0 || $taskId === '') {
            $this->repondre(false, 'tâche ou identité manquante');
            return;
        }
        $ok = $this->repo->declarerRemise($taskId, $membershipId, $this->nomConsultant($membershipId), $mot);
        $this->repondre($ok, $ok ? 'Remise annoncée' : ($this->repo->lastError ?? 'échec'));
    }

    /** POST /mes-taches-reseau/annuler — remise annoncée par erreur. */
    public function annuler(): void
    {
        $membershipId = $this->membershipId();
        $taskId = trim((string)($_POST['task_id'] ?? ''));
        if ($membershipId <= 0 || $taskId === '') {
            $this->repondre(false, 'tâche ou identité manquante');
            return;
        }
        $ok = $this->repo->annulerRemise($taskId, $membershipId);
        $this->repondre($ok, $ok ? 'Remise annulée' : ($this->repo->lastError ?? 'échec'));
    }

    /** L'identité du consultant connecté : le membership du jeton. */
    private function membershipId(): int
    {
        $user = GlobalRegistry::get('user');
        return (int)($user['membership_id'] ?? $user['id'] ?? 0);
    }

    /**
     * Le nom à inscrire sur la remise.
     *
     * Lu dans les tables de référence, comme l'écran de profil — pas recopié
     * depuis le jeton, dont les claims varient d'un émetteur à l'autre.
     */
    private function nomConsultant(int $membershipId): string
    {
        $p = $this->consultantUsers->getConsultantData($membershipId)['profile'] ?? [];
        foreach (['display_name', 'full_name'] as $k) {
            if (!empty($p[$k])) {
                return (string)$p[$k];
            }
        }
        $nom = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        return $nom !== '' ? $nom : ('Consultant #' . $membershipId);
    }

    private function repondre(bool $ok, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$ok) {
            http_response_code(422);
        }
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
