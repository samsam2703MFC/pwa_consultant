<?php
namespace App\Consultant\app\Services\Agenda;

use App\Consultant\app\Repositories\Agenda\AgendaRepository;

/**
 * Agenda des visites consultants : persistance (via AgendaRepository), grille
 * mensuelle « type Google Calendar », génération d'invitation .ics + lien
 * Google Agenda, et actions à travailler par levier.
 */
class AgendaService
{
    /** Les 6 leviers HEXm (clé, lettre, nom) — pour les actions par levier. */
    public const LEVERS = [
        ['key' => 'trafic',     'letter' => 'T', 'name' => 'Trafic'],
        ['key' => 'recurrence', 'letter' => 'R', 'name' => 'Récurrence'],
        ['key' => 'xp',         'letter' => 'E', 'name' => 'Expérience'],
        ['key' => 'food',       'letter' => 'F', 'name' => 'Food Cost'],
        ['key' => 'labour',     'letter' => 'L', 'name' => 'Labour'],
        ['key' => 'overhead',   'letter' => 'O', 'name' => 'Overhead'],
    ];

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(private AgendaRepository $repo) {}

    public function createVisit(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        return $this->repo->createVisit($data);
    }

    public function addLeverAction(array $data): int
    {
        return $this->repo->addLeverAction($data);
    }

    public function updateVisitStatus(int $id, string $status): bool
    {
        return $this->repo->updateVisitStatus($id, $status);
    }

    public function getVisit(int $id): ?array
    {
        $v = $this->repo->getVisit($id);
        return $v ? $this->decorate($v) : null;
    }

    /** Visites d'un consultant sur un mois, décorées. */
    public function visitsForConsultantMonth(int $consultantId, int $year, int $month): array
    {
        [$from, $to] = $this->monthBounds($year, $month);
        return array_map([$this, 'decorate'], $this->repo->visitsForConsultant($consultantId, $from, $to));
    }

    /** Visites d'une boutique (tous consultants) sur un mois. */
    public function visitsForShopMonth(int $shopId, int $year, int $month): array
    {
        [$from, $to] = $this->monthBounds($year, $month);
        return array_map([$this, 'decorate'], $this->repo->visitsForShop($shopId, $from, $to));
    }

    public function leverActionsForShop(int $shopId): array
    {
        return $this->repo->leverActionsForShop($shopId);
    }

    /**
     * Grille mensuelle (semaines lun→dim) avec les visites du consultant.
     * @return array{label:string, year:int, month:int, weeks:array, count:int, visits:array}
     */
    public function buildMonth(int $consultantId, int $year, int $month): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $last  = $first->modify('last day of this month');
        $visits = $this->visitsForConsultantMonth($consultantId, $year, $month);

        $byDate = [];
        foreach ($visits as $v) {
            $byDate[$v['date']][] = $v;
        }

        $gridStart = $first->modify('monday this week');
        $offset    = (int)$gridStart->diff($first)->days;
        $rows      = (int)ceil(($offset + (int)$last->format('j')) / 7);
        $today     = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $weeks = [];
        $cur   = $gridStart;
        for ($w = 0; $w < $rows; $w++) {
            $row = [];
            for ($d = 0; $d < 7; $d++) {
                $iso = $cur->format('Y-m-d');
                $row[] = [
                    'day'      => (int)$cur->format('j'),
                    'in_month' => ((int)$cur->format('n') === $month),
                    'iso'      => $iso,
                    'today'    => ($iso === $today),
                    'visits'   => $byDate[$iso] ?? [],
                ];
                $cur = $cur->modify('+1 day');
            }
            $weeks[] = $row;
        }

        return [
            'label'  => self::MONTHS[$month] . ' ' . $year,
            'year'   => $year,
            'month'  => $month,
            'weeks'  => $weeks,
            'count'  => count($visits),
            'visits' => $visits,
            'prev'   => $first->modify('-1 month'),
            'next'   => $first->modify('+1 month'),
        ];
    }

    /** Invitation calendrier .ics (une visite). */
    public function icsForVisit(array $v): string
    {
        $start = new \DateTimeImmutable((string)$v['scheduled_at']);
        $end   = $start->modify('+' . (int)($v['duration_min'] ?? 60) . ' minutes');
        $fmt   = fn(\DateTimeImmutable $d) => $d->format('Ymd\THis');
        $esc   = fn($s) => preg_replace('/([,;\\\\])/', '\\\\$1', str_replace("\n", '\\n', (string)$s));

        $uid = 'visit-' . ($v['id'] ?? uniqid()) . '@atelierby';
        $sum = 'Visite conseil — ' . ($v['shop_name'] ?? '');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Atelier by//Agenda//FR',
            'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $fmt(new \DateTimeImmutable('now')),
            'DTSTART:' . $fmt($start),
            'DTEND:' . $fmt($end),
            'SUMMARY:' . $esc($sum),
            'DESCRIPTION:' . $esc($v['goal'] ?? ''),
            'LOCATION:' . $esc($v['shop_name'] ?? ''),
            'END:VEVENT', 'END:VCALENDAR',
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    /** Lien « Ajouter à Google Agenda » (aucune API requise). */
    public function gcalUrl(array $v): string
    {
        $start = new \DateTimeImmutable((string)$v['scheduled_at']);
        $end   = $start->modify('+' . (int)($v['duration_min'] ?? 60) . ' minutes');
        $q = http_build_query([
            'action'  => 'TEMPLATE',
            'text'    => 'Visite conseil — ' . ($v['shop_name'] ?? ''),
            'dates'   => $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis'),
            'details' => (string)($v['goal'] ?? ''),
            'location' => (string)($v['shop_name'] ?? ''),
        ]);
        return 'https://calendar.google.com/calendar/render?' . $q;
    }

    private function decorate(array $v): array
    {
        $at = (string)($v['scheduled_at'] ?? '');
        $v['date']  = substr($at, 0, 10);
        $v['time']  = substr($at, 11, 5);
        $status = (string)($v['status'] ?? 'planned');
        $v['status_class'] = ['planned' => 'plan', 'done' => 'done', 'cancelled' => 'cancel'][$status] ?? 'plan';
        return $v;
    }

    /** @return array{0:string,1:string} bornes 'Y-m-d' du mois. */
    private function monthBounds(int $year, int $month): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }
}
