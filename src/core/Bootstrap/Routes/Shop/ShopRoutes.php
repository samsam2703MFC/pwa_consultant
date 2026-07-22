<?php

use App\Consultant\app\Http\Controllers\Shop\ShopController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/shops', [
        'controller' => ShopController::class,
        'method'     => 'index',
    ]);

    // Ventes du jour d'un magasin depuis la base locale (tickets + CA) —
    // utilisé par le tableau « état au moment T » de l'accueil.
    $r->addRoute('GET', '/shops/{shopId:\d+}/day-sales', [
        'controller' => ShopController::class,
        'method'     => 'daySales',
    ]);

    // Note Google du magasin (Places API, clé côté serveur, cache 12 h).
    $r->addRoute('GET', '/shops/{shopId:\d+}/google-rating', [
        'controller' => ShopController::class,
        'method'     => 'googleRatingEndpoint',
    ]);
};

