<?php
namespace App\Consultant\app\Http\Controllers\Valuation;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Valuation\ValuationService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Valorisation des boutiques / réseau (bouton « Valeurs réseau » de la section
 * Boutiques). Chargé à la demande (accordéon) pour ne pas alourdir la page.
 */
class ValuationController extends Controller
{
    public function __construct(private ValuationService $valuation) {}

    /** GET /shops/valuation — données de valorisation (JSON). */
    public function data(): JsonResponse
    {
        $data = $this->safeFetch(fn() => $this->valuation->build(), $this->errors, null, []);
        return $this->json(['ok' => empty($this->errors), 'data' => $data]);
    }
}
