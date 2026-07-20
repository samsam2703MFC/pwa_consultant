<?php

use App\Consultant\app\Http\Controllers\Note\NoteController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Globalny punkt wejscia z navbara
    $r->addRoute('GET', '/notes', [
        'controller' => NoteController::class,
        'method'     => 'index',
    ]);

    // Lista notatek dla sklepu
    $r->addRoute('GET', '/shops/{shopId:\d+}/notes', [
        'controller' => NoteController::class,
        'method'     => 'listForShop',
    ]);

    // Lista notatek dla pracownika sklepu
    $r->addRoute('GET', '/shops/{shopId:\d+}/employees/{employeeId:\d+}/notes', [
        'controller' => NoteController::class,
        'method'     => 'listForEmployee',
    ]);

    // Nowa notatka sklepu
    $r->addRoute('GET', '/shops/{shopId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);
    $r->addRoute('POST', '/shops/{shopId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);

    // Nowa notatka pracownika sklepu
    $r->addRoute('GET', '/shops/{shopId:\d+}/employees/{employeeId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'createForEmployee',
    ]);
    $r->addRoute('POST', '/shops/{shopId:\d+}/employees/{employeeId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'createForEmployee',
    ]);

    // Szczegoly notatki
    $r->addRoute('GET', '/notes/{id:\d+}', [
        'controller' => NoteController::class,
        'method'     => 'detail',
    ]);

    // Usuwanie notatki
    $r->addRoute('POST', '/notes/{id:\d+}/delete', [
        'controller' => NoteController::class,
        'method'     => 'deleteNote',
    ]);

    // Dodawanie komentarza
    $r->addRoute('POST', '/notes/{noteId:\d+}/comments', [
        'controller' => NoteController::class,
        'method'     => 'addComment',
    ]);

    // Usuwanie komentarza
    $r->addRoute('POST', '/comments/{id:\d+}/delete', [
        'controller' => NoteController::class,
        'method'     => 'deleteComment',
    ]);
};
