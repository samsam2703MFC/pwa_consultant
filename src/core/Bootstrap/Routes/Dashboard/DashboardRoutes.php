<?php

use App\Consultant\app\Http\Controllers\Dashboard\DashboardController;
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => DashboardController::class,
        'method'     => 'index'
    ]);

};

