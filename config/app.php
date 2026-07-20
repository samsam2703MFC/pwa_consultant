<?php

define('ROOT',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/pwa_consultant');

define('API_BASE_URL',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/api/v1');

define('SHARED_FILES_URL',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/shared-assets');

define('THEME_CONFIG_PATH',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/shared/admin-theme-config.json');

define('JWT_SECRET_KEY', $_ENV['JWT_SECRET']);
define('JWT_ISSUER', $_ENV['JWT_ISSUER']);
define('JWT_ACCESS_TOKEN_EXPIRY', $_ENV['JWT_ACCESS_TOKEN_EXPIRY']);
define('JWT_REFRESH_TOKEN_EXPIRY', $_ENV['JWT_REFRESH_TOKEN_EXPIRY']);

define('DEFAULT_LANGUAGE', $_ENV['DEFAULT_LANGUAGE']);
define('COUNTRY_CODE', $_ENV['DEFAULT_COUNTRY']);
define('CURRENCY', $_ENV['CURRENCY']);
define('APP_CURRENCY_SYMBOL', $_ENV['CURRENCY_SYMBOL']);
define('APP_NAME', $_ENV['APP_NAME']);
define('APP_DESC', $_ENV['APP_DESC']);

const DEBUG = true;

/*
 * DEV_NO_AUTH — TEMPORARY test mode that BYPASSES authentication.
 * When enabled, every page is reachable without logging in (a fake demo user is
 * injected). Data lists stay empty because there is no JWT to call the backend.
 *
 * ⚠️  SECURITY: this opens the whole app to anyone. Keep it OFF in production.
 * Enable it ONLY for testing by adding `SetEnv DEV_NO_AUTH 1` in public/.htaccess
 * (or the server env). Default is OFF. Remove the flag to restore normal login.
 */
define('DEV_NO_AUTH',
    (($_SERVER['DEV_NO_AUTH'] ?? $_ENV['DEV_NO_AUTH'] ?? getenv('DEV_NO_AUTH') ?: '0') === '1'));

