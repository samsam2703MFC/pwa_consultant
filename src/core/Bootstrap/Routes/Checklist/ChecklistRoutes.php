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
};
