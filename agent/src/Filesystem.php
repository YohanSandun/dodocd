<?php

declare(strict_types=1);

namespace Dodo;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Filesystem
{
    /** @return list<string> Paths of all files below $dir, relative to it, with forward slashes. */
    public static function files(string $dir): array
    {
        $files = [];
        foreach (self::entries($dir) as $relative => $info) {
            if (!$info->isDir()) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> Paths of all directories below $dir, relative to it, with forward slashes. */
    public static function directories(string $dir): array
    {
        $dirs = [];
        foreach (self::entries($dir) as $relative => $info) {
            if ($info->isDir() && !$info->isLink()) {
                $dirs[] = $relative;
            }
        }
        sort($dirs);

        return $dirs;
    }

    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $info) {
            /** @var SplFileInfo $info */
            $ok = $info->isDir() && !$info->isLink() ? rmdir($info->getPathname()) : unlink($info->getPathname());
            if (!$ok) {
                throw new RuntimeException('Could not delete ' . $info->getPathname() . '.');
            }
        }

        if (!rmdir($dir)) {
            throw new RuntimeException("Could not delete {$dir}.");
        }
    }

    /** @return iterable<string, SplFileInfo> */
    private static function entries(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $info) {
            yield Path::relative($info->getPathname(), $dir) => $info;
        }
    }
}
