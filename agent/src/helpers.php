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

/** DodoCD's own database, with any pending DodoCD schema migrations applied. */
function dodo_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = dodo_config() ?? throw new RuntimeException('DodoCD is not installed.');
        $pdo = Dodo\Db\Sqlite::open(Dodo\Db\Sqlite::path($config['data_dir']));
        (new Dodo\Db\Migrator($pdo, DODO_AGENT . '/migrations'))->migrate();
        dodo_record_version($pdo);
    }

    return $pdo;
}

/**
 * Keeps settings.schema_version and settings.capabilities current after an update, whether it
 * was made from the dashboard or by replacing the files by hand. Writes only when the version changed.
 */
function dodo_record_version(PDO $pdo): void
{
    $statement = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $statement->execute(['schema_version']);
    if ($statement->fetchColumn() === DODO_VERSION) {
        return;
    }

    $setting = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $setting->execute(['schema_version', DODO_VERSION]);
    $setting->execute(['capabilities', json_encode(Dodo\Installer\Requirements::capabilities())]);
}

/** Whether DodoCD is replacing its own files right now (see Update\Updater). */
function dodo_updating(): bool
{
    $config = dodo_config();

    return $config !== null && Dodo\Update\Updater::inMaintenance($config['data_dir']);
}

/** Converts php.ini shorthand like "64M" to bytes. */
function dodo_ini_bytes(string $value): int
{
    $value = trim($value);
    $number = (int) $value;

    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

function dodo_format_bytes(int $bytes): string
{
    foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $size) {
        if ($bytes >= $size) {
            return round($bytes / $size, 1) . ' ' . $unit;
        }
    }

    return $bytes . ' B';
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

/** A stored UTC timestamp as a <time> element; the layout's script shows it in the viewer's time zone. */
function dodo_time(?string $utc): string
{
    if ($utc === null) {
        return '—';
    }

    $iso = str_replace(' ', 'T', $utc) . 'Z';

    return '<time datetime="' . e($iso) . '" title="' . e($utc) . ' UTC">' . e(substr($utc, 0, 16)) . ' UTC</time>';
}

/** Time between two stored UTC timestamps, e.g. "1m 05s". */
function dodo_duration(string $start, ?string $end): string
{
    if ($end === null) {
        return '—';
    }

    $seconds = max(0, strtotime($end . ' UTC') - strtotime($start . ' UTC'));

    return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
}

/** Colored badge for a deployment status: running, success or failed. */
function dodo_status_badge(string $status): string
{
    $class = match ($status) {
        'success' => 'ok',
        'failed' => 'fail',
        default => 'warn',
    };

    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

/** Encrypts and decrypts stored secrets, such as site database passwords, with the install's app_key. */
function dodo_crypto(): Dodo\Crypto
{
    static $crypto = null;

    return $crypto ??= new Dodo\Crypto((dodo_config() ?? throw new RuntimeException('DodoCD is not installed.'))['app_key']);
}

/** Colored badge for an app migration's status: applied, baseline or failed. */
function dodo_migration_badge(string $status): string
{
    [$class, $label] = match ($status) {
        'applied' => ['ok', 'Applied'],
        'baseline' => ['ok', 'Baselined'],
        default => ['fail', 'Failed'],
    };

    return '<span class="badge ' . $class . '">' . $label . '</span>';
}
