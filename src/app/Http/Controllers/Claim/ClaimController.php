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
        $status = strtoupper((string)($_GET['status'] ?? 'NEW'));
        $allowedStatuses = ['NEW', 'IN_REVIEW', 'ACCEPTED', 'REJECTED', 'ALL'];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'NEW';
        }

        if (!$selectedShop && !empty($shops)) {
            $selectedShop = (string)($shops[0]['id'] ?? '');
        }

        if ($selectedShop === 'all') {
            $claims = $this->claimService->getClaimsForAllShops($shops);
        } else {
            $shopId = $selectedShop !== null ? (int)$selectedShop : null;
            $claims = $this->claimService->getClaimsForShop($shopId);
        }

        $claims = $this->claimService->filterByStatus($claims, $status);

        $this->view('claim/index', [
            'shops' => $shops,
            'claims' => $claims,
            'selected_shop_id' => $selectedShop,
            'selected_status' => $status,
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
