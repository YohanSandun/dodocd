<?php

declare(strict_types=1);

namespace Dodo\AppDb;

use PDO;

/**
 * DodoCD's own record of migrations it ran or baselined (the app_migrations table in SQLite).
 * Used for display and warnings only; the site database's dodocd_migrations table decides what runs.
 */
final class MigrationLog
{
    /** @param array{name: string, checksum: string, status: string, statements_run: int, statements_total: int, duration_ms: int, error: ?string} $result */
    public static function record(PDO $pdo, int $siteId, ?int $deploymentId, ?int $releaseId, ?int $userId, array $result): void
    {
        $pdo->prepare('INSERT INTO app_migrations (site_id, deployment_id, release_id, user_id, name, checksum, status,
                                                   statements_run, statements_total, error, duration_ms, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$siteId, $deploymentId, $releaseId, $userId, $result['name'], $result['checksum'], $result['status'],
                $result['statements_run'], $result['statements_total'], $result['error'], $result['duration_ms'], dodo_now()]);
    }

    /** @return list<array> */
    public static function forDeployment(PDO $pdo, int $deploymentId): array
    {
        $statement = $pdo->prepare('SELECT name, status, statements_run, statements_total, duration_ms, error
                                    FROM app_migrations WHERE deployment_id = ? ORDER BY id');
        $statement->execute([$deploymentId]);

        return array_map(fn (array $row) => [
            'name' => $row['name'],
            'status' => $row['status'],
            'statements_run' => (int) $row['statements_run'],
            'statements_total' => (int) $row['statements_total'],
            'duration_ms' => (int) $row['duration_ms'],
            'error' => $row['error'],
        ], $statement->fetchAll());
    }

    /** @return list<array> The site's most recent log rows, newest first. */
    public static function recent(PDO $pdo, int $siteId, int $limit = 30): array
    {
        $statement = $pdo->prepare('SELECT * FROM app_migrations WHERE site_id = ? ORDER BY id DESC LIMIT ' . $limit);
        $statement->execute([$siteId]);

        return $statement->fetchAll();
    }

    /**
     * How a release's migrations differ from what DodoCD has applied to the site's database,
     * for warnings when switching to that release (rollbacks never run migrations).
     *
     * @return array{stay_applied: list<string>, not_applied: list<string>}
     */
    public static function drift(PDO $pdo, int $siteId, string $releaseDir): array
    {
        try {
            $files = array_keys(MigrationRunner::files($releaseDir));
        } catch (MigrationException) {
            $files = [];
        }

        $statement = $pdo->prepare("SELECT DISTINCT name FROM app_migrations WHERE site_id = ? AND status IN ('applied', 'baseline')");
        $statement->execute([$siteId]);
        $applied = $statement->fetchAll(PDO::FETCH_COLUMN);

        return [
            'stay_applied' => array_values(array_diff($applied, $files)),
            'not_applied' => array_values(array_diff($files, $applied)),
        ];
    }
}
