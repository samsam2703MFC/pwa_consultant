<?php

use App\Consultant\app\Http\Controllers\Task\TaskController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/tasks', [
        'controller' => TaskController::class,
        'method'     => 'tasks',
    ]);

    $r->addRoute('GET', '/tasks/{id:\d+}', [
        'controller' => TaskController::class,
        'method'     => 'taskOverview',
    ]);

    $r->addRoute('POST', '/tasks/{id:\d+}', [
        'controller' => TaskController::class,
        'method'     => 'markAsDoneTask',
    ]);

    $r->addRoute('GET', '/tasks/completion/{id:\d+}', [
        'controller' => TaskController::class,
        'method'     => 'taskCompletionOverview',
    ]);

    $r->addRoute('GET', '/helpdesk', [
        'controller' => TaskController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('GET', '/helpdesk/cases/{id:\d+}', [
        'controller' => TaskController::class,
        'method'     => 'details',
    ]);

    $r->addRoute('GET', '/helpdesk/cases/{id:\d+}/eligible-consultants', [
        'controller' => TaskController::class,
        'method'     => 'eligibleConsultants',
    ]);

    $r->addRoute('GET', '/helpdesk/cases/{id:\d+}/meetings', [
        'controller' => TaskController::class,
        'method'     => 'meetings',
    ]);

    $r->addRoute('GET', '/helpdesk/attachments/{id:\d+}/url', [
        'controller' => TaskController::class,
        'method'     => 'attachmentUrl',
    ]);

    $r->addRoute('POST', '/helpdesk/cases/{id:\d+}/status', [
        'controller' => TaskController::class,
        'method'     => 'updateStatus',
    ]);

    $r->addRoute('POST', '/helpdesk/cases/{id:\d+}/consultant', [
        'controller' => TaskController::class,
        'method'     => 'assignConsultant',
    ]);

    $r->addRoute('POST', '/helpdesk/cases/{id:\d+}/meeting', [
        'controller' => TaskController::class,
        'method'     => 'assignMeeting',
    ]);

    $r->addRoute('POST', '/helpdesk/cases/{id:\d+}/reply', [
        'controller' => TaskController::class,
        'method'     => 'saveReply',
    ]);
};
