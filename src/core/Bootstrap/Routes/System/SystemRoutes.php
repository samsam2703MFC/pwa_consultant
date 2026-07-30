<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Provisioning des tables applicatives (consultant_param, shop_monthly_pnl,
    // kpi_threshold, agenda) — à ouvrir une fois après un déploiement.
    $r->addRoute('GET', '/system/db-setup', [
        'controller' => \App\Consultant\app\Http\Controllers\System\DbSetupController::class,
        'method'     => 'setup',
    ]);
};
