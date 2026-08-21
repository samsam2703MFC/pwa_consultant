<?php

use App\Consultant\core\Bootstrap\App;
use App\Consultant\core\Support\GlobalRegistry;
//use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

// Załaduj .env z katalogu głównego workspace (app/)
//$dotenv = Dotenv::createImmutable(__DIR__ . '/../../', null, false);
//$dotenv->load();

require __DIR__ . '/../config/app.php';
require __DIR__ . '/../src/core/Support/functions.php';

DEBUG ? ini_set('display_errors', 1) : ini_set('display_errors', 0);

// Język z nagłówka przeglądarki
GlobalRegistry::set('lang_code', getUserLanguage());
GlobalRegistry::set('currency', $_ENV['CURRENCY'] ?? 'PLN');
GlobalRegistry::set('currency_symbol', $_ENV['CURRENCY_SYMBOL'] ?? 'zł');

$container = require __DIR__ . '/../src/core/Container/Container.php';

// Chronomètre de la requête (mac_consultant_perf). Démarré ici, donc AVANT le
// routage : le temps mesuré est celui que l'utilisateur attend, pas celui qu'on
// veut bien compter. L'écriture a lieu après l'envoi de la réponse, et toute
// panne de mesure est avalée — un incident d'instrumentation ne casse pas un
// écran. Se coupe par le paramètre perf_enabled.
try {
    $container->get(\App\Consultant\core\Support\PerfRecorder::class)->start();
} catch (\Throwable $e) {
    error_log('[perf] start: ' . $e->getMessage());
}

// Relevé mensuel de la note Google, complété APRÈS la réponse.
//
// Il ne peut pas être un cron : lire une note demande la liste des boutiques,
// donc l'API, donc un jeton — un cron tomberait sur l'écran de connexion. Il se
// greffe donc sur une requête d'un consultant déjà authentifié, une fois par
// jour au plus, et seulement pour les magasins qui manquent au mois en cours.
//
// L'utilisateur n'attend rien : le balayage démarre après fastcgi_finish_request().
// Toute panne est avalée — Google indisponible ne casse pas un écran.
register_shutdown_function(static function () use ($container): void {
    try {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $container->get(\App\Consultant\app\Services\Google\GoogleSnapshotSweeper::class)->sweep();
    } catch (\Throwable $e) {
        error_log('[google-snapshot] sweep: ' . $e->getMessage());
    }
});

$app = $container->get(App::class);
$app->loadController();

