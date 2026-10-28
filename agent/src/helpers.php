<?php

declare(strict_types=1);

/** The generated bootstrap config, or null when DodoCD is not installed yet. */
function dodo_config(): ?array
{
    static $config = false;

    if ($config === false) {
        $config = is_file(DODO_CONFIG) ? require DODO_CONFIG : null;
    }

    return is_array($config) ? $config : null;
}

/** URL path DodoCD is served from, e.g. "/dodocd" (empty string when served from the web root). */
function dodo_base_path(): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    return rtrim($base, '/');
}

/** Request path relative to the base path, always starting with "/" and without a trailing slash. */
function dodo_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = dodo_base_path();

    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    return '/' . trim($path, '/');
}

function dodo_url(string $path = '/'): string
{
    return dodo_base_path() . '/' . ltrim($path, '/');
}

function dodo_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['SERVER_PORT'] ?? null) == 443;
}

/** Scheme and host of the current request, e.g. "https://example.com". */
function dodo_origin(): string
{
    return (dodo_is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function dodo_redirect(string $path): never
{
    header('Location: ' . dodo_url($path), true, 303);
    exit;
}

/** Current UTC time in the format stored in the SQLite database. */
function dodo_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dodo_view(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require DODO_AGENT . '/views/' . $view . '.php';

    return (string) ob_get_clean();
}

function dodo_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}
