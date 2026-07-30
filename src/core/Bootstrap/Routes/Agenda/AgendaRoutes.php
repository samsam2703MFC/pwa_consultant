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

    // Édition d'une visite.
    $r->addRoute('GET', '/agenda/visits/{id:\d+}/edit', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'editVisit',
    ]);
    $r->addRoute('POST', '/agenda/visits/{id:\d+}/update', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'update',
    ]);

    // Statuts TREFLO d'une boutique pour une période (chargement AJAX du
    // formulaire : sélection du mois → leviers à travailler).
    $r->addRoute('GET', '/agenda/levers', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'leversJson',
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

    // Suppression définitive d'une visite.
    $r->addRoute('POST', '/agenda/visits/{id:\d+}/delete', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'deleteVisit',
    ]);

    // Agenda partagé d'une boutique (toutes les visites, tous consultants).
    $r->addRoute('GET', '/agenda/shop/{shopId:\d+}', [
        'controller' => \App\Consultant\app\Http\Controllers\Agenda\AgendaController::class,
        'method'     => 'shopAgenda',
    ]);
};
