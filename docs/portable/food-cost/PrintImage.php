<?php
declare(strict_types=1);

/**
 * Traitement automatique des images destinées à l'IMPRESSION — brochures,
 * flyers et cartes jusqu'à 10 × 15 cm (PHP 8.1+, GD requis, Imagick utilisé
 * s'il est là pour le CMJN).
 *
 * Pour le web, ce qui compte est le POIDS du fichier. Pour l'impression, c'est
 * le nombre de pixels PAR CENTIMÈTRE : une image de 800 px de large est
 * confortable à l'écran et floue dès 7 cm de papier. Tout ce fichier tourne
 * autour de ce calcul :
 *
 *     pixels = cm ÷ 2,54 × dpi          10 × 15 cm à 300 dpi = 1181 × 1772 px
 *     avec 3 mm de fond perdu           10,6 × 15,6 cm       = 1252 × 1843 px
 *
 * Ce que le traitement fait, dans l'ordre :
 *   1. lit l'image (orientation EXIF appliquée) ;
 *   2. choisit portrait ou paysage selon la source, si `orientation: auto` —
 *      forcer le portrait sur une photo paysage jette 60 % de l'image ;
 *   3. recadre au format cible autour du POINT D'INTÉRÊT (le même que celui du
 *      formulaire campagne) ;
 *   4. rééchantillonne à la taille exacte, fond perdu compris ;
 *   5. ré-accentue légèrement — toute réduction ramollit une image, et ça se
 *      voit sur papier ;
 *   6. écrit la RÉSOLUTION dans le fichier (JFIF), pour qu'InDesign ou Word
 *      place l'image à 10 × 15 cm et pas à 42 cm ;
 *   7. rend un VERDICT : la résolution réellement obtenue, et si elle suffit.
 *
 * Le verdict est le cœur du sujet. Agrandir une image n'invente pas de détail :
 * une source de 600 px étirée à 1181 px sera floue à l'impression, et le seul
 * service à rendre est de le dire AVANT le bon à tirer, pas après.
 *
 * En ligne de commande, sur un dossier entier :
 *   php PrintImage.php --in=photos --out=print --size=10x15 --dpi=300
 */
final class PrintImage
{
    private const MM_PER_INCH = 25.4;
    private const CM_PER_INCH = 2.54;

    private int    $dpi;
    private int    $minDpi;
    private float  $bleedMm;
    private float  $safeMm;
    private int    $quality;
    private bool   $sharpen;
    private bool   $allowUpscale;
    private array  $background;

    /**
     * @param array{
     *   dpi?: int,             résolution cible, défaut 300 (norme offset)
     *   min_dpi?: int,         en dessous : verdict « insuffisant », défaut 150
     *   bleed_mm?: float,      fond perdu par bord, défaut 3 mm (0 = sans)
     *   safe_mm?: float,       marge de sécurité indiquée dans le rapport, défaut 4 mm
     *   quality?: int,         qualité JPEG, défaut 92 (l'impression n'est pas le web)
     *   sharpen?: bool,        ré-accentuation après réduction, défaut true
     *   allow_upscale?: bool,  défaut true — toujours signalé dans le verdict
     *   background?: array      RVB du fond en mode `contain`, défaut blanc
     * } $opts
     */
    public function __construct(array $opts = [])
    {
        $this->dpi          = (int)($opts['dpi'] ?? 300);
        $this->minDpi       = (int)($opts['min_dpi'] ?? 150);
        $this->bleedMm      = (float)($opts['bleed_mm'] ?? 3.0);
        $this->safeMm       = (float)($opts['safe_mm'] ?? 4.0);
        $this->quality      = (int)($opts['quality'] ?? 92);
        $this->sharpen      = (bool)($opts['sharpen'] ?? true);
        $this->allowUpscale = (bool)($opts['allow_upscale'] ?? true);
        $this->background   = $opts['background'] ?? [255, 255, 255];
    }

    // ───────────────────────── Calculs de format ─────────────────────────

    /** Pixels nécessaires pour `$cm` centimètres à `$dpi`. */
    public static function pxForCm(float $cm, int $dpi): int
    {
        return (int)ceil($cm / self::CM_PER_INCH * $dpi);
    }

    /** Résolution réelle qu'on obtient en imprimant `$px` pixels sur `$cm`. */
    public static function dpiForPx(int $px, float $cm): float
    {
        return $cm > 0 ? $px / ($cm / self::CM_PER_INCH) : 0.0;
    }

