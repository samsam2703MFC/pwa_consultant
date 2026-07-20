<?php

use App\Consultant\app\Http\Controllers\Me\MeController;
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/me', [
        'controller' => MeController::class,
        'method'     => 'index'
    ]);

};
