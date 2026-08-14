<?php
namespace App\Consultant\app\Services\Product;

use App\Consultant\app\Repositories\Product\ProductRepository;
use App\Consultant\app\Services\Param\ParamService;

/**
 * La photo de la fiche technique, en face de la photo prise en boutique.
 *
 * « Contrôle qualité – Salade Grecque » : le consultant juge une assiette sans
 * rien à quoi la comparer, donc de mémoire. Ce service rend le visuel de la
 * fiche du produit contrôlé, pour que le contrôle devienne une comparaison.
 *
 * UNIQUEMENT PAR IDENTIFIANT. La tâche porte un `product_id`, et c'est la
 * seule clé utilisée : jamais l'intitulé. Rapprocher sur le nom paraît
 * commode et ne l'est pas — « Salade » et « Grecque » collent tous deux à
 * « Salade Grecque », et le mauvais visuel ferait refuser un produit correct
 * avec l'air d'une preuve. Pas d'identifiant sur la tâche : pas de référence,
 * et le diagnostic le dit.
 */
class ProductPhotoService
{
    public function __construct(
        private ProductRepository $products,
        private ParamService $params,
    ) {}

    private array $diag = [];

    public function diagnostics(): array
    {
        return $this->diag + $this->products->diagnostics();
    }

    public function enabled(): bool
    {
        return $this->params->getInt('product_ref_enabled', 1) === 1;
    }

    /**
     * @return array{found:bool, id?:int, name?:string, url?:?string, att?:?int, reason?:string}
     */
    public function forProductId(int $id): array
    {
        $this->diag = ['product_id' => $id];
        if (!$this->enabled()) {
            return ['found' => false, 'reason' => 'désactivé'];
        }
        if ($id <= 0) {
            // Ce n'est pas une panne : la plupart des tâches ne portent sur
            // aucun produit (« Nettoyage du sol »).
            return ['found' => false, 'reason' => 'la tâche ne porte pas de product_id'];
        }

        $catalogue = $this->products->all($this->params->getString('product_ref_endpoint', '/products'));
        if ($catalogue === []) {
            return ['found' => false, 'reason' => 'catalogue produits vide ou illisible'];
        }

        foreach ($catalogue as $p) {
            if ($p['id'] === $id) {
                $this->diag['matched'] = $p['name'];
                return [
                    'found' => true,
                    'id'    => $id,
                    'name'  => $p['name'],
                    'url'   => $p['url'],
                    'att'   => $p['att'],
                ];
            }
        }

        // Un identifiant que le catalogue ne connaît pas : produit retiré, ou
        // catalogue tronqué (pagination). Les deux se corrigent, à condition
        // de les distinguer d'une tâche sans produit — d'où ce motif à part.
        $this->diag['catalogue_size'] = count($catalogue);
        return ['found' => false, 'reason' => 'produit ' . $id . ' absent du catalogue'];
    }
}
