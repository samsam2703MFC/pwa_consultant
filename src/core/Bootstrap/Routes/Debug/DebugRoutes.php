<?php

use App\Consultant\app\Http\Controllers\Debug\DebugController;
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/pnl-debug', [
        'controller' => DebugController::class,
        'method'     => 'pnl'
    ]);

};
