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
        echo $this->viewToString($name, $data);
    }

    /**
     * Le même rendu que view(), rendu en CHAÎNE.
     *
     * Le partage d'un rapport a besoin du HTML pour le figer ; le faire par un
     * second chemin de rendu garantirait qu'un jour les deux divergent.
     */
    public function viewToString(string $name, array $data = []): string
    {
        $baseViewPath = __DIR__ . '/../../../app/Views/';
        $data = $this->viewData($name, $data);
        $twigTemplate = $name . '.twig';

        return file_exists($baseViewPath . $twigTemplate)
            ? $this->twig($baseViewPath)->render($twigTemplate, $data)
            : $this->twig($baseViewPath)->render('errors/404.twig', $data);
    }

    /** Les variables que TOUTE vue reçoit : traductions, messages, contexte. */
    private function viewData(string $name, array $data): array
    {
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

        // Le jeton de mesure de la requête (PerfRecorder) : la page s'en sert
        // pour renvoyer le temps réellement vécu. Absent = mesure coupée, et
        // le gabarit n'écrit alors aucun script.
        $data['perf_rid'] = GlobalRegistry::get('perf_rid') ?: null;

        return $data;
    }

    /**
     * Des BLOCS d'une vue, rendus séparément — `head`, `content`, `scripts`.
     *
     * Le partage d'un rapport a besoin du corps, pas de la page qui l'entoure.
     * Twig sait isoler un bloc d'un gabarit qui hérite ; le faire autrement
     * reviendrait à découper du HTML à l'expression régulière.
     *
     * @param string[] $blocks
     * @return array<string, string> map nom de bloc => HTML ('' si absent)
     */
    public function viewBlocks(string $name, array $blocks, array $data = []): array
    {
        $baseViewPath = __DIR__ . '/../../../app/Views/';
        $data = $this->viewData($name, $data);
        $tpl  = $this->twig($baseViewPath)->load($name . '.twig');
        $out  = [];
        foreach ($blocks as $b) {
            $out[$b] = $tpl->hasBlock($b, $data) ? $tpl->renderBlock($b, $data) : '';
        }
        return $out;
    }

    /** Moteur Twig partagé pour toute la requête (évite de le reconstruire à chaque view()). */
    private static ?Environment $twig = null;

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
