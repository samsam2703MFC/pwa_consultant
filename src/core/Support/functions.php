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
function loadTranslations(string $type, ?string $lang, ?string $module = null, string $defaultLang = 'fr'): array
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

/**
 * Langue effective de l'application, dans l'ordre de priorité :
 *   1. préférence explicite du consultant (cookie consultant_lang, posé par
 *      le sélecteur de langue du profil),
 *   2. langue du compte (claim JWT usr_ln),
 *   3. langue du navigateur.
 * Langues supportées : fr, nl, en, it, pl ; défaut : français.
 */
function resolveAppLanguage(?string $accountLang = null): string
{
    $supported = ['fr', 'nl', 'en', 'it', 'pl'];

    $cookie = strtolower(substr((string)($_COOKIE['consultant_lang'] ?? ''), 0, 2));
    if (in_array($cookie, $supported, true)) {
        return $cookie;
    }

    $account = strtolower(substr((string)$accountLang, 0, 2));
    if (in_array($account, $supported, true)) {
        return $account;
    }

    $browser = strtolower(substr(getUserLanguage(), 0, 2));
    if (in_array($browser, $supported, true)) {
        return $browser;
    }

    return 'fr';
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
