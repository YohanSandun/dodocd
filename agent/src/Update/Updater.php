<?php

declare(strict_types=1);

namespace Dodo\Update;

use Dodo\Db\Sqlite;
use Dodo\Deploy\Archive;
use Dodo\Deploy\Deployer;
use Dodo\Deploy\Sync;
use Dodo\Filesystem;
use Dodo\Path;
use FilesystemIterator;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Updates DodoCD from a package zip. An uploaded package is first extracted and checked
 * (staged), then shown for review, then applied:
 *
 * 1. Takes the update lock and every site's lock, so nothing deploys meanwhile, and
 *    puts DodoCD in maintenance mode (index.php answers 503).
 * 2. Backs up the code and the SQLite database to {data_dir}/backups/update-…, with RESTORE.txt.
 * 3. Copies the package in with Deploy\Sync: files the installed package listed and the new one
 *    doesn't are deleted, install state (config, storage, data dir) is never touched.
 *    If copying fails, the backed-up code is copied back.
 *
 * The new version's own schema migrations run on the next request, like after a manual upgrade.
 */
final class Updater
{
    public const KEEP_BACKUPS = 3;

    /** Requests are refused for at most this long, in case an update dies without clearing the flag. */
    public const MAINTENANCE_SECONDS = 600;

    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dataDir,
        private readonly string $root = DODO_ROOT,
    ) {
    }

    public static function maintenanceFile(string $dataDir): string
    {
        return "{$dataDir}/tmp/updating";
    }

    public static function inMaintenance(string $dataDir): bool
    {
        $mtime = @filemtime(self::maintenanceFile($dataDir));

        return $mtime !== false && $mtime > time() - self::MAINTENANCE_SECONDS;
    }

    /**
     * Extracts and checks an uploaded package. Replaces any package staged earlier.
     *
     * @return string The staged package's id, for load() and apply().
     * @throws UpdateException|\Dodo\Deploy\DeployException When it isn't a valid package.
     */
    public function stage(string $zipFile): string
    {
        $this->discardAll();

        $id = bin2hex(random_bytes(8));
        $dir = $this->stagingDir($id);
        try {
            Archive::extract($zipFile, $dir);
            Package::load($dir);
        } catch (Throwable $e) {
            Filesystem::deleteDirectory($dir);
            throw $e;
        }

        return $id;
    }

    /** @throws UpdateException When the package is gone or no longer valid. */
    public function load(string $id): Package
    {
        $dir = $this->stagingDir($id);
        if (!is_dir($dir)) {
            throw new UpdateException('That package is no longer staged. Upload it again.');
        }

        return Package::load($dir);
    }

    public function discardAll(): void
    {
        foreach (glob("{$this->dataDir}/tmp/update-*", GLOB_ONLYDIR) ?: [] as $dir) {
            Filesystem::deleteDirectory($dir);
        }
    }

    /**
     * @param bool $allowUnsigned The admin confirmed installing a package without a valid signature.
     * @return array{from: string, to: string, signed: bool, backup: string, stats: array, log: list<string>}
     * @throws UpdateException|\Dodo\Deploy\DeployException 409 when a deploy or another update is running.
     */
    public function apply(string $id, bool $allowUnsigned): array
    {
        $lock = @fopen("{$this->dataDir}/tmp/update.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new UpdateException('Another update is running.');
        }

        try {
            // Checked again here: the staged files are re-verified against the signed manifest.
            $package = $this->load($id);
            if ($package->problems) {
                throw new UpdateException(implode(' ', $package->problems));
            }
            if (!$package->signed() && !$allowUnsigned) {
                throw new UpdateException('This package is not signed. Confirm that you trust it to install it anyway.');
            }

            $from = DODO_VERSION;
            $to = $package->version();
            $result = (new Deployer($this->pdo, $this->dataDir))->withAllSiteLocks(
                fn () => $this->install($package, $from, $to));

            Filesystem::deleteDirectory($this->stagingDir($id));
            $this->pruneBackups();

            return $result + ['from' => $from, 'to' => $to, 'signed' => $package->signed(), 'log' => $this->log];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{backup: string, stats: array} */
    private function install(Package $package, string $from, string $to): array
    {
        $maintenance = self::maintenanceFile($this->dataDir);
        if (file_put_contents($maintenance, $to) === false) {
            throw new RuntimeException("Could not write {$maintenance}.");
        }

        try {
            $backup = $this->dataDir . '/backups/update-' . gmdate('Ymd-His') . "-{$from}-to-{$to}";
            $this->backup($backup, $from, $to);

            $installed = Package::installedFiles($this->root);
            if ($installed === null) {
                $this->log('This install was not made from a package, so files that newer versions no longer have are left in place.');
            }

            $excluded = $this->excludedPaths();
            $sync = new Sync($this->root, $excluded, $this->log(...));
            try {
                $sync->runWithFileList($package->dir, $installed === null ? null : array_keys($installed));
            } catch (Throwable $e) {
                $this->log('Copying failed: ' . $e->getMessage() . ' Copying the backed-up code back.');
                (new Sync($this->root, $excluded, $this->log(...)))
                    ->runWithFileList("{$backup}/code", array_keys($package->manifest['files']));
                throw new UpdateException('The update failed and the previous version was restored: ' . $e->getMessage());
            }

            if (function_exists('opcache_invalidate')) {
                foreach (array_keys($package->manifest['files']) as $path) {
                    @opcache_invalidate("{$this->root}/{$path}", true);
                }
            }

            $this->log(sprintf('Updated DodoCD from %s to %s: %d added, %d updated, %d unchanged, %d deleted.',
                $from, $to, $sync->stats['added'], $sync->stats['updated'], $sync->stats['unchanged'], $sync->stats['deleted']));

            return ['backup' => $backup, 'stats' => $sync->stats];
        } finally {
            @unlink($maintenance);
        }
    }

    private function backup(string $dir, string $from, string $to): void
    {
        $excluded = $this->excludedPaths();
        $files = 0;
        foreach ($this->codeFiles($excluded) as $relative) {
            $target = "{$dir}/code/{$relative}";
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Could not create ' . dirname($target) . '.');
            }
            if (!copy("{$this->root}/{$relative}", $target)) {
                throw new RuntimeException("Could not back up {$relative}.");
            }
            $files++;
        }

        $this->backupDatabase("{$dir}/" . Sqlite::FILENAME);

        $root = Path::normalize($this->root);
        $data = Path::normalize($this->dataDir);
        $now = dodo_now();
        $notes = <<<TXT
            Backup made before updating DodoCD from {$from} to {$to}, on {$now} UTC.

            To go back to {$from}:
            1. Replace the contents of {$root} with the contents of the code folder here.
               Keep {$root}/agent/config.php (it is not in this backup, and the update didn't change it)
               and {$root}/storage if it exists.
            2. Replace {$data}/dodo.sqlite with the dodo.sqlite here, and delete
               dodo.sqlite-wal and dodo.sqlite-shm next to it if they exist.
            Deployments made after the update are not in this database copy.

            TXT;
        file_put_contents("{$dir}/RESTORE.txt", $notes);

        $this->log("Backed up {$files} files and the database to {$dir}.");
    }

    /**
     * Copies the database table by table inside one read transaction, which gives a consistent
     * snapshot while other requests keep reading, and works on SQLite 3.7.17 (no VACUUM INTO).
     */
    private function backupDatabase(string $file): void
    {
        $schema = $this->pdo->query("SELECT type, name, sql FROM sqlite_master
                                     WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
                                     ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name")->fetchAll();
        $tables = array_column(array_filter($schema, fn (array $row) => $row['type'] === 'table'), 'name');

        $copy = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($schema as $row) {
            if ($row['type'] === 'table') {
                $copy->exec($row['sql']);
            }
        }
        $copy = null;

        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $attach = $this->pdo->prepare('ATTACH DATABASE ? AS backup');
        $attach->execute([$file]);
        try {
            $this->pdo->exec('BEGIN');
            foreach ($tables as $table) {
                $quoted = '"' . str_replace('"', '""', $table) . '"';
                $this->pdo->exec("INSERT INTO backup.{$quoted} SELECT * FROM main.{$quoted}");
            }
            if ($this->pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn()) {
                // The inserts above already added rows (with the highest id, not the highest ever used).
                $this->pdo->exec('DELETE FROM backup.sqlite_sequence');
                $this->pdo->exec('INSERT INTO backup.sqlite_sequence SELECT * FROM main.sqlite_sequence');
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->pdo->exec('DETACH DATABASE backup');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        // Indexes, triggers and views last, so the copy above doesn't have to maintain them.
        $copy = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($schema as $row) {
            if ($row['type'] !== 'table') {
                $copy->exec($row['sql']);
            }
        }
    }

    /**
     * DodoCD's files, relative to its folder, without install state, the data dir and .git.
     *
     * @param list<string> $excluded
     * @return list<string>
     */
    private function codeFiles(array $excluded): array
    {
        $root = $this->root;
        $skip = function (SplFileInfo $info) use ($root, $excluded): bool {
            $relative = Path::relative($info->getPathname(), $root);
            foreach ([...$excluded, '.git'] as $path) {
                if (Path::isWithin('/' . $relative, '/' . $path)) {
                    return false;
                }
            }

            return true;
        };

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $info) => $skip($info),
        ));

        $files = [];
        foreach ($iterator as $info) {
            $files[] = Path::relative($info->getPathname(), $root);
        }
        sort($files);

        return $files;
    }

    /** @return list<string> Paths relative to DodoCD's folder that an update never touches. */
    private function excludedPaths(): array
    {
        $excluded = Package::INSTALL_STATE;
        if (Path::isWithin(Path::canonical($this->dataDir), Path::canonical($this->root))) {
            $excluded[] = Path::relative(Path::canonical($this->dataDir), Path::canonical($this->root));
        }

        return array_values(array_unique($excluded));
    }

    private function pruneBackups(): void
    {
        $backups = glob("{$this->dataDir}/backups/update-*", GLOB_ONLYDIR) ?: [];
        rsort($backups);

        foreach (array_slice($backups, self::KEEP_BACKUPS) as $dir) {
            try {
                Filesystem::deleteDirectory($dir);
            } catch (Throwable $e) {
                $this->log('Could not delete the old backup ' . basename($dir) . ': ' . $e->getMessage());
            }
        }
    }

    private function stagingDir(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
            throw new UpdateException('That package is no longer staged. Upload it again.');
        }

        return "{$this->dataDir}/tmp/update-{$id}";
    }

    private function log(string $message): void
    {
        $this->log[] = $message;
    }
}
