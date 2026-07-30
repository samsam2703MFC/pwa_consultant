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

        $langCode = GlobalRegistry::get('lang_code') ?: resolveAppLanguage();

        $globalTranslations = loadTranslations('page', $langCode, 'components');
        $moduleTranslations  = loadTranslations('page', $langCode, $moduleName);

        $data['translations']       = array_merge($globalTranslations, $moduleTranslations);
        $data['errors']             = $this->errors;
        $data['information']        = $this->information;
        $data['warnings']           = $this->warnings;
        $data['successes']          = $this->successes;
        $data['ROOT']               = ROOT;
        $data['dev_no_auth']        = defined('DEV_NO_AUTH') && DEV_NO_AUTH;
        $data['api_base_url']       = API_BASE_URL;
        $data['shared_files_url']   = SHARED_FILES_URL;
        // CURRENCY_SYMBOL absent du .env → constante null → « 1140,90 null »
        // dans les montants côté JS. Repli sur € (déploiement L'Atelier).
        $data['currency_symbol']    = APP_CURRENCY_SYMBOL ?: '€';
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

    /** Moteur Twig partagé pour toute la requête (évite de le reconstruire à chaque view()). */
    private static ?Environment $twig = null;

    private function render(string $baseViewPath, string $twigTemplate, array $data): void
    {
        echo $this->twig($baseViewPath)->render($twigTemplate, $data);
    }

    /**
     * Moteur Twig avec CACHE DES TEMPLATES COMPILÉS.
     *
     * Avant : un `new Environment(cache=false)` par page → Twig recompilait
     * chaque .twig en PHP à CHAQUE requête. Ici le cache disque persiste entre
     * les requêtes ; `auto_reload` re-stat le mtime et recompile seulement si le
     * template a changé (déploiement) — coût négligeable vs recompilation.
     *
     * `debug` (dump(), DebugExtension) est aligné sur la constante DEBUG : off
     * en production. Répertoire de cache hors dépôt et inscriptible (même
     * stratégie que le cache API) ; s'il n'est pas inscriptible on retombe
     * proprement sur `cache=false` sans casser le rendu.
     */
    private function twig(string $baseViewPath): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $debug = defined('DEBUG') && DEBUG;

        $cacheDir = sys_get_temp_dir() . '/pwa_consultant_twig';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }
        $cache = (is_dir($cacheDir) && is_writable($cacheDir)) ? $cacheDir : false;

        $loader = new FilesystemLoader($baseViewPath);
        $twig   = new Environment($loader, [
            'cache'       => $cache,
            'autoescape'  => 'html',
            'debug'       => $debug,
            'auto_reload' => true,
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }
        $twig->addExtension(new AppExtension($_POST));

        return self::$twig = $twig;
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
