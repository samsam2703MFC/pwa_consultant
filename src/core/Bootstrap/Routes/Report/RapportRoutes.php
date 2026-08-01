<?php

use App\Consultant\app\Http\Controllers\Report\ChecklistReportController;
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

    // Rapport « Checklist Tâches » — s'ajoute au rapport de gestion, ne le
    // remplace pas. Une boutique à la fois : le détail par jour et les
    // constats terrain n'ont aucun sens agrégés sur tout le réseau.
    $r->addRoute('GET', '/reports/checklist/week', [
        'controller' => ChecklistReportController::class,
        'method'     => 'week',
    ]);

    $r->addRoute('GET', '/reports/checklist/month', [
        'controller' => ChecklistReportController::class,
        'method'     => 'month',
    ]);
};
