<?php
declare(strict_types=1);

/**
 * Illustration de campagne — validation et stockage (PHP 8.1+, sans dépendance ;
 * GD utilisé s'il est là, pour la vignette uniquement).
 *
 * Un champ « photo » dans un formulaire est un point d'entrée : ce qui arrive
 * est un fichier choisi par un utilisateur, dont ni le nom, ni l'extension, ni
 * le type MIME annoncé ne sont dignes de confiance. Cette classe applique les
 * quatre règles qui comptent :
 *
 *   1. Le type se DÉDUIT du contenu (getimagesize), jamais de l'extension ni
 *      du `type` envoyé par le navigateur — les deux se falsifient à la main.
 *   2. Le nom de fichier est REGÉNÉRÉ. Un nom fourni par le client porte des
 *      « ../ », des octets nuls, ou une double extension `.jpg.php`.
 *   3. Les dimensions sont bornées avant tout traitement. Une image de
 *      64 000 × 64 000 pèse 12 ko sur le disque et 12 Go décompressée.
 *   4. SVG est refusé par défaut : c'est un document XML, il exécute du script
 *      dans le navigateur. À n'autoriser que servi en pièce jointe, jamais
 *      inline. (`allow_svg` existe, mais lisez la note avant de l'activer.)
 *
 * Le dossier de destination doit être HORS webroot, ou servi par un dossier
 * sans exécution PHP (`php_admin_flag engine off`, ou un `location` nginx qui
 * ne passe rien à FPM). Sinon la règle 2 est la seule chose qui vous protège.
 */
final class CampaignImage
{
    /** Types acceptés : constante IMAGETYPE_* => [mime, extension]. */
    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG  => ['image/png',  'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF  => ['image/gif',  'gif'],
    ];

    private string $dir;
    private string $publicBase;
    private int    $maxBytes;
    private int    $maxPixels;
    private int    $minWidth;
    private bool   $allowSvg;

    /**
     * @param array{
     *   dir: string,                 dossier de stockage (créé si absent)
     *   public_base?: string,        préfixe d'URL publique ('' = pas d'URL directe)
     *   max_bytes?: int,             défaut 5 Mo
     *   max_pixels?: int,            défaut 40 Mpx (bombe de décompression)
     *   min_width?: int,             défaut 600 px — un bandeau flou est un bug visuel
     *   allow_svg?: bool             défaut false, voir la note dans l'en-tête
     * } $opts
     */
    public function __construct(array $opts)
    {
        $this->dir        = rtrim($opts['dir'], '/');
        $this->publicBase = rtrim($opts['public_base'] ?? '', '/');
        $this->maxBytes   = (int)($opts['max_bytes'] ?? 5 * 1024 * 1024);
        $this->maxPixels  = (int)($opts['max_pixels'] ?? 40_000_000);
        $this->minWidth   = (int)($opts['min_width'] ?? 600);
        $this->allowSvg   = (bool)($opts['allow_svg'] ?? false);
    }

