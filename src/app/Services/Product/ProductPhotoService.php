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
    /**
     * Un échantillon du catalogue : identifiant, nom, photo résolue.
     *
     * Sans lui, personne ne peut choisir un identifiant d'essai sans un jeton
     * et une ligne de curl — et tant qu'on n'a pas essayé sur un vrai produit,
     * on ne sait pas si les chemins `shop_photo_path` se résolvent contre la
     * bonne base. C'est la question ouverte de T14, et cet échantillon y répond
     * en une page.
     *
     * @return array{ok: bool, total?: int, avec_photo?: int, produits?: array, reason?: string}
     */
    public function echantillon(int $combien = 20): array
    {
        $this->diag = [];
        if (!$this->enabled()) {
            return ['ok' => false, 'reason' => 'désactivé'];
        }
        $catalogue = $this->products
            ->avecBasePhoto($this->params->getString('product_ref_photo_base', ''))
            ->all($this->params->getString('product_ref_endpoint', '/recipes'));
        $this->diag = $this->products->diagnostics();

        if ($catalogue === []) {
            return ['ok' => false, 'reason' => 'catalogue vide ou illisible'];
        }
        $avecPhoto = 0;
        foreach ($catalogue as $p) {
            if (($p['url'] ?? null) !== null || ($p['att'] ?? null) !== null) {
                $avecPhoto++;
            }
        }
        // Les produits AVEC photo d'abord : ce sont les seuls qui permettent
        // d'éprouver la comparaison.
        usort($catalogue, static fn ($a, $b) =>
            (int)(($b['url'] ?? null) !== null) <=> (int)(($a['url'] ?? null) !== null));

        return [
            'ok'         => true,
            'total'      => count($catalogue),
            'avec_photo' => $avecPhoto,
            'produits'   => array_slice(array_map(static fn ($p) => [
                'id'    => $p['id'] ?? null,
                'nom'   => $p['name'] ?? null,
                'photo' => $p['url'] ?? null,
                'att'   => $p['att'] ?? null,
            ], $catalogue), 0, max(1, min($combien, 100))),
        ];
    }

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

        $catalogue = $this->products
            ->avecBasePhoto($this->params->getString('product_ref_photo_base', ''))
            ->all($this->params->getString('product_ref_endpoint', '/recipes'));
        if ($catalogue === []) {
            return ['found' => false, 'reason' => 'catalogue produits vide ou illisible'];
        }

        foreach ($catalogue as $p) {
            if ($p['id'] === $id) {
                $this->diag['matched'] = $p['name'];
                $out = [
                    'found'  => true,
                    'id'     => $id,
                    'name'   => $p['name'],
                    'url'    => $p['url'],
                    'att'    => $p['att'],
                    'source' => 'fiche',
                ];
                // Fiche technique SANS visuel : la photo produit du webshop
                // (même identifiant ERP) prend le relais — et la modale dit
                // d'où vient l'image. Vérifié le 23/08 sur « Ebly à la
                // niçoise » (2130010) : fiche sans visuel, webshop fourni.
                if ($out['url'] === null && $out['att'] === null) {
                    $ws = $this->webshopPhotoUrl($id);
                    if ($ws !== null) {
                        $out['url']    = $ws;
                        $out['source'] = 'webshop';
                        $this->diag['webshop_photo'] = $ws;
                    }
                }
                return $out;
            }
        }

        // Un identifiant que le catalogue ne connaît pas : produit retiré, ou
        // catalogue tronqué (pagination). Les deux se corrigent, à condition
        // de les distinguer d'une tâche sans produit — d'où ce motif à part.
        // La photo webshop, elle, reste tentée : même id ERP, et un contrôle
        // qualité sans référence est pire qu'une référence sans libellé.
        $this->diag['catalogue_size'] = count($catalogue);
        $ws = $this->webshopPhotoUrl($id);
        if ($ws !== null) {
            $this->diag['webshop_photo'] = $ws;
            return ['found' => true, 'id' => $id, 'name' => null,
                    'url' => $ws, 'att' => null, 'source' => 'webshop'];
        }
        return ['found' => false, 'reason' => 'produit ' . $id . ' absent du catalogue'];
    }

    /**
     * La photo produit du webshop pour un id ERP, si le fichier existe.
     *
     * Le webshop range ses photos (synchronisées depuis l'ERP) en
     * assets/product_pictures/<id>.<ext> sur CE serveur — on vérifie le
     * fichier sur disque plutôt que de servir une URL peut-être morte.
     * Répertoire et base URL réglables (autre hébergement) via params.
     */
    private function webshopPhotoUrl(int $id): ?string
    {
        $dir  = rtrim($this->params->getString(
            'product_ref_webshop_dir', '/var/www/html/webshop/assets/product_pictures'), '/');
        $base = rtrim($this->params->getString(
            'product_ref_webshop_base', '/webshop/assets/product_pictures'), '/');
        if ($dir === '' || $base === '') {
            return null;
        }
        foreach (glob($dir . '/' . $id . '.*') ?: [] as $f) {
            if (preg_match('/\.(png|jpe?g|webp)$/i', $f)) {
                return $base . '/' . basename($f);
            }
        }
        return null;
    }
}
