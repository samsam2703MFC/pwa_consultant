<?php
use FastRoute\RouteCollector;

// Automatycznie ładuje wszystkie pliki *Routes.php z podkatalogów
return function(RouteCollector $r) {

    $directory = __DIR__;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $phpFiles = new RegexIterator($iterator, '/Routes\.php$/');

    foreach ($phpFiles as $file) {
        $routesDef = require $file;
        if (is_callable($routesDef)) {
            $routesDef($r);
        }
    }
};