    /**
     * Ce qu'une image PEUT donner, sans rien écrire. À appeler avant un bon à
     * tirer, ou pour trier un dossier de photos fournies par un franchisé.
     *
     * @return array{ok: bool, error: ?string, width: ?int, height: ?int,
     *   orientation: ?string, max_dpi: ?float, verdict: ?string,
     *   max_cm: ?array{0: float, 1: float}, message: string}
     */
    public function inspect(string $path, float $widthCm = 10.0, float $heightCm = 15.0): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return ['ok' => false, 'error' => 'not_an_image', 'width' => null, 'height' => null,
                    'orientation' => null, 'max_dpi' => null, 'verdict' => null, 'max_cm' => null,
                    'message' => "Ce fichier n'est pas une image."];
        }
        [$w, $h] = [$info[0], $info[1]];
        if (self::exifSwapsAxes($path)) {
            [$w, $h] = [$h, $w];
        }

        // Orientation retenue : celle qui recadre le moins.
        $portrait = $this->pickPortrait($w, $h, $widthCm, $heightCm);
        [$tw, $th] = $portrait ? [$widthCm, $heightCm] : [$heightCm, $widthCm];
        [$bw, $bh] = $this->withBleed($tw, $th);

        // Le recadrage « cover » consomme la plus contraignante des deux
        // dimensions : c'est elle qui fixe la résolution atteignable.
        $scale   = max($bw / $w, $bh / $h) > 0 ? min($w / $bw, $h / $bh) : 0;
        $maxDpi  = min(self::dpiForPx((int)round($w), $bw), self::dpiForPx((int)round($h), $bh));
        $verdict = $this->verdict($maxDpi);

        return [
            'ok'          => true,
            'error'       => null,
            'width'       => $w,
            'height'      => $h,
            'orientation' => $portrait ? 'portrait' : 'paysage',
            'max_dpi'     => round($maxDpi, 1),
            'verdict'     => $verdict,
            // Le plus grand format imprimable à la résolution cible.
            'max_cm'      => [
                round($w / $this->dpi * self::CM_PER_INCH, 1),
                round($h / $this->dpi * self::CM_PER_INCH, 1),
            ],
            'message'     => $this->verdictMessage($verdict, $maxDpi, $tw, $th),
        ];
    }

    // ───────────────────────── Traitement ─────────────────────────

    /**
     * Traite UNE image et écrit le fichier prêt pour l'impression.
     *
     * @param array{
     *   width_cm?: float, height_cm?: float,   défaut 10 × 15
     *   orientation?: string,                  'auto' (défaut) | 'portrait' | 'paysage'
     *   focus?: array{0: float, 1: float},     point d'intérêt 0..1, défaut centre
     *   fit?: string,                          'cover' (défaut, recadre) | 'contain' (entière, fond uni)
     *   format?: string,                       'jpg' (défaut) | 'png'
     *   bleed_mm?: float                       surcharge le fond perdu
     * } $opts
     * @return array{ok: bool, error: ?string, path: ?string, width: ?int, height: ?int,
     *   dpi: ?int, effective_dpi: ?float, verdict: ?string, upscaled: ?bool,
     *   trim_px: ?array, bleed_px: ?int, safe_px: ?int, messages: list<string>}
     */
    public function process(string $src, string $dest, array $opts = []): array
    {
        $ko = static fn(string $e, string $m) => [
            'ok' => false, 'error' => $e, 'path' => null, 'width' => null, 'height' => null,
            'dpi' => null, 'effective_dpi' => null, 'verdict' => null, 'upscaled' => null,
            'trim_px' => null, 'bleed_px' => null, 'safe_px' => null, 'messages' => [$m],
        ];

        $widthCm  = (float)($opts['width_cm'] ?? 10.0);
        $heightCm = (float)($opts['height_cm'] ?? 15.0);
        $fit      = $opts['fit'] ?? 'cover';
        $format   = strtolower($opts['format'] ?? 'jpg');
        $bleedMm  = (float)($opts['bleed_mm'] ?? $this->bleedMm);
        $focus    = $opts['focus'] ?? [0.5, 0.5];

        $info = @getimagesize($src);
        if ($info === false) {
            return $ko('not_an_image', "Ce fichier n'est pas une image.");
        }
        if (($info['channels'] ?? 3) === 4 && $info[2] === IMAGETYPE_JPEG) {
            // GD ne lit pas les JPEG CMJN : mieux vaut le dire que rendre une
            // image aux couleurs inversées sans prévenir.
            return $ko('cmyk_source', 'Source en CMJN : à convertir en RVB avant traitement (GD ne la lit pas).');
        }

        $im = $this->load($src, $info[2]);
        if (!$im) {
            return $ko('unsupported_type', 'Format non pris en charge (JPEG, PNG, WebP, GIF).');
        }
        $im = $this->applyExifOrientation($im, $src);

        [$w, $h] = [imagesx($im), imagesy($im)];

        // 1. Orientation cible.
        $orientation = $opts['orientation'] ?? 'auto';
        $portrait = $orientation === 'auto'
            ? $this->pickPortrait($w, $h, $widthCm, $heightCm)
            : ($orientation === 'portrait');
        [$tw, $th] = $portrait ? [$widthCm, $heightCm] : [$heightCm, $widthCm];

        // 2. Dimensions finales, fond perdu inclus.
        [$bwCm, $bhCm] = $this->withBleed($tw, $th, $bleedMm);
        $outW = self::pxForCm($bwCm, $this->dpi);
        $outH = self::pxForCm($bhCm, $this->dpi);

        $messages = [];
        $canvas   = imagecreatetruecolor($outW, $outH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $bg = imagecolorallocate($canvas, ...$this->background);
        imagefilledrectangle($canvas, 0, 0, $outW, $outH, $bg);
        imagealphablending($canvas, true);

        if ($fit === 'contain') {
            // L'image entière tient dans le format ; le reste est du fond uni.
            // Sans mise en garde : le fond perdu d'une image « contain » est du
            // fond, pas de l'image — c'est voulu pour un packshot, pas pour une
            // photo d'ambiance.
            $scale = min($outW / $w, $outH / $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            imagecopyresampled($canvas, $im, (int)(($outW - $nw) / 2), (int)(($outH - $nh) / 2), 0, 0, $nw, $nh, $w, $h);

            // En « contain » l'image n'occupe pas toute la page : le verdict doit
            // porter sur la taille qu'elle occupe VRAIMENT, sinon une image qui
            // s'imprime en 10 × 7,5 cm est jugée sur 10 × 15 et déclarée
            // insuffisante à tort.
            $srcCropW    = $w;
            $srcCropH    = $h;
            $renderScale = $scale;
            $printedWCm  = $nw / $this->dpi * self::CM_PER_INCH;
            $printedHCm  = $nh / $this->dpi * self::CM_PER_INCH;
            if ($bleedMm > 0) {
                $messages[] = 'Mode « contain » : le fond perdu est rempli de la couleur de fond, pas de l\'image.';
            }
        } else {
            // 3. Recadrage « cover » centré sur le point d'intérêt.
            $targetRatio = $outW / $outH;
            $srcRatio    = $w / $h;
            if ($srcRatio > $targetRatio) {           // source trop large → on rogne en largeur
                $srcCropH = $h;
                $srcCropW = (int)round($h * $targetRatio);
            } else {                                   // source trop haute → on rogne en hauteur
                $srcCropW = $w;
                $srcCropH = (int)round($w / $targetRatio);
            }
            $fx = min(1.0, max(0.0, (float)($focus[0] ?? 0.5)));
            $fy = min(1.0, max(0.0, (float)($focus[1] ?? 0.5)));
            $sx = (int)round($fx * $w - $srcCropW / 2);
            $sy = (int)round($fy * $h - $srcCropH / 2);
            $sx = max(0, min($w - $srcCropW, $sx));    // le cadre reste dans l'image
            $sy = max(0, min($h - $srcCropH, $sy));

            imagecopyresampled($canvas, $im, 0, 0, $sx, $sy, $outW, $outH, $srcCropW, $srcCropH);

            // Le verdict s'exprime sur le format FINI (après massicot), pas sur
            // le format + fond perdu : c'est « 10 × 15 » que l'utilisateur a demandé.
            $renderScale = $outW / max(1, $srcCropW);
            $printedWCm  = $tw;
            $printedHCm  = $th;

            $lost = 1 - ($srcCropW * $srcCropH) / ($w * $h);
            if ($lost > 0.35) {
                $messages[] = 'Recadrage important : ' . round($lost * 100) . ' % de l\'image d\'origine est perdu — vérifiez le point d\'intérêt.';
            }
        }
        imagedestroy($im);

        // 4. Verdict de résolution, calculé sur le rééchantillonnage RÉEL :
        //    facteur > 1 = agrandissement, et la résolution obtenue est la
        //    résolution cible divisée par ce facteur.
        $effDpi   = $this->dpi / max(1e-9, $renderScale);
        $verdict  = $this->verdict($effDpi);
        $upscaled = $renderScale > 1.001;

        if ($upscaled && !$this->allowUpscale) {
            imagedestroy($canvas);
            return $ko('too_low_resolution', $this->verdictMessage('insuffisant', $effDpi, $printedWCm, $printedHCm));
        }
        if ($upscaled) {
            $messages[] = 'Image agrandie de ' . round($srcCropW) . ' à ' . $outW . ' px : '
                . 'l\'agrandissement n\'ajoute aucun détail, il ne fait qu\'étaler celui qui existe.';
        }
        $messages[] = $this->verdictMessage($verdict, $effDpi, $printedWCm, $printedHCm);

        // 5. Ré-accentuation : toute réduction ramollit l'image.
        if ($this->sharpen && $renderScale < 0.87 && function_exists('imageconvolution')) {
            $this->unsharp($canvas);
        }

        // 6. Écriture + résolution inscrite dans le fichier.
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            return $ko('mkdir_failed', 'Dossier de sortie impossible à créer.');
        }

        $ok = $format === 'png'
            ? imagepng($canvas, $dest, 6)
            : imagejpeg($canvas, $dest, $this->quality);
        imagedestroy($canvas);
        if (!$ok) {
            return $ko('write_failed', 'Écriture du fichier impossible.');
        }
        if ($format !== 'png') {
            self::setJpegDpi($dest, $this->dpi);
        } else {
            $messages[] = 'PNG : la résolution n\'est pas inscrite dans le fichier — indiquez 10 × 15 cm à l\'import dans la maquette.';
        }

        $bleedPx = (int)round($bleedMm / self::MM_PER_INCH * $this->dpi);
        $safePx  = (int)round($this->safeMm / self::MM_PER_INCH * $this->dpi);

        return [
            'ok'            => true,
            'error'         => null,
            'path'          => $dest,
            'width'         => $outW,
            'height'        => $outH,
            'dpi'           => $this->dpi,
            'effective_dpi' => round($effDpi, 1),
            'verdict'       => $verdict,
            'upscaled'      => $upscaled,
            // Le format fini, une fois le fond perdu massicoté.
            'trim_px'       => [self::pxForCm($tw, $this->dpi), self::pxForCm($th, $this->dpi)],
            'bleed_px'      => $bleedPx,
            'safe_px'       => $safePx,
            'messages'      => $messages,
        ];
    }

    /**
     * Traite un DOSSIER entier. C'est le mode courant : une boutique envoie
     * quinze photos, il faut quinze fichiers prêts et la liste de celles qui
     * ne passeront pas.
     *
     * @return array{done: int, failed: int, results: array<string, array>}
     */
    public function batch(string $inDir, string $outDir, array $opts = []): array
    {
        $done = 0; $failed = 0; $results = [];
        $files = glob(rtrim($inDir, '/') . '/*.{jpg,jpeg,JPG,JPEG,png,PNG,webp,WEBP}', GLOB_BRACE) ?: [];
        sort($files);

        foreach ($files as $f) {
            $base = preg_replace('/\.[^.]+$/', '', basename($f));
            $ext  = ($opts['format'] ?? 'jpg') === 'png' ? 'png' : 'jpg';
            $dest = rtrim($outDir, '/') . '/' . $base . '_print.' . $ext;
            $r = $this->process($f, $dest, $opts);
            $results[basename($f)] = $r;
            $r['ok'] ? $done++ : $failed++;
        }
        return ['done' => $done, 'failed' => $failed, 'results' => $results];
    }

    // ───────────────────────── Métadonnées ─────────────────────────

    /**
     * Inscrit la résolution dans un JPEG (segment JFIF APP0). GD écrit toujours
     * 96 dpi ; sans cette correction, une image de 1181 px arrive dans InDesign
     * comme un objet de 31 cm de large, et il faut la redimensionner à la main
     * — le genre d'erreur qui passe en production.
     *
     * Structure JFIF : FFD8 | FFE0 | longueur(2) | "JFIF\0" | version(2) |
     *                  unités(1) | Xdensité(2) | Ydensité(2)
     */
    public static function setJpegDpi(string $path, int $dpi): bool
    {
        $fh = @fopen($path, 'r+b');
        if (!$fh) {
            return false;
        }
        $head = fread($fh, 18);
        if ($head === false || strlen($head) < 18
            || substr($head, 0, 2) !== "\xFF\xD8"          // SOI
            || substr($head, 2, 2) !== "\xFF\xE0"          // APP0
            || substr($head, 6, 5) !== "JFIF\x00") {
            fclose($fh);
            return false;
        }
        fseek($fh, 13);
        // unités = 1 (points par pouce), puis les deux densités en big-endian.
        $ok = fwrite($fh, "\x01" . pack('n', $dpi) . pack('n', $dpi)) === 5;
        fclose($fh);
        return $ok;
    }

    /** Résolution lue dans un JPEG, ou null. */
    public static function jpegDpi(string $path): ?int
    {
        $head = @file_get_contents($path, false, null, 0, 18);
        if ($head === false || strlen($head) < 18 || substr($head, 6, 5) !== "JFIF\x00") {
            return null;
        }
        $units = ord($head[13]);
        $x     = unpack('n', substr($head, 14, 2))[1];
        return match ($units) {
            1 => $x,                                   // points par pouce
            2 => (int)round($x * self::CM_PER_INCH),   // points par cm
            default => null,                           // ratio d'aspect seul
        };
    }

    // ───────────────────────── Interne ─────────────────────────

    /** Portrait ou paysage : on garde l'orientation qui recadre le moins. */
    private function pickPortrait(int $w, int $h, float $widthCm, float $heightCm): bool
    {
        if (abs($widthCm - $heightCm) < 0.01) {
            return true;                                // format carré : indifférent
        }
        $portraitRatio = $widthCm / $heightCm;
        $paysageRatio  = $heightCm / $widthCm;
        $srcRatio      = $w / max(1, $h);
        // Le format dont le rapport est le plus proche de la source rogne le moins.
        return abs(log($srcRatio / $portraitRatio)) <= abs(log($srcRatio / $paysageRatio));
    }

    /** @return array{0: float, 1: float} format en cm, fond perdu compris */
    private function withBleed(float $wCm, float $hCm, ?float $bleedMm = null): array
    {
        $b = ($bleedMm ?? $this->bleedMm) / 10.0;       // mm → cm, sur les deux bords
        return [$wCm + 2 * $b, $hCm + 2 * $b];
    }

    private function verdict(float $dpi): string
    {
        if ($dpi >= $this->dpi - 0.5)  return 'ok';
        if ($dpi >= $this->minDpi)     return 'limite';
        return 'insuffisant';
    }

    private function verdictMessage(string $verdict, float $dpi, float $wCm, float $hCm): string
    {
        $d  = round($dpi);
        $fm = rtrim(rtrim(number_format($wCm, 1, ',', ''), '0'), ',') . ' × '
            . rtrim(rtrim(number_format($hCm, 1, ',', ''), '0'), ',') . ' cm';
        return match ($verdict) {
            'ok'     => "Résolution suffisante : {$d} dpi en {$fm}.",
            'limite' => "Résolution juste : {$d} dpi en {$fm} — acceptable pour un aplat ou une photo d'ambiance, visible sur un texte ou un détail fin.",
            default  => "Résolution insuffisante : {$d} dpi en {$fm}, il en faut {$this->minDpi} au minimum. Demandez le fichier d'origine.",
        };
    }

    private function load(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default        => false,
        };
    }

    /** Vrai si l'EXIF échange largeur et hauteur (photo prise à la verticale). */
    private static function exifSwapsAxes(string $path): bool
    {
        if (!function_exists('exif_read_data')) {
            return false;
        }
        $e = @exif_read_data($path);
        return in_array((int)($e['Orientation'] ?? 1), [5, 6, 7, 8], true);
    }

    /** Applique l'orientation EXIF — sans quoi les photos verticales sortent couchées. */
    private function applyExifOrientation($im, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $im;
        }
        $e = @exif_read_data($path);
        $o = (int)($e['Orientation'] ?? 1);
        if ($o < 2 || $o > 8) {
            return $im;
        }
        $rot = match ($o) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if ($rot !== 0) {
            $r = imagerotate($im, $rot, 0);
            if ($r) { imagedestroy($im); $im = $r; }
        }
        if (in_array($o, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($im, IMG_FLIP_HORIZONTAL);
        }
        return $im;
    }

    /** Masque flou léger : compense le ramollissement dû à la réduction. */
    private function unsharp($im): void
    {
        $m = [[-1, -1, -1], [-1, 20, -1], [-1, -1, -1]];
        @imageconvolution($im, $m, 12, 0);
    }
}

