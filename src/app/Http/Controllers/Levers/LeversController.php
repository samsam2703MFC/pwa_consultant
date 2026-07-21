<?php
namespace App\Consultant\app\Http\Controllers\Levers;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\app\Services\Shop\ShopService;

/**
 * 6L — les 6 leviers de gestion.
 *
 * Un levier par ligne, chacun avec ses 3 KPI clés (valeur magasin vs
 * moyenne réseau) et un statut ✓ / ⚠ / ● ; comparateur magasins × leviers
 * en bas de page. Les valeurs sont chargées côté client depuis les mêmes
 * endpoints que Boutiques/Accueil (P&L + KPI de vente, cache serveur) —
 * une seule source de vérité. Les couleurs officielles des leviers
 * viennent de la table transversale of_tag (repli sur la palette locale).
 */
class LeversController extends Controller
{
    /**
     * Seuils de statut vs moyenne réseau, en % RELATIFS (configurables) :
     *   score = sens × (valeur − moyenne) / |moyenne| × 100
     *   (sens = −1 pour les KPI où plus bas = mieux : food cost, labour…)
     *   ✓ bon    : score ≥ THRESHOLD_GOOD
     *   ⚠ moyen  : THRESHOLD_DANGER ≤ score < THRESHOLD_GOOD
     *   ● danger : score < THRESHOLD_DANGER
     */
    private const THRESHOLD_GOOD   = -5.0;
    private const THRESHOLD_DANGER = -15.0;

    public function __construct(
        private ShopService $shopService,
        private ConsultantUserRepository $consultantUsers,
    ) {}

    public function index(): void
    {
        $this->view('levers/index', [
            'shops'      => $this->shopService->getAllShops(),
            'of_tags'    => $this->consultantUsers->getOfficialTags(),
            'thresholds' => ['good' => self::THRESHOLD_GOOD, 'danger' => self::THRESHOLD_DANGER],
            'active_nav' => 'sixl',
        ]);
    }
}
