<?php
namespace App\Consultant\core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(private array $old) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('old', [$this, 'getOld'])];
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

