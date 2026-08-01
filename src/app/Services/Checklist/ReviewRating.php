<?php
namespace App\Consultant\app\Services\Checklist;

use App\Consultant\app\Services\Param\ParamService;

/**
 * La note d'un avis — y compris quand elle n'a pas été saisie.
 *
 * Les boutons « Conforme » / « Non conforme » posent une note d'office (4 et 2
 * par défaut) : le consultant contrôle des dizaines de tâches, lui faire poser
 * une note ET un verdict pour chacune double le geste. Mais un avis peut
 * arriver de l'API avec le VERDICT et sans la note — posé ailleurs, ou par une
 * version antérieure du panel. La tâche s'affichait alors « Conforme » avec une
 * colonne note vide, elle sortait de la moyenne, et au rapport une
 * non-conformité sans note passait pour MINEURE alors que « non conforme »
 * signifie 2, donc majeure.
 *
 * La règle est donc la même partout : à défaut de note, le verdict la donne.
 * Elle vit ici, à un seul endroit, pour que la liste, la pile de contrôle et le
 * rapport mensuel ne puissent pas se contredire sur la même tâche.
 */
class ReviewRating
{
    public function __construct(private ParamService $params) {}

    private ?int $ok = null;
    private ?int $ko = null;

    /**
     * @param mixed     $rating   la note telle que l'API (ou le journal) la rend
     * @param bool|null $accepted le verdict, ou null s'il n'y en a pas
     * @return array{0: int|null, 1: bool} [note, déduite du verdict]
     */
    public function resolve(mixed $rating, ?bool $accepted): array
    {
        if (is_numeric($rating)) {
            return [(int)$rating, false];
        }
        if ($accepted === null) {
            return [null, false];
        }

        if ($this->ok === null) {
            $this->ok = $this->params->getInt('checklist_review_rating_ok', 4);
            $this->ko = $this->params->getInt('checklist_review_rating_ko', 2);
        }
        // 0 = « aucune note d'office » : la configuration a le droit de refuser
        // la déduction, et alors on ne déduit rien plutôt que d'inventer un 0.
        $n = $accepted ? $this->ok : $this->ko;
        return $n > 0 ? [$n, true] : [null, false];
    }

    /** La note seule, quand l'appelant n'a que faire de sa provenance. */
    public function of(mixed $rating, ?bool $accepted): ?int
    {
        return $this->resolve($rating, $accepted)[0];
    }

    /** Le verdict, ramené à un booléen ou à rien. */
    public static function verdict(mixed $accepted): ?bool
    {
        if ($accepted === null || $accepted === '') {
            return null;
        }
        if (is_bool($accepted)) {
            return $accepted;
        }
        return (int)$accepted === 1;
    }
}