    /**
     * Valide un fichier de `$_FILES` SANS le stocker. À appeler pour afficher
     * une erreur de formulaire avant d'écrire quoi que ce soit.
     *
     * @param array $file une entrée de $_FILES
     * @return array{ok: bool, error: ?string, mime: ?string, width: ?int, height: ?int, ext: ?string}
     */
    public function validate(array $file): array
    {
        $ko = static fn(string $e) => ['ok' => false, 'error' => $e, 'mime' => null, 'width' => null, 'height' => null, 'ext' => null];

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return $ko('no_file');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            // Fréquent et invisible : upload_max_filesize / post_max_size côté
            // serveur, plus bas que la limite annoncée dans le formulaire.
            return $ko('too_large_server_limit');
        }
        if ($err !== UPLOAD_ERR_OK) {
            return $ko('upload_error_' . $err);
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return $ko('unreadable');
        }
        // Refuse un chemin arbitraire présenté comme un upload.
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            return $ko('not_an_upload');
        }

        $size = (int)($file['size'] ?? filesize($tmp) ?: 0);
        if ($size <= 0) {
            return $ko('empty');
        }
        if ($size > $this->maxBytes) {
            return $ko('too_large');
        }

        // Le type vient du CONTENU. `$file['type']` est déclaratif : ignoré.
        $info = @getimagesize($tmp);
        if ($info === false) {
            if ($this->allowSvg && $this->looksLikeSvg($tmp)) {
                return ['ok' => true, 'error' => null, 'mime' => 'image/svg+xml',
                        'width' => null, 'height' => null, 'ext' => 'svg'];
            }
            return $ko('not_an_image');
        }

        [$w, $h, $type] = [$info[0], $info[1], $info[2]];
        if (!isset(self::TYPES[$type])) {
            return $ko('unsupported_type');
        }
        if ($w * $h > $this->maxPixels) {
            return $ko('too_many_pixels');
        }
        if ($w < $this->minWidth) {
            return $ko('too_small');
        }

        [$mime, $ext] = self::TYPES[$type];
        return ['ok' => true, 'error' => null, 'mime' => $mime, 'width' => $w, 'height' => $h, 'ext' => $ext];
    }

    /**
     * Valide puis stocke. Le nom de fichier est régénéré (`aaaa/mm/<32 hex>.ext`)
     * — imprévisible, donc non énumérable, et rangé par mois pour qu'un dossier
     * ne finisse pas avec 200 000 entrées.
     *
     * @return array{ok: bool, error: ?string, path: ?string, url: ?string,
     *   mime: ?string, bytes: ?int, width: ?int, height: ?int}
     */
    public function store(array $file): array
    {
        $v = $this->validate($file);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error'], 'path' => null, 'url' => null,
                    'mime' => null, 'bytes' => null, 'width' => null, 'height' => null];
        }

        $rel = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $v['ext'];
        $abs = $this->dir . '/' . $rel;

        $sub = dirname($abs);
        if (!is_dir($sub) && !@mkdir($sub, 0755, true) && !is_dir($sub)) {
            return ['ok' => false, 'error' => 'mkdir_failed', 'path' => null, 'url' => null,
                    'mime' => null, 'bytes' => null, 'width' => null, 'height' => null];
        }

        $tmp = (string)$file['tmp_name'];
        $ok  = (PHP_SAPI !== 'cli' && is_uploaded_file($tmp))
            ? @move_uploaded_file($tmp, $abs)
            : (@rename($tmp, $abs) ?: @copy($tmp, $abs));
        if (!$ok) {
            return ['ok' => false, 'error' => 'move_failed', 'path' => null, 'url' => null,
                    'mime' => null, 'bytes' => null, 'width' => null, 'height' => null];
        }
        @chmod($abs, 0644);

        return [
            'ok'     => true,
            'error'  => null,
            'path'   => $rel,                       // à mettre dans campaign.illustration_path
            'url'    => $this->url($rel),
            'mime'   => $v['mime'],
            'bytes'  => (int)filesize($abs),
            'width'  => $v['width'],
            'height' => $v['height'],
        ];
    }

    /**
     * Vignette carrée (GD requis). Rend le chemin relatif de la vignette, ou
     * null si GD est absent — la vignette est un confort, pas une dépendance :
     * l'appelant retombe sur l'image d'origine en CSS (`object-fit: cover`).
     */
    public function thumbnail(string $relPath, int $side = 480): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $src = $this->dir . '/' . $this->safeRel($relPath);
        if (!is_file($src)) {
            return null;
        }
        $info = @getimagesize($src);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            return null;
        }

        $im = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            default        => false,
        };
        if (!$im) {
            return null;
        }

        [$w, $h] = [imagesx($im), imagesy($im)];
        $c  = min($w, $h);                          // recadrage centré
        $dx = (int)(($w - $c) / 2);
        $dy = (int)(($h - $c) / 2);

        $out = imagecreatetruecolor($side, $side);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, $dx, $dy, $side, $side, $c, $c);

        $rel = preg_replace('/\.[a-z0-9]+$/i', '', $this->safeRel($relPath)) . '_t' . $side . '.jpg';
        $abs = $this->dir . '/' . $rel;
        $ok  = imagejpeg($out, $abs, 82);
        imagedestroy($out);
        imagedestroy($im);

        return $ok ? $rel : null;
    }

    /** URL publique d'un chemin relatif, ou null si le dossier n'est pas servi. */
    public function url(?string $relPath): ?string
    {
        if ($relPath === null || $relPath === '' || $this->publicBase === '') {
            return null;
        }
        return $this->publicBase . '/' . $this->safeRel($relPath);
    }

    /**
     * Supprime une illustration et sa vignette. Idempotent : un fichier déjà
     * absent n'est pas une erreur — le remplacement d'un visuel ne doit pas
     * échouer parce que l'ancien avait disparu.
     */
    public function delete(?string $relPath): bool
    {
        if ($relPath === null || $relPath === '') {
            return true;
        }
        $rel = $this->safeRel($relPath);
        $abs = $this->dir . '/' . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
        foreach (glob($this->dir . '/' . preg_replace('/\.[a-z0-9]+$/i', '', $rel) . '_t*.jpg') ?: [] as $t) {
            @unlink($t);
        }
        return true;
    }

    /**
     * `object-position` CSS depuis le point d'intérêt stocké — le même visuel
     * en bandeau 3:1 et en vignette carrée sans décapiter le sujet.
     */
    public static function focusCss(?float $x, ?float $y): string
    {
        $p = static fn(?float $v) => round(max(0.0, min(1.0, $v ?? 0.5)) * 100, 1);
        return $p($x) . '% ' . $p($y) . '%';
    }

    /** Messages d'erreur prêts à afficher (à traduire côté projet). */
    public static function errorMessage(?string $code): string
    {
        return match ($code) {
            'no_file'                => 'Aucun fichier sélectionné.',
            'too_large'              => 'Image trop lourde (5 Mo maximum).',
            'too_large_server_limit' => 'Image refusée par le serveur : elle dépasse la taille autorisée par sa configuration.',
            'not_an_image'           => "Ce fichier n'est pas une image.",
            'unsupported_type'       => 'Format non accepté — JPEG, PNG ou WebP.',
            'too_many_pixels'        => 'Image trop grande en pixels.',
            'too_small'              => 'Image trop petite : elle serait floue à l’affichage.',
            'empty'                  => 'Fichier vide.',
            'not_an_upload', 'unreadable' => 'Fichier illisible.',
            'mkdir_failed', 'move_failed' => 'Enregistrement impossible sur le serveur.',
            null                     => '',
            default                  => 'Image refusée (' . $code . ').',
        };
    }

    // ───────────────────────────── Interne ─────────────────────────────

    /** Neutralise toute traversée de dossier dans un chemin venu de la base. */
    private function safeRel(string $rel): string
    {
        $rel = str_replace(["\0", '\\'], ['', '/'], $rel);
        $out = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    private function looksLikeSvg(string $path): bool
    {
        $head = (string)@file_get_contents($path, false, null, 0, 1024);
        return $head !== '' && preg_match('/<\s*svg[\s>]/i', $head) === 1;
    }
}
