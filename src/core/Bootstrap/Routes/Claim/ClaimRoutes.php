<?php

use App\Consultant\app\Http\Controllers\Claim\ClaimController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/claims', [
        'controller' => ClaimController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('GET', '/claims/attachments/{attachmentId:\d+}/preview', [
        'controller' => ClaimController::class,
        'method'     => 'previewAttachment',
    ]);
};
