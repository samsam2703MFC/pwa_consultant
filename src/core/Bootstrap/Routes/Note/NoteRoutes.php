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

    // Nouvelle note — formulaire NEUTRE (boutique + personne choisies dans le
    // formulaire). Point d'entrée du bouton « Ajouter une note » de l'accueil.
    $r->addRoute('GET', '/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);
    $r->addRoute('POST', '/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);

    // Nowa notatka sklepu (boutique pré-remplie via l'URL)
    $r->addRoute('GET', '/shops/{shopId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);
    $r->addRoute('POST', '/shops/{shopId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);

    // Nowa notatka pracownika sklepu (boutique + personne pré-remplies via l'URL)
    $r->addRoute('GET', '/shops/{shopId:\d+}/employees/{employeeId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);
    $r->addRoute('POST', '/shops/{shopId:\d+}/employees/{employeeId:\d+}/notes/new', [
        'controller' => NoteController::class,
        'method'     => 'create',
    ]);

    // Aperçu d'une pièce jointe (redirige vers l'URL présignée)
    $r->addRoute('GET', '/notes/attachments/{attachmentId:\d+}/preview', [
        'controller' => NoteController::class,
        'method'     => 'previewAttachment',
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

    // Relecture d'une note (bouton « Corriger »). Route STATIQUE : elle ne
    // heurte pas /notes/{id:\d+}, dont le motif n'accepte que des chiffres.
    $r->addRoute('POST', '/notes/ai-correct', [
        'controller' => NoteController::class,
        'method'     => 'aiCorrect',
    ]);
};
