<?php

use App\Consultant\app\Http\Controllers\Dashboard\DashboardController;
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => DashboardController::class,
        'method'     => 'index'
    ]);

    // Page de chargement post-login : préchauffe le cache API en parallèle.
    $r->addRoute('GET', '/loading', [
        'controller' => DashboardController::class,
        'method'     => 'loading'
    ]);

};

