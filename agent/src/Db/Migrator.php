<?php

declare(strict_types=1);

namespace Dodo\Db;

use PDO;
use Throwable;

/**
 * Applies DodoCD's own schema migrations (agent/migrations/*.php) to the SQLite database.
 * Each migration file returns a callable taking the PDO connection, and runs in its own transaction.
 * Not to be confused with the deployed app's database migrations.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> Names of the migrations applied by this run. */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dodo_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL
        )');

        $applied = $this->pdo->query('SELECT name FROM dodo_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = require $file;

            $this->pdo->beginTransaction();
            try {
                $migration($this->pdo);
                $this->pdo->prepare('INSERT INTO dodo_migrations (name, applied_at) VALUES (?, ?)')
                    ->execute([$name, dodo_now()]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            $ran[] = $name;
        }

        return $ran;
    }
}
