<?php

use App\Consultant\app\Http\Controllers\ApiProxyController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/api-proxy', [
        'controller' => ApiProxyController::class,
        'method'     => 'handle',
    ]);

};

