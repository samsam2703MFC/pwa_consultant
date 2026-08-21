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

    /** Base des chemins de photo, réglée par le service (paramètre en base). */
    private string $baseReglee = '';

    /** Base résolue, calculée une fois. */
    private ?string $photoBase = null;

    /**
     * La base contre laquelle résoudre `shop_photo_path`.
     *
     * Vide = on retombe sur l'origine de l'API. Le réglage existe parce que
     * rien ne dit que les médias vivent sur le même hôte que l'API, et qu'une
     * mauvaise base donne une image morte sans le moindre message.
     */
    public function avecBasePhoto(string $base): self
    {
        $this->baseReglee = $base;
        $this->photoBase = null;
        return $this;
    }

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
     * Une URL d'image utilisable.
     *
     * `shop_photo_path` est la clé de l'API — et c'est un CHEMIN, pas une URL :
     * il est résolu contre une base réglable (`product_ref_photo_base`), vide
     * par défaut, auquel cas on retombe sur l'origine de l'API. Deviner cette
     * base afficherait une image morte sans rien dire ; la régler est une ligne
     * de paramètre, pas un déploiement.
     *
     * Les autres orthographes restent acceptées : le panel a servi plusieurs
     * formes avant que l'API ne tranche, et une base plus ancienne ne doit pas
     * perdre sa photo au passage.
     */
    private function premierUrl(array $r): ?string
    {
        $cles = ['shop_photo_path', 'photo_path', 'image_path',
                 'photo_url', 'image_url', 'picture_url', 'thumbnail_url', 'url',
                 'photo', 'image', 'picture', 'thumbnail', 'media', 'cover'];
        foreach ($cles as $k) {
            $v = $r[$k] ?? null;
            if (is_string($v) && $v !== '') {
                $abs = $this->absolutiser($v);
                if ($abs !== null) {
                    return $abs;
                }
            }
            if (is_array($v)) {
                foreach (['url', 'src', 'href', 'path', 'link', 'shop_photo_path'] as $sk) {
                    if (is_string($v[$sk] ?? null) && $v[$sk] !== '') {
                        $abs = $this->absolutiser($v[$sk]);
                        if ($abs !== null) {
                            return $abs;
                        }
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

    /**
     * Un chemin d'image devient une URL, ou rien.
     *
     * Déjà absolue → rendue telle quelle. Sinon on la résout contre la base
     * réglée, à défaut contre l'origine de l'API — `API_BASE_URL` finit par
     * `/api/v1`, qu'on retire : un chemin de média ne vit pas sous le préfixe
     * de l'API.
     *
     * Rend null pour ce qui ne ressemble à aucun des deux, plutôt que de
     * fabriquer une URL qui ne mènera nulle part.
     */
    private function absolutiser(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        // Une valeur sans extension ni séparateur n'est pas un chemin d'image :
        // c'est plus probablement un libellé qu'on transformerait en lien mort.
        if (!str_contains($v, '/') && !preg_match('#\.(jpe?g|png|webp|gif|avif)$#i', $v)) {
            return null;
        }
        $base = $this->photoBase();
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '/' . ltrim($v, '/');
    }

    /** La base des chemins de photo : réglage d'abord, origine de l'API sinon. */
    private function photoBase(): string
    {
        if ($this->photoBase !== null) {
            return $this->photoBase;
        }
        $regle = trim($this->baseReglee);
        if ($regle !== '') {
            return $this->photoBase = $regle;
        }
        if (!defined('API_BASE_URL')) {
            return $this->photoBase = '';
        }
        $parts = parse_url((string)API_BASE_URL);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $this->photoBase = '';
        }
        return $this->photoBase = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
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
