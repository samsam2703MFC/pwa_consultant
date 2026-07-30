<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Provisioning des tables applicatives (mac_consultant_param, mac_shop_monthly_pnl,
    // mac_kpi_threshold, agenda) — à ouvrir une fois après un déploiement.
    $r->addRoute('GET', '/system/db-setup', [
        'controller' => \App\Consultant\app\Http\Controllers\System\DbSetupController::class,
        'method'     => 'setup',
    ]);

    // Configuration par endpoints : paramètres métier + bandes de couleur des
    // marges — lecture/écriture sans accès SQL.
    $r->addRoute('GET', '/system/params', [
        'controller' => \App\Consultant\app\Http\Controllers\System\ConfigController::class,
        'method'     => 'params',
    ]);
    $r->addRoute('POST', '/system/params', [
        'controller' => \App\Consultant\app\Http\Controllers\System\ConfigController::class,
        'method'     => 'saveParam',
    ]);
    $r->addRoute('GET', '/system/kpi-thresholds', [
        'controller' => \App\Consultant\app\Http\Controllers\System\ConfigController::class,
        'method'     => 'kpiThresholds',
    ]);
    $r->addRoute('POST', '/system/kpi-thresholds', [
        'controller' => \App\Consultant\app\Http\Controllers\System\ConfigController::class,
        'method'     => 'saveKpiThresholds',
    ]);
};
