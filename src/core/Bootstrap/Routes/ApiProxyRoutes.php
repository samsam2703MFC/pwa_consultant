<?php

use App\Consultant\app\Http\Controllers\ApiProxyController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/api-proxy', [
        'controller' => ApiProxyController::class,
        'method'     => 'handle',
    ]);

    // Proxy groupé : plusieurs endpoints en parallèle, une seule réponse.
    $r->addRoute('POST', '/api-proxy-batch', [
        'controller' => ApiProxyController::class,
        'method'     => 'handleBatch',
    ]);

};

