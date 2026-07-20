<?php
namespace App\Consultant\core\Bootstrap;

use App\Consultant\app\Http\Controllers\Auth\AuthController;
use App\Consultant\app\Http\Middleware\AuthMiddleware;
use Exception;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use function FastRoute\simpleDispatcher;


class App {

    private array $publicControllers = [
        AuthController::class,
    ];

    public function __construct(
        private ContainerInterface $container,
        private AuthMiddleware $middleware
    ) {}

    public function loadController(): void
    {
        $uri    = '/' . trim($_GET['url'] ?? '', '/');
        $method = $_SERVER['REQUEST_METHOD'];

        if ($uri === '/') {
            redirect('dashboard');
            return;
        }

        $dispatcher = simpleDispatcher(
            require __DIR__ . '/routes.php'
        );

        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                http_response_code(404);
                echo '404 Not Found';
                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                exit;

            case Dispatcher::FOUND:
                [$_, $handler, $vars] = $routeInfo;
                $controllerFQCN = $handler['controller'];
                $action         = $handler['method'];
                $requiredPerm   = $handler['permission'] ?? null;

                try {
                    $controller = $this->container->get($controllerFQCN);
                } catch (Exception $e) {
                    http_response_code(500);
                    echo 'Błąd DI: ' . $e->getMessage();
                    exit;
                }

                if (!in_array($controllerFQCN, $this->publicControllers, true)) {
                    $this->middleware->handle($requiredPerm);
                }

                $result = call_user_func_array([$controller, $action], $vars);

                if ($result instanceof Response) {
                    $result->send();
                    exit;
                }
                break;
        }
    }
}
