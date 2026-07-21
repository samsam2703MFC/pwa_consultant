<?php
namespace App\Consultant\app\Http\Controllers\Claim;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Services\Claim\ClaimService;
use App\Consultant\app\Services\Shop\ShopService;

class ClaimController extends Controller
{
    public function __construct(
        private ClaimService $claimService,
        private ShopService $shopService
    ) {}

    /**
     * GET /claims?shop_id=123&status=NEW
     * Lista reklamacji sklepu od wszystkich dostawcow.
     */
    public function index(): void
    {
        $shops = $this->shopService->getAllShops();
        $selectedShop = $_GET['shop_id'] ?? null;

        if (!$selectedShop && !empty($shops)) {
            $selectedShop = (string)($shops[0]['id'] ?? '');
        }

        // Niveau global : toutes les réclamations de TOUS les magasins —
        // sert aux badges globaux (au-dessus du sélecteur de magasin).
        $allClaims    = $this->claimService->getClaimsForAllShops($shops);
        $globalCounts = $this->claimService->countByStatus($allClaims);

        if ($selectedShop === 'all') {
            $claims = $allClaims;
        } else {
            $shopId = $selectedShop !== null ? (int)$selectedShop : null;
            $claims = $this->claimService->getClaimsForShop($shopId);
        }

        // Niveau magasin : compteurs sur la sélection courante (le filtrage
        // se fait côté client via les badges), données réelles.
        $statusCounts = $this->claimService->countByStatus($claims);

        // Filtre initial optionnel via ?status= (badge pré-activé côté client).
        $activeStatus = strtoupper((string)($_GET['status'] ?? ''));
        if (!array_key_exists($activeStatus, $statusCounts)) {
            $activeStatus = '';
        }

        $this->view('claim/index', [
            'shops' => $shops,
            'claims' => $claims,
            'selected_shop_id' => $selectedShop,
            'status_counts' => $statusCounts,
            'status_order' => array_keys($statusCounts),
            'global_status_counts' => $globalCounts,
            'global_status_order' => array_keys($globalCounts),
            'active_status' => $activeStatus,
            'active_nav' => 'claims',
        ]);
    }

    public function previewAttachment(int $attachmentId): void
    {
        $url = $this->claimService->getAttachmentPreviewUrl($attachmentId);
        if (!$url) {
            $this->view('errors/404', ['active_nav' => 'claims']);
            return;
        }

        header('Location: ' . $url);
        exit;
    }
}
