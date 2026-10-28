<?php

declare(strict_types=1);

namespace Dodo\Installer;

use Dodo\Crypto;
use Dodo\Db\Migrator;
use Dodo\Db\Sqlite;
use Dodo\Path;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Final setup step: creates the SQLite database, stores the admin, main site and
 * optional app database, and writes agent/config.php last. The config file is what
 * marks DodoCD as installed, so a failure before it leaves setup re-runnable.
 */
final class Installer
{
    /**
     * @param array{
     *     data_dir: string,
     *     admin: array{name: string, email: string, password_hash: string},
     *     site: array{name: string, url: string, target_path: string},
     *     database: ?array{host: string, port: int, name: string, user: string, pass: string},
     * } $input
     * @return string The main site's deploy token. Only its hash is stored, so this is the one chance to show it.
     */
    public function install(array $input): string
    {
        $dataDir = $input['data_dir'];
        DataDir::prepare($dataDir);

        $dbFile = Sqlite::path($dataDir);
        $this->moveAside($dbFile);

        $pdo = Sqlite::open($dbFile);
        (new Migrator($pdo, DODO_AGENT . '/migrations'))->migrate();

        $appKey = Crypto::generateKey();
        $token = 'dodo_' . bin2hex(random_bytes(32));

        $pdo->beginTransaction();
        try {
            $this->seed($pdo, $input, new Crypto($appKey), $token);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->writeConfig([
            'installed_at' => dodo_now(),
            'version' => DODO_VERSION,
            'base_path' => dodo_base_path(),
            'data_dir' => $dataDir,
            'app_key' => $appKey,
        ]);

        return $token;
    }

    /**
     * Paths inside the target that deploys must never touch, relative to the target.
     * Normally just DodoCD's own folder, e.g. ["dodocd"] for public_html/dodocd.
     *
     * @return list<string>
     */
    public static function excludedPaths(string $targetPath, string $dataDir): array
    {
        $target = Path::canonical($targetPath);
        $dodoRoot = Path::canonical(DODO_ROOT);
        $dataDir = Path::canonical($dataDir);

        $candidates = [$dodoRoot];
        if (!Path::isWithin($dataDir, $dodoRoot)) {
            $candidates[] = $dataDir; // otherwise already covered by DodoCD's own folder
        }

        $excluded = [];
        foreach ($candidates as $path) {
            if (Path::isWithin($path, $target)) {
                $excluded[] = Path::relative($path, $target);
            }
        }

        return $excluded;
    }

    private function seed(PDO $pdo, array $input, Crypto $crypto, string $token): void
    {
        $now = dodo_now();
        $admin = $input['admin'];
        $site = $input['site'];
        $database = $input['database'];

        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$admin['name'], $admin['email'], $admin['password_hash'], 'admin', $now]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO sites (slug, name, url, target_path, excluded_paths, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                self::slug($site['name']),
                $site['name'],
                $site['url'],
                $site['target_path'],
                json_encode(self::excludedPaths($site['target_path'], $input['data_dir']), JSON_UNESCAPED_SLASHES),
                $now,
                $now,
            ]);
        $siteId = (int) $pdo->lastInsertId();

        if ($database !== null) {
            $pdo->prepare('INSERT INTO site_databases (site_id, driver, host, port, name, username, password_enc, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $siteId,
                    'mysql',
                    $database['host'],
                    $database['port'],
                    $database['name'],
                    $database['user'],
                    $crypto->encrypt($database['pass']),
                    $now,
                ]);
        }

        $pdo->prepare('INSERT INTO api_tokens (site_id, name, prefix, token_hash, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, 'Default deploy token', substr($token, 0, 12), hash('sha256', $token), $now]);

        $setting = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
        $setting->execute(['schema_version', DODO_VERSION]);
        $setting->execute(['capabilities', json_encode(Requirements::capabilities())]);

        $pdo->prepare('INSERT INTO audit_log (user_id, site_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $siteId, 'install', json_encode(['version' => DODO_VERSION], JSON_UNESCAPED_SLASHES), $now]);
    }

    /** Keeps a database left behind by an earlier, unfinished install instead of deleting it. */
    private function moveAside(string $dbFile): void
    {
        if (!file_exists($dbFile)) {
            return;
        }

        $stamp = date('YmdHis');
        // The -wal/-shm files belong to the old database and would corrupt a new one with the same name.
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (file_exists($dbFile . $suffix) && !rename($dbFile . $suffix, "{$dbFile}{$suffix}.bak-{$stamp}")) {
                throw new RuntimeException("Could not move aside the existing {$dbFile}{$suffix}.");
            }
        }
    }

    /** Writes to a temp file and renames, so a half-written config can never mark DodoCD as installed. */
    private function writeConfig(array $config): void
    {
        $code = "<?php\n\n// Generated by the DodoCD installer. Keep this file private: app_key decrypts stored secrets.\n"
            . 'return ' . var_export($config, true) . ";\n";
        $tmp = DODO_CONFIG . '.tmp';

        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $tmp . '. Check folder permissions.');
        }
        @chmod($tmp, 0600);

        if (!rename($tmp, DODO_CONFIG)) {
            @unlink($tmp);
            throw new RuntimeException('Could not create ' . DODO_CONFIG . '.');
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(DODO_CONFIG, true);
        }
    }

    private static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' ? $slug : 'main';
    }
}
