<?php
namespace App\Consultant\app\Http\Controllers;

use App\Consultant\core\Exceptions\DataNotFoundException;
use App\Consultant\core\Exceptions\ProtectedResourceException;
use App\Consultant\core\Support\GlobalRegistry;
use App\Consultant\core\Twig\AppExtension;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class Controller
{
    public array $errors      = [];
    public array $information = [];
    public array $warnings    = [];
    public array $successes   = [];

    /**
     * Bezpieczne pobieranie danych z serwisu z obsługą wyjątków.
     */
    protected function safeFetch(callable $callback, array &$errors, mixed $params = null, mixed $default = []): mixed
    {
        try {
            if ($params === null) {
                return call_user_func($callback);
            } elseif (is_array($params)) {
                return call_user_func_array($callback, $params);
            } else {
                return call_user_func($callback, $params);
            }
        } catch (DataNotFoundException $e) {
            $errors[] = $e->getMessage();
            return $default;
        } catch (ProtectedResourceException $e) {
            $errors[] = $e->getMessage();
            return $default;
        } catch (Exception $e) {
            $errors[] = "Nieoczekiwany błąd: " . $e->getMessage();
            error_log($e->getMessage());
            return $default;
        }
    }

    public function view(string $name, array $data = []): void
    {
        $baseViewPath = __DIR__ . '/../../../app/Views/';

        $splittedPathElems = explode('/', $name);
        $moduleName = $splittedPathElems[0] ?? 'login';

        $langCode = GlobalRegistry::get('lang_code');

        $globalTranslations = loadTranslations('page', $langCode, 'components');
        $moduleTranslations  = loadTranslations('page', $langCode, $moduleName);

        $data['translations']       = array_merge($globalTranslations, $moduleTranslations);
        $data['errors']             = $this->errors;
        $data['information']        = $this->information;
        $data['warnings']           = $this->warnings;
        $data['successes']          = $this->successes;
        $data['ROOT']               = ROOT;
        $data['api_base_url']       = API_BASE_URL;
        $data['shared_files_url']   = SHARED_FILES_URL;
        $data['currency_symbol']    = APP_CURRENCY_SYMBOL;
        $data['lang_code']          = $langCode;

        $user = GlobalRegistry::get('user');
        $data['permissions'] = (array)($user['permissions'] ?? []);
        $data['current_user'] = $user;

        $twigTemplate = $name . '.twig';

        if (file_exists($baseViewPath . $twigTemplate)) {
            $this->render($baseViewPath, $twigTemplate, $data);
        } else {
            $this->render($baseViewPath, 'errors/404.twig', $data);
        }
    }

    private function render(string $baseViewPath, string $twigTemplate, array $data): void
    {
        $loader = new FilesystemLoader($baseViewPath);
        $twig   = new Environment($loader, [
            'cache'    => false,
            'autoescape' => 'html',
            'debug'    => true,
        ]);

        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new AppExtension($_POST));
        echo $twig->render($twigTemplate, $data);
    }

    protected function getJson(Request $request): array
    {
        return json_decode($request->getContent(), true) ?? [];
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}
