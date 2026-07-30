<?php
namespace App\Consultant\app\Services\Kpi;

use App\Consultant\app\Repositories\Kpi\KpiThresholdRepository;

/**
 * Mise en forme conditionnelle des KPI — bandes de couleur par métrique
 * (marge brute, marge nette), lues depuis kpi_threshold. La bande retenue
 * pour une valeur est celle avec la plus grande borne basse ≤ valeur
 * (borne NULL = -infini).
 */
class KpiThresholdService
{
    public function __construct(private KpiThresholdRepository $repo) {}

    /** @return array<string, array<int, array{min: ?float, color: string, label: string}>> */
    public function all(): array
    {
        return $this->repo->all();
    }

    /** @return array<int, array{min: ?float, color: string, label: string}> */
    public function bands(string $metric): array
    {
        return $this->repo->all()[$metric] ?? [];
    }

    /** Couleur applicable à une valeur (%) pour une métrique, ou null. */
    public function colorFor(string $metric, float $pct): ?string
    {
        $color = null;
        foreach ($this->bands($metric) as $b) {
            if ($b['min'] === null || $pct >= $b['min']) {
                $color = $b['color'];
            }
        }
        return $color;
    }
}
