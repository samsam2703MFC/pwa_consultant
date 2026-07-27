<?php

use App\Consultant\app\Http\Controllers\Report\RapportController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Écran de choix (type + périmètre) → point d'entrée du menu « Rapports ».
    $r->addRoute('GET', '/reports', [
        'controller' => RapportController::class,
        'method'     => 'index',
    ]);

    // Rapport imprimable (hebdomadaire ou mensuel), lu depuis ?type=&scope=.
    $r->addRoute('GET', '/reports/view', [
        'controller' => RapportController::class,
        'method'     => 'show',
    ]);
};
