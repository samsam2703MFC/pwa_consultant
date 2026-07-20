<?php
namespace App\Consultant\core\Support;

class GlobalRegistry {
    private static array $storage = [];

    public static function set(string $key, mixed $value): void {
        self::$storage[$key] = $value;
    }

    public static function get(string $key): mixed {
        return self::$storage[$key] ?? null;
    }
}

