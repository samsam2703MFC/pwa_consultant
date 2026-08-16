<?php

use App\Consultant\app\Http\Controllers\Cockpit\CockpitTaskController;
use FastRoute\RouteCollector;

/**
 * « Mes tâches réseau » — les tâches confiées au consultant par la direction,
 * depuis le back office CEO. Le consultant y annonce lui-même la remise ; la
 * note reste à la direction.
 */
return function(RouteCollector $r) {

    $r->addRoute('GET', '/mes-taches-reseau', [
        'controller' => CockpitTaskController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/mes-taches-reseau/remise', [
        'controller' => CockpitTaskController::class,
        'method'     => 'remise'
    ]);

    $r->addRoute('POST', '/mes-taches-reseau/annuler', [
        'controller' => CockpitTaskController::class,
        'method'     => 'annuler'
    ]);

};
