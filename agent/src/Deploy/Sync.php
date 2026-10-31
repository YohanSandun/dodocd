<?php

declare(strict_types=1);

namespace Dodo\Deploy;

use Closure;
use Dodo\Filesystem;
use Dodo\Path;
use RuntimeException;

/**
 * Copies a release into a site's target folder ("copy mode").
 *
 * Files in the release are copied in, skipping unchanged ones. Files that were in the
 * previous release but not in this one are deleted. Anything DodoCD never deployed
 * (user uploads, .env, caches) and the site's excluded paths are left alone.
 * Each file is written to a temp name and renamed, so no file is ever half-written.
 */
final class Sync
{
    /**
     * Release folder for DodoCD itself (e.g. migrations). Never copied into the target, but
     * still in the previous release's list, so copies made by older releases get deleted.
     */
    public const RELEASE_ONLY = '.dodocd';

    /** @var array{added: int, updated: int, unchanged: int, deleted: int, skipped: int} */
    public array $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'skipped' => 0];

    /**
     * @param list<string> $excluded Paths relative to $target that must never be touched.
     * @param Closure(string): void $log
     */
    public function __construct(
        private readonly string $target,
        private readonly array $excluded,
        private readonly Closure $log,
    ) {
    }

    public function run(string $source, ?string $previous): void
    {
        $files = array_values(array_filter(Filesystem::files($source), fn (string $file) => !self::isReleaseOnly($file)));

        foreach ($files as $relative) {
            if ($this->isExcluded($relative)) {
                $this->stats['skipped']++;
                continue;
            }
            $this->copyFile("{$source}/{$relative}", "{$this->target}/{$relative}");
        }

        if ($this->stats['skipped'] > 0) {
            ($this->log)("Skipped {$this->stats['skipped']} files inside protected paths (" . implode(', ', $this->excluded) . ').');
        }

        if ($previous === null) {
            return;
        }

        $current = array_flip(array_map(self::key(...), $files));
        foreach (Filesystem::files($previous) as $relative) {
            if (isset($current[self::key($relative)]) || $this->isExcluded($relative)) {
                continue;
            }

            $path = "{$this->target}/{$relative}";
            if (is_file($path) || is_link($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException("Could not delete {$relative}.");
                }
                $this->stats['deleted']++;
            }
        }

        // Remove folders the previous release created that are now empty, deepest first.
        $currentDirs = array_flip(array_map(self::key(...),
            array_filter(Filesystem::directories($source), fn (string $dir) => !self::isReleaseOnly($dir))));
        $oldDirs = Filesystem::directories($previous);
        usort($oldDirs, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($oldDirs as $relative) {
            if (!isset($currentDirs[self::key($relative)]) && !$this->isExcluded($relative)) {
                @rmdir("{$this->target}/{$relative}"); // fails harmlessly when not empty
            }
        }
    }

    private function copyFile(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create folder {$dir}.");
        }

        $exists = file_exists($to);
        if ($exists && !is_file($to)) {
            throw new RuntimeException("Cannot write {$to}: a folder with that name exists.");
        }
        if ($exists && filesize($from) === filesize($to) && hash_file('xxh128', $from) === hash_file('xxh128', $to)) {
            $this->stats['unchanged']++;
            return;
        }

        $tmp = $to . '.dodo-' . bin2hex(random_bytes(4));
        if (!copy($from, $tmp)) {
            throw new RuntimeException("Could not write {$to}.");
        }
        @chmod($tmp, 0644);

        if (!rename($tmp, $to)) {
            @unlink($tmp);
            throw new RuntimeException("Could not replace {$to}.");
        }

        $this->stats[$exists ? 'updated' : 'added']++;
    }

    private function isExcluded(string $relative): bool
    {
        foreach ($this->excluded as $excluded) {
            if (Path::isWithin('/' . $relative, '/' . $excluded)) {
                return true;
            }
        }

        return false;
    }

    private static function isReleaseOnly(string $relative): bool
    {
        return Path::isWithin('/' . $relative, '/' . self::RELEASE_ONLY);
    }

    /** Comparison key for a relative path; Windows filesystems are case-insensitive. */
    private static function key(string $relative): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($relative) : $relative;
    }
}
