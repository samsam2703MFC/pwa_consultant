<?php

use App\Consultant\core\Http\ApiClient;
use DI\ContainerBuilder;
use function DI\autowire;

require_once __DIR__ . '/../../../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    ApiClient::class => autowire()->constructorParameter('baseUrl', API_BASE_URL),
]);

return $containerBuilder->build();

