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

$app = $container->get(App::class);
$app->loadController();

