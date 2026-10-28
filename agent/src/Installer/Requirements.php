<?php

declare(strict_types=1);

namespace Dodo\Installer;

use Dodo\Crypto;
use PDO;
use Throwable;

/** Server checks shown on the first setup step. "fail" blocks the install, "warn" limits features. */
final class Requirements
{
    public const MIN_PHP = '8.1.0';
    // 3.7.17 is the oldest library still common on shared hosts (CentOS / CloudLinux 7) and covers
    // everything DodoCD's schema uses (foreign keys, WAL). Migrations must stay compatible with it.
    public const MIN_SQLITE = '3.7.17';
    // UPSERT arrived in 3.24, window functions and RENAME COLUMN in 3.25.
    public const RECOMMENDED_SQLITE = '3.25.0';
    public const RECOMMENDED_UPLOAD_BYTES = 64 * 1024 * 1024;
    public const RECOMMENDED_EXECUTION_SECONDS = 60;

    /** @return list<array{label: string, status: 'ok'|'warn'|'fail', detail: string}> */
    public static function check(): array
    {
        $crypto = Crypto::backend();
        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');
        $executionTime = (int) ini_get('max_execution_time');
        $basedir = (string) ini_get('open_basedir');
        $sqlite = self::sqliteVersion();

        return [
            self::result('PHP ' . self::MIN_PHP . ' or newer',
                version_compare(PHP_VERSION, self::MIN_PHP, '>='),
                'Running PHP ' . PHP_VERSION . '.'),
            self::result('PDO SQLite extension',
                extension_loaded('pdo_sqlite'),
                'Stores DodoCD\'s own data (users, sites, releases, deployments).'),
            self::sqliteCheck($sqlite),
            self::result('Zip extension',
                class_exists('ZipArchive'),
                'Extracts uploaded release archives.'),
            self::result('JSON extension',
                function_exists('json_encode'),
                'Used for settings and API responses.'),
            self::result('Encryption (sodium or OpenSSL)',
                $crypto !== null,
                $crypto !== null ? "Using {$crypto} to encrypt stored secrets." : 'Enable the sodium or openssl extension.'),
            self::result('Install folder is writable',
                is_writable(DODO_AGENT),
                'DodoCD writes its config to ' . DODO_AGENT . '.'),
            self::result('PDO MySQL extension',
                extension_loaded('pdo_mysql'),
                'Needed to run your app\'s database migrations. Without it the app database step is skipped.',
                required: false),
            self::result('Upload size limit',
                self::bytes($upload) >= self::RECOMMENDED_UPLOAD_BYTES,
                "upload_max_filesize is {$upload}. Deploy archives larger than this are rejected (64M or more recommended).",
                required: false),
            self::result('POST size limit',
                self::bytes($post) >= self::RECOMMENDED_UPLOAD_BYTES,
                "post_max_size is {$post}. It must be at least as large as the upload limit.",
                required: false),
            self::result('Execution time limit',
                $executionTime === 0 || $executionTime >= self::RECOMMENDED_EXECUTION_SECONDS,
                'max_execution_time is ' . ($executionTime === 0 ? 'unlimited' : "{$executionTime}s") . '. Large deploys may need 60s or more.',
                required: false),
            self::result('symlink() available',
                self::functionAvailable('symlink'),
                'Lets future releases be switched atomically. Not needed for install.',
                required: false),
            self::result('No open_basedir restriction',
                $basedir === '',
                $basedir === '' ? 'PHP can reach folders outside the web root.' : "PHP is restricted to: {$basedir}. The data directory must be inside one of these.",
                required: false),
        ];
    }

    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }

    /** What this server supports, stored in settings so later features can adapt. */
    public static function capabilities(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'sqlite_version' => self::sqliteVersion(),
            'crypto' => Crypto::backend(),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'symlink' => self::functionAvailable('symlink'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ];
    }

    private static function result(string $label, bool $ok, string $detail, bool $required = true): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : ($required ? 'fail' : 'warn'),
            'detail' => $detail,
        ];
    }

    private static function sqliteCheck(?string $version): array
    {
        $label = 'SQLite ' . self::MIN_SQLITE . ' or newer';

        if ($version === null) {
            return ['label' => $label, 'status' => 'fail', 'detail' => 'Could not open an SQLite database to read its version.'];
        }
        if (version_compare($version, self::MIN_SQLITE, '<')) {
            return ['label' => $label, 'status' => 'fail', 'detail' => "SQLite library is {$version}, which is too old. Ask your host for a newer PHP build."];
        }
        if (version_compare($version, self::RECOMMENDED_SQLITE, '<')) {
            return ['label' => $label, 'status' => 'warn', 'detail' => "SQLite library is {$version}. DodoCD works, but "
                . self::RECOMMENDED_SQLITE . ' or newer is recommended. Your host may offer a newer PHP version.'];
        }

        return ['label' => $label, 'status' => 'ok', 'detail' => "SQLite library is {$version}."];
    }

    /** Version of the SQLite library PHP is linked against (not PHP's own version), or null if unusable. */
    public static function sqliteVersion(): ?string
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }

        try {
            $version = (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();

            return is_string($version) ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function functionAvailable(string $name): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists($name) && !in_array($name, $disabled, true);
    }

    /** Converts php.ini shorthand like "64M" to bytes. */
    private static function bytes(string $value): int
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
}
