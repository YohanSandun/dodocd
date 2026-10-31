<?php

declare(strict_types=1);

namespace Dodo\AppDb;

use Closure;
use PDO;
use PDOException;
use Throwable;

/**
 * Runs a release's SQL migrations (.dodocd/migrations/*.sql) against the site's database.
 *
 * What has been applied is recorded in a dodocd_migrations table in that same database.
 * That table is the source of truth: it survives a DodoCD reinstall and stays in step
 * with the schema when the host restores a backup. DodoCD's app_migrations table is
 * only a log for the dashboard.
 */
final class MigrationRunner
{
    public const DIR = '.dodocd/migrations';
    public const TABLE = 'dodocd_migrations';
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]+\.sql$/';

    /**
     * @param array{host: string, port: int, name: string} $db Used to name the lock.
     * @param Closure(string): void $log
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $db,
        private readonly Closure $log,
    ) {
    }

    /**
     * The release's migration files, sorted in the order they run.
     *
     * @return array<string, array{path: string, checksum: string}> Keyed by file name.
     */
    public static function files(string $releaseDir): array
    {
        $dir = $releaseDir . '/' . self::DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            if (!preg_match(self::NAME_PATTERN, $name) || !is_file("{$dir}/{$name}")) {
                throw new MigrationException("\"" . self::DIR . "/{$name}\" is not a valid migration. "
                    . 'Only .sql files named with letters, digits, ".", "_" and "-" are allowed there.');
            }
            $files[$name] = [
                'path' => "{$dir}/{$name}",
                'checksum' => hash('sha256', SqlSplitter::normalize((string) file_get_contents("{$dir}/{$name}"))),
            ];
        }
        uksort($files, 'strcmp');

        return $files;
    }

    /**
     * Compares the files with what the database has applied. Read-only.
     *
     * @param array<string, array{path: string, checksum: string}> $files
     * @return array{tracked: bool, has_tables: bool, applied: array<string, array>, pending: list<string>, changed: list<string>, missing: list<string>}
     */
    public function status(array $files): array
    {
        $tracked = $this->tableExists();
        $applied = [];
        if ($tracked) {
            foreach ($this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY name') as $row) {
                $applied[$row['name']] = $row;
            }
        }

        $pending = $changed = [];
        foreach ($files as $name => $file) {
            if (!isset($applied[$name])) {
                $pending[] = $name;
            } elseif (!hash_equals($applied[$name]['checksum'], $file['checksum'])) {
                $changed[] = $name;
            }
        }

        return [
            'tracked' => $tracked,
            'has_tables' => $tracked || $this->hasTables(),
            'applied' => $applied,
            'pending' => $pending,
            'changed' => $changed,
            'missing' => array_values(array_diff(array_keys($applied), array_keys($files))),
        ];
    }

    /**
     * Applies the pending files in order, each recorded once it has fully run.
     *
     * @param array<string, array{path: string, checksum: string}> $files
     * @param Closure(array): void $onResult Called per file with name, checksum, status, counts, duration and error.
     * @return int Number of files applied.
     */
    public function run(array $files, ?int $releaseId, ?int $deploymentId, Closure $onResult): int
    {
        return $this->withLock(function () use ($files, $releaseId, $deploymentId, $onResult): int {
            $status = $this->status($files);

            if (!$status['tracked'] && $status['has_tables']) {
                throw new MigrationException('This database already has tables but no migration history. '
                    . 'Open the site\'s Migrations page and baseline the migrations that are already applied, then deploy again.');
            }
            if ($status['changed']) {
                throw new MigrationException('These migrations were changed after they were applied: '
                    . implode(', ', $status['changed']) . '. Applied migrations must not be edited; add a new migration instead.');
            }
            foreach ($status['missing'] as $name) {
                ($this->log)("Warning: applied migration {$name} is not in this release.");
            }

            if (!$status['pending']) {
                ($this->log)('Database is up to date: no migrations to run.');
                return 0;
            }

            $this->createTable();
            foreach ($status['pending'] as $name) {
                $this->runFile($name, $files[$name], $releaseId, $deploymentId, $onResult);
            }

            return count($status['pending']);
        });
    }

    /**
     * Marks files as applied without running them, for a database whose schema already
     * has those changes. With $through empty, only starts tracking.
     *
     * @param array<string, array{path: string, checksum: string}> $files
     * @return list<string> The files that were marked.
     */
    public function baseline(array $files, string $through, ?int $releaseId): array
    {
        if ($through !== '' && !isset($files[$through])) {
            throw new MigrationException("Migration {$through} is not in that release.");
        }

        return $this->withLock(function () use ($files, $through, $releaseId): array {
            $this->createTable();
            if ($through === '') {
                return [];
            }

            $applied = $this->status($files)['applied'];
            $marked = [];
            $insert = $this->pdo->prepare('INSERT INTO ' . self::TABLE
                . ' (name, checksum, applied_at, release_id, baseline) VALUES (?, ?, ?, ?, 1)');
            foreach ($files as $name => $file) {
                if (strcmp($name, $through) > 0) {
                    break;
                }
                if (!isset($applied[$name])) {
                    $insert->execute([$name, $file['checksum'], dodo_now(), $releaseId]);
                    $marked[] = $name;
                }
            }

            return $marked;
        });
    }

    private function runFile(string $name, array $file, ?int $releaseId, ?int $deploymentId, Closure $onResult): void
    {
        $statements = SqlSplitter::split((string) file_get_contents($file['path']));
        $total = count($statements);
        $started = microtime(true);
        $ran = 0;
        $result = function (string $status, ?string $error) use ($name, $file, $total, $started, &$ran, $onResult): void {
            $onResult([
                'name' => $name,
                'checksum' => $file['checksum'],
                'status' => $status,
                'statements_run' => $ran,
                'statements_total' => $total,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $error,
            ]);
        };

        ($this->log)("Running migration {$name} ({$total} statements).");
        try {
            foreach ($statements as $index => $statement) {
                try {
                    $this->pdo->query($statement['sql'])->closeCursor();
                } catch (PDOException $e) {
                    throw new MigrationException(sprintf('Migration %s failed at statement %d of %d (line %d): %s',
                        $name, $index + 1, $total, $statement['line'], $e->getMessage()), 422, $ran);
                }
                $ran++;
            }

            if ($this->pdo->inTransaction() || $this->openTransaction()) {
                throw new MigrationException("Migration {$name} started a transaction without committing it.", 422, $ran);
            }

            $this->pdo->prepare('INSERT INTO ' . self::TABLE
                . ' (name, checksum, applied_at, release_id, deployment_id, duration_ms) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $file['checksum'], dodo_now(), $releaseId, $deploymentId,
                    (int) round((microtime(true) - $started) * 1000)]);
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            $error = $e->getMessage();
            if ($ran > 0) {
                $error = rtrim($error, '. ') . ". {$ran} of {$total} statements had already run, so the database may be partly changed.";
            }
            $result('failed', $error);
            throw $e instanceof MigrationException ? new MigrationException($error, $e->status, $ran) : $e;
        }

        $result('applied', null);
        ($this->log)(sprintf('Applied migration %s (%.1fs).', $name, microtime(true) - $started));
    }

    /** A named lock per database, so two sites pointed at the same database by mistake can't migrate at once. */
    private function withLock(Closure $fn): mixed
    {
        $lock = 'dodocd_' . sha1("{$this->db['host']}:{$this->db['port']}/{$this->db['name']}");
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        $got = $statement->fetchColumn();

        if ($got === null || $got === false) {
            throw new MigrationException('Could not take the database migration lock.', 500);
        }
        if ((int) $got !== 1) {
            throw new MigrationException('Migrations are already running against this database. Try again when they finish.', 409);
        }

        try {
            return $fn();
        } finally {
            try {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
            } catch (Throwable) {
            }
        }
    }

    private function tableExists(): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([self::TABLE]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function hasTables(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchColumn() > 0;
    }

    private function createTable(): void
    {
        // VARCHAR(191) ascii keeps the key under the 767-byte limit of older MySQL and MariaDB,
        // and ascii_bin makes names case-sensitive like the strcmp() ordering.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL,
            release_id INT NULL,
            deployment_id INT NULL,
            duration_ms INT NOT NULL DEFAULT 0,
            baseline TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** PDO only knows about transactions it started; a migration's own START TRANSACTION shows up here. */
    private function openTransaction(): bool
    {
        try {
            return (int) $this->pdo->query('SELECT @@in_transaction')->fetchColumn() === 1;
        } catch (PDOException) {
            return false; // MySQL before 5.7 / MariaDB before 10.3 lack @@in_transaction
        }
    }

    private function rollBackQuietly(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
}
