<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Section Tendances — CA consolidé (N, N-1, objectifs) + top 3 des mois.
    $r->addRoute('GET', '/trends', [
        'controller' => \App\Consultant\app\Http\Controllers\Trends\TrendsController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('GET', '/trends/data', [
        'controller' => \App\Consultant\app\Http\Controllers\Trends\TrendsController::class,
        'method'     => 'data',
    ]);
};
