<?php
namespace App\Consultant\core\Db;

use PDO;
use Throwable;

/**
 * Connexion MySQL directe à la base consultant (atelierby_db).
 *
 * Les identifiants NE SONT PAS committés : ils sont lus depuis
 *   config/db.local.php   (fichier hors Git, cf. config/db.local.php.example)
 * ou, à défaut, depuis des variables d'environnement CONSULTANT_DB_*.
 *
 * Renvoie null si la config est absente ou la connexion échoue — les appelants
 * doivent gérer ce cas (dégradation propre, pas d'erreur bloquante).
 */
class Database
{
    private static ?PDO $pdo = null;
    private static bool $tried = false;

    public static function pdo(): ?PDO
    {
        if (self::$tried) {
            return self::$pdo;
        }
        self::$tried = true;

        $cfg = self::config();
        if ($cfg === null) {
            return null;
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                $cfg['port'],
                $cfg['name']
            );
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 4,
            ]);
        } catch (Throwable $e) {
            error_log('[db] connexion atelierby_db échouée: ' . $e->getMessage());
            self::$pdo = null;
        }

        return self::$pdo;
    }

    private static function config(): ?array
    {
        $file = __DIR__ . '/../../../config/db.local.php';
        if (is_file($file)) {
            $c = require $file;
            if (is_array($c) && !empty($c['name'])) {
                return array_merge(['host' => '127.0.0.1', 'port' => 3306, 'user' => '', 'pass' => ''], $c);
            }
        }

        $name = getenv('CONSULTANT_DB_NAME') ?: ($_SERVER['CONSULTANT_DB_NAME'] ?? '');
        if ($name !== '') {
            return [
                'host' => getenv('CONSULTANT_DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('CONSULTANT_DB_PORT') ?: 3306),
                'name' => $name,
                'user' => getenv('CONSULTANT_DB_USER') ?: '',
                'pass' => getenv('CONSULTANT_DB_PASS') ?: '',
            ];
        }

        return null;
    }
}
