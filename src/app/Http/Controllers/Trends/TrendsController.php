<?php
namespace App\Consultant\app\Http\Controllers\Trends;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Trends\TrendsService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Section Tendances — CA consolidé du réseau (N, N-1, objectifs) sur les
 * 12 derniers mois + top 3 des meilleurs mois. La page se charge tout de
 * suite ; les données arrivent ensuite via /trends/data (beaucoup d'appels
 * API en parallèle côté serveur).
 */
class TrendsController extends Controller
{
    public function __construct(private TrendsService $trends) {}

    /** GET /trends — la page. */
    public function index(): void
    {
        $this->view('trends/index', []);
    }

    /** GET /trends/data — données consolidées (JSON). */
    public function data(): JsonResponse
    {
        // 24 fenêtres de ventes + 12 de targets par boutique : on laisse le
        // temps au repli SQL local si l'API ventes n'est pas disponible.
        @set_time_limit(180);
        $data = $this->safeFetch(fn() => $this->trends->build(), $this->errors, null, []);
        return $this->json(['ok' => empty($this->errors), 'data' => $data]);
    }
}
