<?php
namespace App\Consultant\app\Repositories\Cockpit;

use App\Consultant\core\Db\Database;
use PDO;
use Throwable;

/**
 * Les tâches réseau confiées au consultant par la direction (`ceo_project_task`).
 *
 * CES TABLES NE SONT PAS AU PANEL. Elles appartiennent au back office CEO
 * (`consultant_BO`), qui les crée et en fixe la forme ; les deux applications
 * partagent la base `atelierby_db`. Le panel n'y fait donc jamais de CREATE ni
 * d'ALTER : il lit, et n'écrit que la remise du consultant sur SA propre tâche.
 *
 * Jusqu'ici le consultant n'avait aucune vue sur ces tâches : la direction les
 * créait, cochait « rendue » en son nom, puis les notait. Le seul geste que
 * cette classe autorise est celui qui manquait — annoncer soi-même la remise.
 *
 * Table absente (panel déployé sans le cockpit) → listes vides, aucune erreur :
 * l'écran affiche son état vide, il ne casse pas.
 */
class CockpitTaskRepository
{
    /** Colonnes optionnelles réellement présentes, détectées une fois. */
    private ?array $colonnes = null;

    /** Motif du dernier échec, pour que le silence s'explique. */
    public ?string $lastError = null;

    protected function pdo(): ?PDO
    {
        return Database::pdo();
    }

    /**
     * Les colonnes de `ceo_project_task` réellement présentes.
     *
     * Le cockpit ajoute les siennes au fil de ses versions (`note`,
     * `delivered_by`, `delivery_note`…). Le panel ne peut pas les exiger : il
     * s'adapte à ce qu'il trouve, sinon une base en retard d'une version fait
     * échouer la lecture entière.
     *
     * @return array<int, string>
     */
    private function colonnes(): array
    {
        if ($this->colonnes !== null) {
            return $this->colonnes;
        }
        $this->colonnes = [];
        $pdo = $this->pdo();
        if ($pdo === null) {
            $this->lastError = 'base locale indisponible';
            return $this->colonnes;
        }
        try {
            $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ceo_project_task'");
            $this->colonnes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
        }
        return $this->colonnes;
    }

    /** Le cockpit est-il installé sur cette base ? */
    public function disponible(): bool
    {
        return in_array('id', $this->colonnes(), true);
    }

    private function a(string $colonne): bool
    {
        return in_array($colonne, $this->colonnes(), true);
    }

    /**
     * Les tâches d'un consultant, la plus urgente d'abord.
     *
     * L'identité vient du membership du jeton — la même clé que le cockpit
     * écrit dans `owner_id` sous la forme « u<membership> ». Les jeux plus
     * anciens portent un identifiant libre : on accepte les deux écritures
     * plutôt que de masquer des tâches réellement assignées.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forConsultant(int $membershipId): array
    {
        if ($membershipId <= 0 || !$this->disponible()) {
            return [];
        }
        $pdo = $this->pdo();
        $sel = ['t.id', 't.name', 't.due_on', 't.done_on', 't.description', 't.shop_id', 'p.name AS project'];
        foreach (['note', 'validated_by', 'validated_at', 'delivered_by', 'delivery_note', 'budget'] as $c) {
            if ($this->a($c)) {
                $sel[] = 't.' . $c;
            }
        }
        try {
            $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . '
                                   FROM ceo_project_task t
                                   JOIN ceo_project p ON p.id = t.project_id
                                  WHERE t.owner_kind = \'c\' AND t.owner_id IN (?, ?)
                               ORDER BY (t.done_on IS NOT NULL), t.due_on');
            $st->execute(['u' . $membershipId, (string)$membershipId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /** Une tâche précise, seulement si elle appartient bien à ce consultant. */
    public function findOwned(string $taskId, int $membershipId): ?array
    {
        foreach ($this->forConsultant($membershipId) as $t) {
            if ((string)$t['id'] === $taskId) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Le consultant annonce la remise de SA tâche.
     *
     * Écrit `done_on` — la même colonne que la case de la direction, pour que
     * les deux applications parlent du même fait — et, si le cockpit les a
     * posées, `delivered_by` / `delivery_note` : c'est ce qui distingue une
     * remise annoncée par celui qui l'a produite d'une case cochée à sa place.
     *
     * Ne touche jamais à `note` : juger reste à la direction.
     */
    public function declarerRemise(string $taskId, int $membershipId, string $qui, string $mot): bool
    {
        $t = $this->findOwned($taskId, $membershipId);
        if ($t === null) {
            $this->lastError = 'tâche introuvable ou non assignée';
            return false;
        }
        $pdo = $this->pdo();
        if ($pdo === null) {
            $this->lastError = 'base locale indisponible';
            return false;
        }
        $sets = ['done_on = ?'];
        $args = [date('Y-m-d')];
        if ($this->a('delivered_by')) {
            $sets[] = 'delivered_by = ?';
            $args[] = mb_substr($qui, 0, 80);
        }
        if ($this->a('delivery_note')) {
            $sets[] = 'delivery_note = ?';
            $args[] = $mot !== '' ? mb_substr($mot, 0, 2000) : null;
        }
        $args[] = $taskId;
        try {
            $st = $pdo->prepare('UPDATE ceo_project_task SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $st->execute($args);
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Annuler une remise annoncée par erreur.
     *
     * Refusé dès que la direction a noté : revenir en arrière effacerait le
     * fait qui a été jugé, et la note deviendrait orpheline.
     */
    public function annulerRemise(string $taskId, int $membershipId): bool
    {
        $t = $this->findOwned($taskId, $membershipId);
        if ($t === null) {
            $this->lastError = 'tâche introuvable ou non assignée';
            return false;
        }
        if (($t['note'] ?? null) !== null) {
            $this->lastError = 'déjà évaluée par la direction';
            return false;
        }
        $pdo = $this->pdo();
        if ($pdo === null) {
            $this->lastError = 'base locale indisponible';
            return false;
        }
        $sets = ['done_on = NULL'];
        if ($this->a('delivered_by')) {
            $sets[] = 'delivered_by = NULL';
        }
        if ($this->a('delivery_note')) {
            $sets[] = 'delivery_note = NULL';
        }
        try {
            $st = $pdo->prepare('UPDATE ceo_project_task SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $st->execute([$taskId]);
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
}
