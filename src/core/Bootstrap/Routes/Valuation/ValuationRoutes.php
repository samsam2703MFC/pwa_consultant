<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Données de valorisation (bouton « Valeurs réseau » de la section Boutiques).
    $r->addRoute('GET', '/shops/valuation', [
        'controller' => \App\Consultant\app\Http\Controllers\Valuation\ValuationController::class,
        'method'     => 'data',
    ]);
};
