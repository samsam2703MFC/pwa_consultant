<?php

use App\Consultant\app\Http\Controllers\Report\ShareController;
use App\Consultant\app\Http\Controllers\Report\SharedReportController;
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Création, suivi et révocation des liens : réservé au consultant connecté.
    $r->addRoute('GET',  '/reports/share', [
        'controller' => ShareController::class,
        'method'     => 'index',
    ]);
    $r->addRoute('POST', '/reports/share', [
        'controller' => ShareController::class,
        'method'     => 'create',
    ]);
    $r->addRoute('POST', '/reports/share/revoke', [
        'controller' => ShareController::class,
        'method'     => 'revoke',
    ]);

    // La page PUBLIQUE. Chemin court — il finit dans un message — et jeton
    // contraint : 43 caractères d'alphabet base64 URL, la longueur exacte de
    // 32 octets d'aléa encodés.
    $r->addRoute('GET', '/r/{token:[A-Za-z0-9_-]{43}}', [
        'controller' => SharedReportController::class,
        'method'     => 'show',
    ]);
};
