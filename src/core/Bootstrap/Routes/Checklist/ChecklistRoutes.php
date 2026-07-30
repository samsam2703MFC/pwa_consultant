<?php

use App\Consultant\app\Http\Controllers\Checklist\ChecklistController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/checklists', [
        'controller' => ChecklistController::class,
        'method'     => 'index',
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
