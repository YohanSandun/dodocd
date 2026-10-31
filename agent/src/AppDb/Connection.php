<?php

declare(strict_types=1);

namespace Dodo\AppDb;

use Dodo\Crypto;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A site's own MySQL/MariaDB database, as opposed to DodoCD's SQLite database.
 * Connection details always use the shape {host, port, name, username, password}.
 */
final class Connection
{
    /** @param array{host: string, port: int, name: string, username: string, password: string} $db */
    public static function open(array $db): PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['username'],
                $db['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                    // Native prepares can't run CREATE TRIGGER/PROCEDURE; migrations never bind parameters.
                    PDO::ATTR_EMULATE_PREPARES => true,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                ],
            );
            // Some hosts turn autocommit off in init_connect.
            $pdo->exec('SET autocommit = 1');
        } catch (PDOException $e) {
            // A new exception without $previous: PHP 8.1 would otherwise keep the password in the trace arguments.
            throw new RuntimeException('Could not connect to the database: ' . $e->getMessage());
        }

        return $pdo;
    }

    /**
     * The site's database with its password decrypted, or null if none is configured.
     *
     * @return array{host: string, port: int, name: string, username: string, password: string}|null
     */
    public static function forSite(PDO $dodo, int $siteId, Crypto $crypto): ?array
    {
        $row = self::row($dodo, $siteId);
        if ($row === null) {
            return null;
        }

        try {
            $password = $crypto->decrypt($row['password_enc']);
        } catch (Throwable) {
            throw new RuntimeException('The stored database password can\'t be decrypted. Was agent/config.php replaced? Enter the password again in the site\'s database settings.');
        }

        return [
            'host' => $row['host'],
            'port' => (int) $row['port'],
            'name' => $row['name'],
            'username' => $row['username'],
            'password' => $password,
        ];
    }

    /** The stored row without decrypting anything, for display. */
    public static function row(PDO $dodo, int $siteId): ?array
    {
        $statement = $dodo->prepare('SELECT * FROM site_databases WHERE site_id = ?');
        $statement->execute([$siteId]);

        return $statement->fetch() ?: null;
    }

    /** @return list<string> */
    public static function validate(array $db): array
    {
        $errors = [];
        if (!extension_loaded('pdo_mysql')) {
            $errors[] = 'The pdo_mysql extension is not available on this server.';
        }
        if ($db['host'] === '' || $db['name'] === '' || $db['username'] === '') {
            $errors[] = 'Host, database name and user are required.';
        }
        if ($db['port'] < 1 || $db['port'] > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }

        return $errors;
    }

    /** @return string|null Null when the connection works, otherwise why it doesn't. */
    public static function test(array $db): ?string
    {
        try {
            self::open($db)->query('SELECT 1');
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Connection details from a submitted form (fields db_host, db_port, db_name, db_user, db_pass). */
    public static function fromPost(): array
    {
        return [
            'host' => trim((string) ($_POST['db_host'] ?? '')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'username' => trim((string) ($_POST['db_user'] ?? '')),
            'password' => (string) ($_POST['db_pass'] ?? ''),
        ];
    }
}