// ───────────────────────── Ligne de commande ─────────────────────────
// php PrintImage.php --in=photos --out=print [--size=10x15] [--dpi=300]
//                    [--bleed=3] [--fit=cover|contain] [--format=jpg|png]
//                    [--orientation=auto|portrait|paysage] [--inspect]
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $args[$m[1]] = $m[2] ?? '1';
        }
    }
    if (empty($args['in'])) {
        fwrite(STDERR, "usage: php PrintImage.php --in=DOSSIER --out=DOSSIER [--size=10x15] [--dpi=300]\n"
                     . "                          [--bleed=3] [--fit=cover|contain] [--format=jpg|png]\n"
                     . "                          [--orientation=auto|portrait|paysage] [--inspect]\n");
        exit(2);
    }

    [$wCm, $hCm] = array_map('floatval', explode('x', str_replace(',', '.', $args['size'] ?? '10x15')) + [1 => 15]);
    $pi = new PrintImage([
        'dpi'      => (int)($args['dpi'] ?? 300),
        'bleed_mm' => (float)($args['bleed'] ?? 3),
    ]);
    $opts = [
        'width_cm'    => $wCm,
        'height_cm'   => $hCm,
        'fit'         => $args['fit'] ?? 'cover',
        'format'      => $args['format'] ?? 'jpg',
        'orientation' => $args['orientation'] ?? 'auto',
    ];

    $in    = rtrim($args['in'], '/');
    $files = is_dir($in)
        ? (glob($in . '/*.{jpg,jpeg,JPG,JPEG,png,PNG,webp,WEBP}', GLOB_BRACE) ?: [])
        : [$in];
    sort($files);

    if (!empty($args['inspect'])) {
        printf("%-34s %11s %9s %-9s %s\n", 'Fichier', 'Pixels', 'Max dpi', 'Verdict', 'Sens');
        foreach ($files as $f) {
            $r = $pi->inspect($f, $wCm, $hCm);
            printf("%-34s %11s %9s %-9s %s\n", substr(basename($f), 0, 34),
                $r['ok'] ? $r['width'] . '×' . $r['height'] : '—',
                $r['ok'] ? $r['max_dpi'] : '—',
                $r['verdict'] ?? $r['error'], $r['orientation'] ?? '');
        }
        exit(0);
    }

    $out = rtrim($args['out'] ?? ($in . '/print'), '/');
    $ok = 0; $ko = 0; $warn = 0;
    foreach ($files as $f) {
        $dest = $out . '/' . preg_replace('/\.[^.]+$/', '', basename($f)) . '_print.'
              . (($opts['format'] === 'png') ? 'png' : 'jpg');
        $r = $pi->process($f, $dest, $opts);
        if (!$r['ok']) {
            $ko++;
            printf("✗ %-30s %s\n", basename($f), $r['messages'][0] ?? $r['error']);
            continue;
        }
        $ok++;
        if ($r['verdict'] !== 'ok') { $warn++; }
        printf("%s %-30s %d×%d px @ %d dpi  (réel %s dpi, %s)\n",
            $r['verdict'] === 'ok' ? '✓' : '⚠', basename($f),
            $r['width'], $r['height'], $r['dpi'], $r['effective_dpi'], $r['verdict']);
        foreach ($r['messages'] as $m) {
            if ($r['verdict'] !== 'ok' || str_contains($m, 'Recadrage')) {
                echo '    · ' . $m . "\n";
            }
        }
    }
    printf("\n%d traitée(s), %d en échec, %d à vérifier avant le bon à tirer.\n", $ok, $ko, $warn);
    exit($ko > 0 ? 1 : 0);
}
