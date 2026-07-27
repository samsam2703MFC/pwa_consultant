<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Vue mois (agenda du consultant connecté).
    $r->addRoute('GET', '/agenda', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'index',
    ]);

    // Formulaire de planification d'une visite.
    $r->addRoute('GET', '/agenda/new', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'newVisit',
    ]);

    // Création d'une visite (+ actions par levier).
    $r->addRoute('POST', '/agenda/visits', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'store',
    ]);

    // Invitation calendrier (.ics) d'une visite.
    $r->addRoute('GET', '/agenda/visits/{id:\d+}/ics', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'ics',
    ]);

    // Changement de statut d'une visite.
    $r->addRoute('POST', '/agenda/visits/{id:\d+}/status', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'setStatus',
    ]);

    // Agenda partagé d'une boutique (toutes les visites, tous consultants).
    $r->addRoute('GET', '/agenda/shop/{shopId:\d+}', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'shopAgenda',
    ]);
};
