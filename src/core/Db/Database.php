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
 *
 * ET DIT POURQUOI. « Base indisponible » couvrait quatre pannes qui ne se
 * réparent pas de la même façon : identifiants absents, pilote MySQL manquant
 * pour CE PHP, MySQL injoignable, mot de passe refusé. Chaque appelant
 * devinait, et se trompait — le déploiement a longtemps annoncé
 * « config/db.local.php absent ? » alors que le fichier était là et que c'est
 * l'extension pdo_mysql qui manquait au PHP en ligne de commande.
 * unavailableReason() nomme la vraie cause, une fois pour tout le monde.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static bool $tried = false;

    /** Pourquoi il n'y a pas de connexion. null tant que tout va bien. */
    private static ?string $reason = null;

    public static function pdo(): ?PDO
    {
        if (self::$tried) {
            return self::$pdo;
        }
        self::$tried = true;

        $cfg = self::config();
        if ($cfg === null) {
            self::$reason = 'identifiants absents : ni config/db.local.php, ni variables CONSULTANT_DB_*';
            return null;
        }

        // Vérifié AVANT de composer un DSN : sans le pilote, PDO échouerait sur
        // un message (« could not find driver ») que personne ne relie à une
        // extension PHP manquante.
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::$reason = self::motifPilote();
            error_log('[db] ' . self::$reason);
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
            self::$reason = self::motif($e->getMessage(), $cfg);
            error_log('[db] connexion ' . $cfg['name'] . ' échouée : ' . self::$reason
                      . ' (' . $e->getMessage() . ')');
            self::$pdo = null;
        }

        return self::$pdo;
    }

    /**
     * La cause, en clair. À afficher tel quel : c'est cette phrase qui doit
     * arriver jusqu'à l'écran ou au journal de déploiement.
     */
    public static function unavailableReason(): ?string
    {
        self::pdo();
        return self::$pdo === null ? (self::$reason ?? 'cause inconnue') : null;
    }

    /**
     * Le pilote manque — et il manque à UN SAPI, pas forcément à tous : c'est
     * exactement le piège. Le site peut marcher (Apache/FPM a l'extension)
     * pendant qu'un script en ligne de commande échoue en silence. On nomme
     * donc le SAPI concerné.
     */
    private static function motifPilote(): string
    {
        return 'le pilote pdo_mysql n\'est pas installé pour ce PHP (SAPI « '
             . php_sapi_name() . ' », ' . PHP_VERSION . ') — installer l\'extension '
             . 'php-mysql pour ce SAPI ; les autres SAPI peuvent, eux, fonctionner';
    }

    /** Traduit le message de PDO en cause actionnable. */
    private static function motif(string $m, array $cfg): string
    {
        $ou = $cfg['host'] . ':' . $cfg['port'];
        return match (true) {
            str_contains($m, 'could not find driver') => self::motifPilote(),
            str_contains($m, '[1045]')               => 'identifiants refusés par MySQL (utilisateur « ' . $cfg['user'] .' »)',
            str_contains($m, '[1049]')               => 'la base « ' . $cfg['name'] . ' » n\'existe pas sur ' . $ou,
            str_contains($m, '[2002]'),
            str_contains($m, '[2003]')               => 'MySQL injoignable sur ' . $ou,
            str_contains($m, '[2005]')               => 'hôte MySQL introuvable : ' . $cfg['host'],
            default                                  => $m,
        };
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
