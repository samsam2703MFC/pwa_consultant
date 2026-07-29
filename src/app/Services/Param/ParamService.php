<?php
namespace App\Consultant\app\Services\Param;

use App\Consultant\app\Repositories\Param\ParamRepository;

/**
 * Lecture typée des paramètres configurables (table consultant_param).
 * Les valeurs métier ne sont JAMAIS codées en dur dans la logique : elles
 * proviennent d'ici (base), avec un défaut de secours uniquement si la base
 * est indisponible.
 */
class ParamService
{
    public function __construct(private ParamRepository $repo) {}

    public function getFloat(string $key, float $fallback = 0.0): float
    {
        $v = $this->repo->get($key);
        return ($v !== null && is_numeric($v)) ? (float)$v : $fallback;
    }

    public function getInt(string $key, int $fallback = 0): int
    {
        $v = $this->repo->get($key);
        return ($v !== null && is_numeric($v)) ? (int)$v : $fallback;
    }

    public function getString(string $key, string $fallback = ''): string
    {
        $v = $this->repo->get($key);
        return $v ?? $fallback;
    }

    public function set(string $key, string $value): bool
    {
        return $this->repo->set($key, $value);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->repo->all();
    }
}
