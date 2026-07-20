<?php

use App\Consultant\app\Http\Controllers\Shop\ShopController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/shops', [
        'controller' => ShopController::class,
        'method'     => 'index',
    ]);
};

