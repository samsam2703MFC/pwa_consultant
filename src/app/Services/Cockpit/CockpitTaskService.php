<?php
namespace App\Consultant\app\Services\Cockpit;

use App\Consultant\app\Repositories\Cockpit\CockpitTaskRepository;

/**
 * Les tâches réseau du consultant, mises en forme pour l'écran.
 *
 * Trois états, dans l'ordre où ils comptent pour celui qui les porte :
 * à faire (dont en retard), annoncées et en attente du verdict, évaluées.
 */
class CockpitTaskService
{
    public function __construct(private CockpitTaskRepository $repo) {}

    public function disponible(): bool
    {
        return $this->repo->disponible();
    }

    /**
     * @return array{groupes: array<int, array<string, mixed>>, compteurs: array<string, int>}
     */
    public function forConsultant(int $membershipId, string $aujourdhui): array
    {
        $enRetard = [];
        $aFaire   = [];
        $attente  = [];
        $evaluees = [];

        foreach ($this->repo->forConsultant($membershipId) as $r) {
            $t = $this->presenter($r, $aujourdhui);
            if ($t['note'] !== null)      { $evaluees[] = $t; continue; }
            if ($t['rendue'])             { $attente[]  = $t; continue; }
            if ($t['en_retard'])          { $enRetard[] = $t; continue; }
            $aFaire[] = $t;
        }

        $groupes = [];
        foreach ([
            ['en_retard', 'En retard',            '#8D1D2C', $enRetard],
            ['a_faire',   'À faire',              '#8a5a13', $aFaire],
            ['attente',   'En attente du verdict', '#435ebe', $attente],
            ['evaluees',  'Évaluées',             '#2D7A3E', $evaluees],
        ] as [$cle, $titre, $couleur, $items]) {
            if ($items !== []) {
                $groupes[] = ['cle' => $cle, 'titre' => $titre, 'couleur' => $couleur,
                              'n' => count($items), 'items' => $items];
            }
        }

        return [
            'groupes'   => $groupes,
            'compteurs' => [
                'a_faire'  => count($aFaire) + count($enRetard),
                'retard'   => count($enRetard),
                'attente'  => count($attente),
                'evaluees' => count($evaluees),
            ],
        ];
    }

    /** Une ligne de base transformée en ce que le gabarit affiche. */
    private function presenter(array $r, string $aujourdhui): array
    {
        $note   = isset($r['note']) && $r['note'] !== null ? (int)$r['note'] : null;
        $rendue = ($r['done_on'] ?? null) !== null;
        $due    = (string)($r['due_on'] ?? '');

        return [
            'id'          => (string)$r['id'],
            'nom'         => (string)$r['name'],
            'projet'      => (string)($r['project'] ?? ''),
            'attendu'     => trim((string)($r['description'] ?? '')),
            'echeance'    => $due,
            'rendue'      => $rendue,
            'rendue_le'   => $r['done_on'] ?? null,
            'rendue_par'  => $r['delivered_by'] ?? null,
            // NULL sur une tâche rendue = cochée par la direction, pas annoncée
            // par le consultant. La nuance change ce que l'écran propose.
            'annoncee'    => $rendue && ($r['delivered_by'] ?? null) !== null,
            'mot'         => trim((string)($r['delivery_note'] ?? '')),
            'note'        => $note,
            'evaluee_par' => $r['validated_by'] ?? null,
            'en_retard'   => !$rendue && $due !== '' && $due < $aujourdhui,
            'jours'       => $due !== '' ? $this->joursRestants($due, $aujourdhui) : null,
        ];
    }

    /** Jours restants — négatif si l'échéance est passée. */
    private function joursRestants(string $due, string $aujourdhui): int
    {
        $a = strtotime($due);
        $b = strtotime($aujourdhui);
        if ($a === false || $b === false) {
            return 0;
        }
        return (int)floor(($a - $b) / 86400);
    }
}
