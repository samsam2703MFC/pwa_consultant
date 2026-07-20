<?php

function show(mixed $stuff): void
{
    echo "<pre>";
    print_r($stuff);
    echo "</pre>";
}

function redirect(string $path): never
{
    header("Location: " . ROOT . "/" . trim($path, "/"));
    die;
}

function old_value(string $key, string $default = ''): string
{
    if (!empty($_POST[$key]))
        return $_POST[$key];

    return $default;
}

function old_select(string $key, string $value, string $default = ''): string
{
    if (!empty($_POST[$key]) && $_POST[$key] == $value) {
        return ' selected ';
    }

    if (!empty($default) && $default == $value) {
        return ' selected ';
    }

    return '';
}

function old_checkbox(string $key, string $value, string $default = ''): string
{
    if (!empty($_POST[$key]) && $_POST[$key] == $value) {
        return ' checked ';
    }

    if (!empty($default) && $default == $value) {
        return ' checked ';
    }

    return '';
}

/**
 * Ładuje tłumaczenia dla podanego modułu.
 *
 * @param string      $type        — typ (np. 'page')
 * @param string      $lang        — kod języka (np. 'pl', 'en')
 * @param string|null $module      — moduł (np. 'auth', 'dashboard')
 * @param string      $defaultLang — fallback język
 * @return array
 */
function loadTranslations(string $type, ?string $lang, ?string $module = null, string $defaultLang = 'en'): array
{
    $lang     = $lang ?: $defaultLang;
    $basePath = __DIR__ . '/../I18n/translations/';
    $filePath = $basePath . $type . '/' . $lang . '/' . $module . '.json';

    if (file_exists($filePath)) {
        $translations = json_decode(file_get_contents($filePath), true) ?? [];
    } else {
        $translations = [];
    }

    // Fallback do domyślnego języka
    if (empty($translations) && $lang !== $defaultLang) {
        $fallbackPath = $basePath . $type . '/' . $defaultLang . '/' . $module . '.json';
        if (file_exists($fallbackPath)) {
            $translations = json_decode(file_get_contents($fallbackPath), true) ?? [];
        }
    }

    return $translations;
}

function getUserLanguage(): string
{
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'pl';
    $languages      = explode(',', $acceptLanguage);
    $primary        = trim($languages[0]);
    $code           = substr($primary, 0, 2);

    return !empty($code) ? strtolower($code) : 'pl';
}

function sanitizeSlug(string $slug): string
{
    return strtolower(preg_replace('/[^a-z0-9-]/', '', $slug));
}

/**
 * Sprawdza, czy zalogowany konsultant posiada dane uprawnienie.
 * Korzysta z GlobalRegistry — musi być wywołana po AuthMiddleware::handle().
 */
function has_perm(string $perm): bool
{
    $user  = \App\Consultant\core\Support\GlobalRegistry::get('user');
    $perms = (array)($user['permissions'] ?? []);
    return in_array($perm, $perms, true);
}
