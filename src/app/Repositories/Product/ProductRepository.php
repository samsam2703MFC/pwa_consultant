<?php
namespace App\Consultant\app\Repositories\Product;

use App\Consultant\core\Http\ApiClient;

/**
 * Le catalogue produits (GET /products), pour la photo de la fiche technique.
 *
 * PAYLOAD INCONNU À L'ÉCRITURE. Le panel n'appelait aucun endpoint produit
 * jusqu'ici : ni le nom des clés, ni la forme de l'enveloppe ne sont
 * documentés. Plutôt que de parier sur une orthographe et de rendre un écran
 * muet le jour où elle diffère, on lit TOLÉRANT — plusieurs noms possibles
 * pour chaque champ — et on expose ce qu'on a trouvé (?debug=1) pour qu'un
 * désaccord se voie en trois secondes au lieu de se chercher une matinée.
 *
 * Le catalogue est de la donnée de RÉFÉRENCE : il ne bouge pas dans la
 * journée. Un seul appel sert toute la session (cache de l'ApiClient).
 */
class ProductRepository
{
    public function __construct(private ApiClient $apiClient) {}

    /** Trace du dernier appel — jamais le jeton, jamais la charge entière. */
    private array $debug = [];

    public function diagnostics(): array
    {
        return $this->debug;
    }

    /**
     * Le catalogue, normalisé.
     *
     * @return array<int, array{id:?int, name:string, url:?string, att:?int}>
     */
    public function all(string $endpoint = '/products'): array
    {
        $res = $this->apiClient->get($endpoint);
        $this->debug = ['endpoint' => $endpoint, 'success' => (bool)($res['success'] ?? false)];

        if (empty($res['success'])) {
            $this->debug['error'] = $res['error'] ?? 'appel refusé';
            return [];
        }

        $lignes = $this->lignes($res['data'] ?? null);
        $this->debug['rows'] = count($lignes);
        if ($lignes === []) {
            // Une enveloppe qu'on n'a pas su ouvrir : on montre ses clés, c'est
            // ce qui manque pour corriger.
            $this->debug['payload_keys'] = is_array($res['data'] ?? null)
                ? array_slice(array_keys($res['data']), 0, 12) : [];
            return [];
        }
        $this->debug['row_keys'] = array_slice(array_keys($lignes[0]), 0, 20);

        $out = [];
        $avecPhoto = 0;
        foreach ($lignes as $r) {
            $nom = $this->premier($r, ['name', 'product_name', 'label', 'title', 'nom', 'designation']);
            if ($nom === null || trim($nom) === '') {
                continue;                      // sans nom, rien à rapprocher
            }
            $url = $this->premierUrl($r);
            $att = $this->premierAtt($r);
            if ($url !== null || $att !== null) {
                $avecPhoto++;
            }
            $id = $this->premier($r, ['id', 'product_id', 'id_product']);
            $out[] = [
                'id'   => is_numeric($id) ? (int)$id : null,
                'name' => trim((string)$nom),
                'url'  => $url,
                'att'  => $att,
            ];
        }
        $this->debug['named'] = count($out);
        $this->debug['with_photo'] = $avecPhoto;

        return $out;
    }

    /**
     * La liste, quelle que soit l'enveloppe : liste nue, {data:…},
     * {products:…}, {items:…}, {results:…}.
     *
     * @return array<int, array>
     */
    private function lignes(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['data', 'products', 'items', 'results', 'rows', 'content'] as $k) {
            if (is_array($data[$k] ?? null)) {
                // Une enveloppe peut en cacher une autre ({data:{items:[…]}}).
                $sous = $this->lignes($data[$k]);
                if ($sous !== []) {
                    return $sous;
                }
            }
        }
        return [];
    }

    /** La première clé présente, parmi plusieurs orthographes possibles. */
    private function premier(array $r, array $cles): mixed
    {
        foreach ($cles as $k) {
            if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) {
                return $r[$k];
            }
        }
        return null;
    }

    /**
     * Une URL d'image directement utilisable. Les payloads mettent parfois
     * l'image sous un objet ({photo:{url:…}}) ou sous une liste d'images.
     */
    private function premierUrl(array $r): ?string
    {
        $cles = ['photo_url', 'image_url', 'picture_url', 'thumbnail_url', 'url',
                 'photo', 'image', 'picture', 'thumbnail', 'media', 'cover'];
        foreach ($cles as $k) {
            $v = $r[$k] ?? null;
            if (is_string($v) && preg_match('#^https?://#i', $v)) {
                return $v;
            }
            if (is_array($v)) {
                foreach (['url', 'src', 'href', 'path', 'link'] as $sk) {
                    if (is_string($v[$sk] ?? null) && preg_match('#^https?://#i', $v[$sk])) {
                        return $v[$sk];
                    }
                }
                // Liste d'images : la première suffit — c'est la fiche, pas une galerie.
                if (array_is_list($v) && isset($v[0])) {
                    if (is_string($v[0]) && preg_match('#^https?://#i', $v[0])) {
                        return $v[0];
                    }
                    if (is_array($v[0])) {
                        foreach (['url', 'src', 'href'] as $sk) {
                            if (is_string($v[0][$sk] ?? null) && preg_match('#^https?://#i', $v[0][$sk])) {
                                return $v[0][$sk];
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    /** À défaut d'URL : un identifiant de pièce jointe, à signer comme les autres. */
    private function premierAtt(array $r): ?int
    {
        foreach (['attachment_id', 'photo_id', 'image_id', 'picture_id', 'file_id', 'media_id'] as $k) {
            if (is_numeric($r[$k] ?? null) && (int)$r[$k] > 0) {
                return (int)$r[$k];
            }
        }
        foreach (['photo', 'image', 'attachment'] as $k) {
            $v = $r[$k] ?? null;
            if (is_array($v) && is_numeric($v['id'] ?? null) && (int)$v['id'] > 0) {
                return (int)$v['id'];
            }
        }
        return null;
    }
}
