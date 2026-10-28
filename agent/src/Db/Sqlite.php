<?php

declare(strict_types=1);

namespace Dodo\Db;

use PDO;
use PDOException;

/** DodoCD's own database: a single SQLite file in the data directory. */
final class Sqlite
{
    public const FILENAME = 'dodo.sqlite';

    public static function path(string $dataDir): string
    {
        return rtrim($dataDir, '/') . '/' . self::FILENAME;
    }

    public static function open(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        try {
            // WAL lets the dashboard read while a deploy writes. Some shared-host
            // filesystems refuse it, in which case SQLite keeps its default journal.
            $pdo->query('PRAGMA journal_mode = WAL');
        } catch (PDOException) {
        }

        return $pdo;
    }
}
