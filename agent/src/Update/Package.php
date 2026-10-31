<?php

declare(strict_types=1);

namespace Dodo\Update;

use Dodo\Filesystem;
use Dodo\Installer\Requirements;

/**
 * An extracted DodoCD package: a single "dodocd/" folder holding the code and agent/manifest.json,
 * which lists every file's SHA-256, plus agent/manifest.json.sig when the package is signed.
 * Built by tools/build-package.php.
 */
final class Package
{
    public const ROOT = 'dodocd';
    public const MANIFEST = 'agent/manifest.json';
    public const SIGNATURE = 'agent/manifest.json.sig';

    /** An install's own state. Never in a package, and never touched or backed up by an update. */
    public const INSTALL_STATE = ['agent/config.php', 'agent/config.php.tmp', 'agent/setup.lock', 'storage'];

    /**
     * @param string $dir The package's "dodocd" folder.
     * @param array{version: string, commit: ?string, built_at: ?string, requires: array, files: array<string, string>} $manifest
     * @param array{status: string, detail: string} $signature Signature::verify()
     * @param list<string> $problems Reasons this server can't run the package. Empty when it can.
     */
    private function __construct(
        public readonly string $dir,
        public readonly array $manifest,
        public readonly array $signature,
        public readonly array $problems,
    ) {
    }

    /**
     * @param string $extracted The folder the package zip was extracted into.
     * @throws UpdateException When it isn't a complete, unmodified DodoCD package.
     */
    public static function load(string $extracted): self
    {
        $entries = array_values(array_diff(scandir($extracted) ?: [], ['.', '..']));
        $dir = $extracted . '/' . self::ROOT;
        if ($entries !== [self::ROOT] || !is_dir($dir)) {
            throw new UpdateException('This is not a DodoCD package. A package has a single "' . self::ROOT . '" folder at the top. '
                . 'Download dodocd-x.y.z.zip from the release page, not GitHub\'s source code zip.');
        }

        $json = @file_get_contents($dir . '/' . self::MANIFEST);
        $manifest = $json === false ? null : json_decode($json, true);
        if (!self::validManifest($manifest)) {
            throw new UpdateException('The package has no valid ' . self::MANIFEST . '. Build it with tools/build-package.php.');
        }

        $sigFile = $dir . '/' . self::SIGNATURE;
        $signature = Signature::verify($json, is_file($sigFile) ? (string) file_get_contents($sigFile) : null);

        self::checkFiles($dir, $manifest['files']);

        return new self($dir, $manifest, $signature, self::problems($manifest));
    }

    /** @return array<string, string>|null The installed package's files and hashes, or null for an install not made from a package. */
    public static function installedFiles(string $root): ?array
    {
        $json = @file_get_contents($root . '/' . self::MANIFEST);
        $manifest = $json === false ? null : json_decode($json, true);

        return self::validManifest($manifest) ? $manifest['files'] : null;
    }

    public function version(): string
    {
        return $this->manifest['version'];
    }

    public function signed(): bool
    {
        return $this->signature['status'] === 'valid';
    }

    /**
     * What installing this package into $root changes.
     *
     * @param array<string, string>|null $installed Package::installedFiles()
     * @return array{added: list<string>, changed: list<string>, removed: list<string>, unchanged: int}
     */
    public function changes(string $root, ?array $installed): array
    {
        $changes = ['added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0];

        foreach ($this->manifest['files'] as $path => $hash) {
            $file = "{$root}/{$path}";
            if (!is_file($file)) {
                $changes['added'][] = $path;
            } elseif (hash_file('sha256', $file) !== $hash) {
                $changes['changed'][] = $path;
            } else {
                $changes['unchanged']++;
            }
        }

        foreach (array_keys($installed ?? []) as $path) {
            if (!isset($this->manifest['files'][$path]) && is_file("{$root}/{$path}")) {
                $changes['removed'][] = $path;
            }
        }

        return $changes;
    }

    private static function validManifest(mixed $manifest): bool
    {
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'dodocd'
            || !is_string($manifest['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+([-.+][0-9A-Za-z.-]+)?$/', $manifest['version'])
            || !is_array($manifest['files'] ?? null) || $manifest['files'] === []
            || !is_array($manifest['requires'] ?? [])) {
            return false;
        }

        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                return false;
            }
        }

        return true;
    }

    /** Every file must be listed with the right hash and every listed file present, so the manifest describes the package exactly. */
    private static function checkFiles(string $dir, array $listed): void
    {
        $present = array_diff(Filesystem::files($dir), [self::MANIFEST, self::SIGNATURE]);

        foreach ($present as $path) {
            if (!isset($listed[$path])) {
                throw new UpdateException("The package contains {$path}, which its manifest doesn't list.");
            }
            if (hash_file('sha256', "{$dir}/{$path}") !== $listed[$path]) {
                throw new UpdateException("{$path} in the package doesn't match its manifest. The package was changed after it was built.");
            }
        }

        $missing = array_diff(array_keys($listed), $present);
        if ($missing) {
            throw new UpdateException('The package is incomplete. Missing: ' . implode(', ', array_slice($missing, 0, 5)) . '.');
        }

        foreach ($present as $path) {
            foreach (self::INSTALL_STATE as $state) {
                if ($path === $state || str_starts_with($path, $state . '/')) {
                    throw new UpdateException("The package contains {$path}, which belongs to an install, not a package.");
                }
            }
        }
    }

    /** @return list<string> */
    private static function problems(array $manifest): array
    {
        $problems = [];
        $requires = $manifest['requires'] ?? [];

        if (version_compare($manifest['version'], DODO_VERSION, '<')) {
            $problems[] = "Version {$manifest['version']} is older than the running version " . DODO_VERSION . '. '
                . 'Downgrades aren\'t supported, because database changes only go forward. Restore a backup instead.';
        }

        $php = (string) ($requires['php'] ?? '');
        if ($php !== '' && version_compare(PHP_VERSION, $php, '<')) {
            $problems[] = "It needs PHP {$php} or newer. This server runs PHP " . PHP_VERSION . '.';
        }

        $sqlite = (string) ($requires['sqlite'] ?? '');
        $current = Requirements::sqliteVersion();
        if ($sqlite !== '' && ($current === null || version_compare($current, $sqlite, '<'))) {
            $problems[] = "It needs SQLite {$sqlite} or newer. This server has " . ($current ?? 'none') . '.';
        }

        foreach ((array) ($requires['extensions'] ?? []) as $extension) {
            if (is_string($extension) && !extension_loaded($extension)) {
                $problems[] = "It needs the {$extension} PHP extension, which this server doesn't have.";
            }
        }

        return $problems;
    }
}
