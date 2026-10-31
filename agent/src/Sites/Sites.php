<?php

declare(strict_types=1);

namespace Dodo\Sites;

use Dodo\AppDb\Connection;
use Dodo\Auth\ApiToken;
use Dodo\Crypto;
use Dodo\Installer\Installer;
use Dodo\Path;
use PDO;
use Throwable;

/** Creating, validating and updating sites and their app database settings. Used by setup and the dashboard. */
final class Sites
{
    /** Slugs that would clash with dashboard routes under /sites/. */
    private const RESERVED_SLUGS = ['new'];

    /** @return array{name: string, url: string, target_path: string} Site fields from a submitted form. */
    public static function fromPost(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'url' => rtrim(trim((string) ($_POST['url'] ?? '')), '/'),
            'target_path' => trim((string) ($_POST['target_path'] ?? '')),
        ];
    }

    /**
     * @param PDO|null $pdo DodoCD's database, to check overlap with other sites (null during setup).
     * @return list<string>
     */
    public static function validate(array $site, string $dataDir, ?PDO $pdo = null, ?int $ignoreSiteId = null): array
    {
        $errors = [];
        if ($site['name'] === '') {
            $errors[] = 'Name is required.';
        }
        if (filter_var($site['url'], FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($site['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            $errors[] = 'Enter the site URL, starting with http:// or https://.';
        }

        $path = $site['target_path'];
        if (!Path::isAbsolute($path)) {
            $errors[] = 'The target path must be absolute.';
            return $errors;
        }
        if (!Path::allowedByOpenBasedir($path) || !is_dir($path)) {
            $errors[] = 'The target path does not exist or PHP cannot reach it.';
            return $errors;
        }

        // Compare resolved paths, so a symlink can't point a site into DodoCD's own folders.
        $path = Path::canonical($path);
        if (!is_writable($path)) {
            $errors[] = 'PHP cannot write to the target path, so deploys would fail.';
        } elseif (Path::isWithin($path, Path::canonical(DODO_ROOT))) {
            $errors[] = 'The target path cannot be DodoCD\'s own folder or inside it.';
        } elseif (Path::isWithin($path, Path::canonical($dataDir))) {
            $errors[] = 'The target path cannot be DodoCD\'s data directory or inside it.';
        } elseif ($pdo !== null) {
            foreach ($pdo->query('SELECT id, name, target_path FROM sites')->fetchAll() as $other) {
                if ((int) $other['id'] === $ignoreSiteId) {
                    continue;
                }
                if (Path::isWithin($path, $other['target_path']) || Path::isWithin($other['target_path'], $path)) {
                    $errors[] = "The target path overlaps the folder of \"{$other['name']}\" ({$other['target_path']}). "
                        . 'Sites must not share files, or deploys of one could delete files of the other.';
                    break;
                }
            }
        }

        return $errors;
    }

    /** @return array{id: int, slug: string, token: string} The deploy token is returned only here; just its hash is stored. */
    public static function create(PDO $pdo, array $site, string $dataDir): array
    {
        $now = dodo_now();
        $targetPath = Path::canonical($site['target_path']);
        $slug = self::uniqueSlug($pdo, $site['name']);

        $pdo->prepare('INSERT INTO sites (slug, name, url, target_path, excluded_paths, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $slug,
                $site['name'],
                $site['url'],
                $targetPath,
                json_encode(Installer::excludedPaths($targetPath, $dataDir), JSON_UNESCAPED_SLASHES),
                $now,
                $now,
            ]);
        $siteId = (int) $pdo->lastInsertId();

        $token = ApiToken::generate();
        $pdo->prepare('INSERT INTO api_tokens (site_id, name, prefix, token_hash, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, 'Default deploy token', $token['prefix'], $token['hash'], $now]);

        return ['id' => $siteId, 'slug' => $slug, 'token' => $token['token']];
    }

    /**
     * Saves name, URL and target path. The slug never changes, because CI URLs use it.
     * A new target path starts fresh: the next deploy is treated as the first one there,
     * so nothing in the new folder is deleted.
     *
     * @return bool Whether the target path changed.
     */
    public static function update(PDO $pdo, array $existing, array $site, string $dataDir): bool
    {
        $targetPath = Path::canonical($site['target_path']);
        $pathChanged = $targetPath !== $existing['target_path'];

        $pdo->prepare('UPDATE sites SET name = ?, url = ?, target_path = ?, excluded_paths = ?, updated_at = ?'
                . ($pathChanged ? ', current_release_id = NULL' : '') . ' WHERE id = ?')
            ->execute([
                $site['name'],
                $site['url'],
                $targetPath,
                json_encode(Installer::excludedPaths($targetPath, $dataDir), JSON_UNESCAPED_SLASHES),
                dodo_now(),
                $existing['id'],
            ]);

        return $pathChanged;
    }

    public static function find(PDO $pdo, string $slug): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM sites WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetch() ?: null;
    }

    /**
     * Fills in the stored password when the form leaves it blank, but only if host, port and
     * user are unchanged, so the stored password can't be sent to a different server.
     *
     * @return array{0: array, 1: list<string>} The connection details and any errors.
     */
    public static function databaseInput(array $db, ?array $row, Crypto $crypto): array
    {
        $errors = Connection::validate($db);

        if ($db['password'] === '' && $row !== null) {
            $sameServer = $db['host'] === $row['host'] && $db['port'] === (int) $row['port'] && $db['username'] === $row['username'];
            if (!$sameServer) {
                $errors[] = 'Enter the password again when you change the host, port or user.';
            } else {
                try {
                    $db['password'] = $crypto->decrypt($row['password_enc']);
                } catch (Throwable) {
                    $errors[] = 'The stored password can\'t be decrypted. Enter it again.';
                }
            }
        }

        return [$db, $errors];
    }

    public static function saveDatabase(PDO $pdo, int $siteId, array $db, Crypto $crypto): void
    {
        $passwordEnc = $crypto->encrypt($db['password']);

        // Delete + insert rather than UPSERT, which SQLite only has from 3.24.
        $pdo->prepare('DELETE FROM site_databases WHERE site_id = ?')->execute([$siteId]);
        $pdo->prepare('INSERT INTO site_databases (site_id, driver, host, port, name, username, password_enc, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$siteId, 'mysql', $db['host'], $db['port'], $db['name'], $db['username'], $passwordEnc, dodo_now()]);
    }

    public static function removeDatabase(PDO $pdo, int $siteId): void
    {
        $pdo->prepare('DELETE FROM site_databases WHERE site_id = ?')->execute([$siteId]);
    }

    /** @return list<string> Names of other sites using the same database. */
    public static function sharingDatabase(PDO $pdo, int $siteId, array $db): array
    {
        $statement = $pdo->prepare('SELECT s.name FROM site_databases d JOIN sites s ON s.id = d.site_id
                                    WHERE d.site_id <> ? AND d.host = ? AND d.port = ? AND d.name = ?');
        $statement->execute([$siteId, $db['host'], $db['port'], $db['name']]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' && !in_array($slug, self::RESERVED_SLUGS, true) ? $slug : 'site';
    }

    private static function uniqueSlug(PDO $pdo, string $name): string
    {
        $base = self::slug($name);
        $statement = $pdo->prepare('SELECT 1 FROM sites WHERE slug = ?');

        for ($n = 1; ; $n++) {
            $slug = $n === 1 ? $base : "{$base}-{$n}";
            $statement->execute([$slug]);
            if ($statement->fetchColumn() === false) {
                return $slug;
            }
        }
    }
}
