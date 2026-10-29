<?php

declare(strict_types=1);

namespace Dodo\Deploy;

use RuntimeException;
use ZipArchive;

/** Validates an uploaded zip before anything is written, then extracts it. */
final class Archive
{
    public const MAX_ENTRIES = 100_000;
    public const MAX_UNCOMPRESSED_BYTES = 1024 ** 3;

    private const UNIX_FILE_TYPE_MASK = 0170000;
    private const UNIX_SYMLINK = 0120000;

    /** @return int Number of files (not directories) extracted. */
    public static function extract(string $zipFile, string $destination): int
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipFile, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new DeployException("The upload is not a valid zip archive (zip error {$opened}).");
        }

        try {
            $files = self::validate($zip);

            if (!is_dir($destination) && !mkdir($destination, 0750, true)) {
                throw new RuntimeException("Could not create {$destination}.");
            }
            if (!$zip->extractTo($destination)) {
                throw new RuntimeException('Extracting the archive failed: ' . $zip->getStatusString());
            }
        } finally {
            $zip->close();
        }

        return $files;
    }

    private static function validate(ZipArchive $zip): int
    {
        if ($zip->numFiles === 0) {
            throw new DeployException('The archive is empty.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new DeployException('The archive has more than ' . self::MAX_ENTRIES . ' entries.');
        }

        $files = 0;
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            self::assertSafeName($name);

            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if ($opsys === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & self::UNIX_FILE_TYPE_MASK) === self::UNIX_SYMLINK) {
                throw new DeployException("The archive contains a symlink ({$name}), which is not allowed.");
            }

            // Guards against zip bombs: the declared size is checked before extracting.
            $totalBytes += $stat['size'];
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new DeployException('The archive expands to more than ' . dodo_format_bytes(self::MAX_UNCOMPRESSED_BYTES) . '.');
            }

            if (!str_ends_with($name, '/')) {
                $files++;
            }
        }

        return $files;
    }

    /** Rejects entries that could be written outside the extraction folder ("zip slip"). */
    private static function assertSafeName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new DeployException('The archive contains an entry with an invalid name.');
        }
        if (str_contains($name, '\\')) {
            throw new DeployException("The archive entry \"{$name}\" uses backslashes. Create the zip with forward-slash paths.");
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
            throw new DeployException("The archive entry \"{$name}\" has an absolute path.");
        }
        if (in_array('..', explode('/', $name), true)) {
            throw new DeployException("The archive entry \"{$name}\" points outside the archive.");
        }
    }
}
