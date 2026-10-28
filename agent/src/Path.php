<?php

declare(strict_types=1);

namespace Dodo;

/** Filesystem path helpers that behave the same on Linux hosts and Windows dev machines. */
final class Path
{
    /** Forward slashes, no trailing slash, "." and ".." resolved lexically. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $drive = '';

        if (preg_match('#^[A-Za-z]:#', $path)) {
            $drive = strtoupper($path[0]) . ':';
            $path = substr($path, 2);
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $drive . '/' . implode('/', $parts);
    }

    /** Like normalize(), but resolves symlinks when the path exists. */
    public static function canonical(string $path): string
    {
        $real = self::allowedByOpenBasedir($path) ? realpath($path) : false;

        return self::normalize($real !== false ? $real : $path);
    }

    public static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([A-Za-z]:)?[\\\\/]#', $path);
    }

    /** True when $path is $parent itself or somewhere below it. */
    public static function isWithin(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = rtrim(self::normalize($parent), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    /** $path relative to $parent; $path must be within $parent. */
    public static function relative(string $path, string $parent): string
    {
        $path = self::normalize($path);
        $parent = rtrim(self::normalize($parent), '/');

        return ltrim(substr($path, strlen($parent)), '/');
    }

    public static function allowedByOpenBasedir(string $path): bool
    {
        $basedir = (string) ini_get('open_basedir');
        if ($basedir === '') {
            return true;
        }

        foreach (explode(PATH_SEPARATOR, $basedir) as $allowed) {
            if ($allowed !== '' && self::isWithin($path, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
