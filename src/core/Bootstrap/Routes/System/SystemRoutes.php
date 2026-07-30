<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Provisioning des tables applicatives (mac_consultant_param, mac_shop_monthly_pnl,
    // mac_kpi_threshold, agenda) — à ouvrir une fois après un déploiement.
    $r->addRoute('GET', '/system/db-setup', [
        'controller' => \App\Consultant\app\Http\Controllers\System\DbSetupController::class,
        'method'     => 'setup',
    ]);
};
