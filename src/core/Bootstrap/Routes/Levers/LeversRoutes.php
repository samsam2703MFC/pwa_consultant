<?php

use App\Consultant\app\Http\Controllers\Levers\LeversController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // 6L — les 6 leviers de gestion, par magasin, vs moyenne réseau.
    $r->addRoute('GET', '/levers', [
        'controller' => LeversController::class,
        'method'     => 'index',
    ]);
};
