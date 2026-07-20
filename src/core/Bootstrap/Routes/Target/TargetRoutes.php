<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/targets', [
        'controller' => \App\Consultant\app\Http\Controllers\Target\ShopMetricTargetController::class,
        'method'     => 'overview',
    ]);

    $r->addRoute('GET', '/targets/{shopId:\d+}', [
        'controller' => \App\Consultant\app\Http\Controllers\Target\ShopMetricTargetController::class,
        'method'     => 'edit',
    ]);

    $r->addRoute('POST', '/targets/{shopId:\d+}/save', [
        'controller' => \App\Consultant\app\Http\Controllers\Target\ShopMetricTargetController::class,
        'method'     => 'save',
    ]);

    $r->addRoute('POST', '/targets/{shopId:\d+}/copy', [
        'controller' => \App\Consultant\app\Http\Controllers\Target\ShopMetricTargetController::class,
        'method'     => 'copy',
    ]);
};

