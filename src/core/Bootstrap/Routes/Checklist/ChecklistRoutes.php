<?php

use App\Consultant\app\Http\Controllers\Checklist\ChecklistController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/checklists', [
        'controller' => ChecklistController::class,
        'method'     => 'index',
    ]);

    // Toutes les tâches du réseau, Boutique › Checklist › Tâche. Déclarée
    // AVANT la route par boutique : FastRoute n'a pas d'ambiguïté ici (le
    // segment est fixe), mais l'ordre de lecture suit celui de l'écran.
    $r->addRoute('GET', '/checklists/tasks', [
        'controller' => ChecklistController::class,
        'method'     => 'networkTasks',
    ]);

    $r->addRoute('GET', '/checklists/shops/{shopId:\d+}/tasks', [
        'controller' => ChecklistController::class,
        'method'     => 'shopTasks',
    ]);

    $r->addRoute('POST', '/checklists/reviews', [
        'controller' => ChecklistController::class,
        'method'     => 'submitReview',
    ]);

    // Validation de l'avis d'un consultant par l'Owner (case à cocher sur la
    // vignette « Vérifié par … »).
    $r->addRoute('POST', '/checklists/reviews/validate', [
        'controller' => ChecklistController::class,
        'method'     => 'validateReview',
    ]);
};
