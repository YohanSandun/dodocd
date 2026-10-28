<?php

declare(strict_types=1);

namespace Dodo\Installer;

use RuntimeException;

/**
 * Stops a second browser from running setup while one is in progress.
 * The setup URL is public until install finishes, so the first visitor to
 * submit a step holds the lock until it expires or setup completes.
 */
final class SetupLock
{
    public const TTL_SECONDS = 1800;

    public static function file(): string
    {
        return DODO_AGENT . '/setup.lock';
    }

    /** Claims or refreshes the lock for $owner. False when another browser holds it. */
    public static function claim(string $owner): bool
    {
        $handle = @fopen(self::file(), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open ' . self::file() . '. Check folder permissions.');
        }

        try {
            flock($handle, LOCK_EX);

            $ownerHash = hash('sha256', $owner);
            $current = json_decode((string) stream_get_contents($handle), true);
            if (is_array($current)
                && ($current['owner'] ?? null) !== $ownerHash
                && ($current['expires'] ?? 0) > time()) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['owner' => $ownerHash, 'expires' => time() + self::TTL_SECONDS]));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function release(): void
    {
        @unlink(self::file());
    }
}
