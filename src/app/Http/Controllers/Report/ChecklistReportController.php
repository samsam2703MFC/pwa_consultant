<?php
namespace App\Consultant\app\Http\Controllers\Report;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Checklist\ChecklistRepository;
use App\Consultant\app\Services\Report\ChecklistReportService;
use App\Consultant\core\Support\Route;
use DateTimeImmutable;

/**
 * Rapport « Checklist Tâches » — hebdomadaire et mensuel.
 *
 * S'ajoute au rapport de gestion existant (`/reports/view`) sans le toucher :
 * autre route, autre service, autres gabarits.
 */
class ChecklistReportController extends Controller
{
    public function __construct(private ChecklistReportService $service) {}

    /**
     * GET /reports/checklist/week?scope={shopId}[&week=YYYY-MM-DD]
     * `week` est un jour quelconque de la semaine voulue ; par défaut, la
     * semaine dernière (la semaine en cours n'est pas close).
     */
    #[Route('GET', '/reports/checklist/week')]
    public function week(): void
    {
        $shopId = (int)($_GET['scope'] ?? 0);
        if ($shopId <= 0) {
            $this->redirectToChooser();
            return;
        }

        $ref = (string)($_GET['week'] ?? '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) === 1
            ? new DateTimeImmutable($ref)
            : new DateTimeImmutable('monday last week');
        $monday = $day->modify('monday this week')->format('Y-m-d');

        // `?fresh=1` : ignorer les jours figés et tout redemander. Un jour figé
        // pendant qu'un endpoint se taisait garderait sinon sa conformité vide
        // pour toujours.
        // `?debug=1` implique `fresh` : échantillonner une réponse suppose
        // qu'un appel ait lieu, et un rapport servi par le cache n'en fait
        // aucun.
        $debug = ($_GET['debug'] ?? '') === '1';
        if ($debug || ($_GET['fresh'] ?? '') === '1') {
            $this->service->forceRefresh();
        }
        if ($debug) {
            ChecklistRepository::sampleOn();
        }
        $report = $this->safeFetch([$this->service, 'week'], $this->errors, [$shopId, $monday], []);
        if ($debug) {
            $report['samples'] = ChecklistRepository::samples();
        }

        $this->view('report/checklist_week', [
            'report'     => $report,
            'shop_id'    => $shopId,
            'monday'     => $monday,
            'prev_week'  => $day->modify('monday this week')->modify('-7 days')->format('Y-m-d'),
            'next_week'  => $this->nextOrNull($day->modify('monday this week')->modify('+7 days')),
            'active_nav' => 'reports',
        ]);
    }

    /**
     * GET /reports/checklist/month?scope={shopId}[&month=YYYY-MM]
     * Par défaut, le mois dernier — le mois en cours est partiel.
     */
    #[Route('GET', '/reports/checklist/month')]
    public function month(): void
    {
        $shopId = (int)($_GET['scope'] ?? 0);
        if ($shopId <= 0) {
            $this->redirectToChooser();
            return;
        }

        $ref = (string)($_GET['month'] ?? '');
        $ym  = preg_match('/^\d{4}-\d{2}$/', $ref) === 1
            ? $ref
            : (new DateTimeImmutable('first day of last month'))->format('Y-m');

        $debug = ($_GET['debug'] ?? '') === '1';
        if ($debug || ($_GET['fresh'] ?? '') === '1') {
            $this->service->forceRefresh();
        }
        if ($debug) {
            ChecklistRepository::sampleOn();
        }
        $report = $this->safeFetch([$this->service, 'month'], $this->errors, [$shopId, $ym], []);
        if ($debug) {
            $report['samples'] = ChecklistRepository::samples();
        }

        $first = new DateTimeImmutable($ym . '-01');
        $this->view('report/checklist_month', [
            'report'     => $report,
            'shop_id'    => $shopId,
            'ym'         => $ym,
            'prev_month' => $first->modify('-1 month')->format('Y-m'),
            'next_month' => $this->nextOrNull($first->modify('+1 month')),
            'active_nav' => 'reports',
        ]);
    }

    /**
     * Une période à venir n'a rien à montrer : le lien « suivant » disparaît
     * plutôt que de mener à un rapport vide.
     */
    private function nextOrNull(DateTimeImmutable $d): ?string
    {
        if ($d->format('Y-m-d') > date('Y-m-d')) {
            return null;
        }
        return $d->format('Y-m-d');
    }

    private function redirectToChooser(): void
    {
        header('Location: ' . ROOT . '/reports');
    }
}
