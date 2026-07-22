<?php
namespace App\Consultant\core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(private array $old) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('old', [$this, 'getOld']),
            new TwigFunction('assetv', [$this, 'assetVersion']),
        ];
    }

    /**
     * Version d'un asset public (cache-busting) : mtime du fichier.
     * L'URL change à chaque déploiement du fichier → le service worker
     * et le navigateur rechargent la nouvelle version au lieu de servir
     * l'ancienne depuis le cache.
     */
    public function assetVersion(string $path): string
    {
        $file = dirname(__DIR__, 3) . '/public/' . ltrim($path, '/');
        $mtime = @filemtime($file);
        return $mtime !== false ? (string)$mtime : '1';
    }

    public function getOld(string $key, string $default = ''): string
    {
        return $this->old[$key] ?? $default;
    }

    public function getGlobals(): array
    {
        return ['old' => $this->old];
    }
}

