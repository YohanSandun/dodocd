<?php

declare(strict_types=1);

namespace Dodo\Deploy;

use Dodo\Filesystem;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Runs one deployment: store the upload, extract it into a new release, copy the release
 * into the site's target folder, then record the result. A rollback copies an earlier,
 * still-kept release back in the same way. Deployments for the same site never overlap,
 * and a failed copy restores the previous release where possible.
 */
final class Deployer
{
    /** Release folders kept on disk per site, including the live one, so they can be rolled back to. */
    public const KEEP_RELEASES = 5;

    private float $startedAt;
    /** @var list<string> */
    private array $log = [];
    /** File counts from the copy, recorded even when it fails partway. */
    private ?array $stats = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dataDir,
    ) {
    }

    /**
     * @param array{name: string, tmp_name: string} $upload An entry from $_FILES.
     * @param array{ref: ?string, commit: ?string, message: ?string} $meta Optional details sent by CI.
     * @return array Summary of the finished deployment.
     */
    public function deploy(int $siteId, ?int $tokenId, array $upload, array $meta): array
    {
        $this->startedAt = microtime(true);
        $lock = $this->lock($siteId);

        $deploymentId = null;
        $zip = null;
        $workDir = null;

        try {
            $site = $this->site($siteId);
            $this->failInterrupted($siteId);
            $deploymentId = $this->createDeployment($siteId, 'deploy', null, $tokenId, null);
            $this->log("Deployment #{$deploymentId} started for site \"{$site['slug']}\".");

            $zip = "{$this->dataDir}/uploads/deploy-{$deploymentId}.zip";
            if (!move_uploaded_file($upload['tmp_name'], $zip)) {
                throw new RuntimeException('Could not store the uploaded archive.');
            }
            $size = (int) filesize($zip);
            $sha256 = hash_file('sha256', $zip);
            $this->log(sprintf('Received %s (%s, sha256 %s).', $upload['name'], dodo_format_bytes($size), substr($sha256, 0, 12)));

            $workDir = "{$this->dataDir}/tmp/deploy-{$deploymentId}";
            $fileCount = Archive::extract($zip, $workDir);
            $this->log("Extracted {$fileCount} files.");
            $this->warnAboutWrapperFolder($workDir);
            @unlink($zip);
            $zip = null;

            $releaseId = $this->createRelease($siteId, $upload['name'], $size, $sha256, $fileCount, $meta);
            $releaseDir = $this->releaseDir($siteId, $releaseId);
            if (!is_dir(dirname($releaseDir)) && !mkdir(dirname($releaseDir), 0750, true)) {
                throw new RuntimeException('Could not create ' . dirname($releaseDir) . '.');
            }
            if (!rename($workDir, $releaseDir)) {
                throw new RuntimeException("Could not move the release into {$releaseDir}.");
            }
            $workDir = null;
            $this->pdo->prepare('UPDATE deployments SET release_id = ? WHERE id = ?')->execute([$releaseId, $deploymentId]);
            $this->log("Created release #{$releaseId}.");

            $this->activate($site, $releaseId);
            $this->pruneReleases($siteId, $releaseId);
            $this->log(sprintf('Release #%d is live (%.1fs).', $releaseId, microtime(true) - $this->startedAt));

            $this->finish($deploymentId, 'success');
            $this->pdo->prepare('INSERT INTO audit_log (site_id, action, details, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$siteId, 'deploy', json_encode(['deployment_id' => $deploymentId, 'release_id' => $releaseId]), dodo_now()]);

            return $this->summary($deploymentId);
        } catch (Throwable $e) {
            if ($zip !== null) {
                @unlink($zip);
            }
            if ($workDir !== null) {
                try {
                    Filesystem::deleteDirectory($workDir);
                } catch (Throwable) {
                }
            }

            $exception = $e instanceof DeployException ? $e : new DeployException($e->getMessage(), 500, $e);
            if ($deploymentId !== null) {
                $this->log('Deployment failed: ' . $e->getMessage());
                $this->finish($deploymentId, 'failed', $e->getMessage());
                $exception->deployment = $this->summary($deploymentId);
            }

            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Makes an earlier release live again by copying it over the current one. Only releases
     * that were live before and whose folder is still kept can be rolled back to.
     *
     * @return array Summary of the finished rollback.
     */
    public function rollback(int $siteId, int $releaseId, ?int $userId, ?int $tokenId = null): array
    {
        $this->startedAt = microtime(true);
        $lock = $this->lock($siteId);
        $deploymentId = null;

        try {
            $site = $this->site($siteId);
            $this->failInterrupted($siteId);
            $this->checkRollbackTarget($site, $releaseId);

            $deploymentId = $this->createDeployment($siteId, 'rollback', $releaseId, $tokenId, $userId);
            $this->log("Rollback #{$deploymentId} started for site \"{$site['slug']}\": "
                . "release #{$site['current_release_id']} → release #{$releaseId}.");

            $this->activate($site, $releaseId);
            $this->log(sprintf('Release #%d is live (%.1fs).', $releaseId, microtime(true) - $this->startedAt));

            $this->finish($deploymentId, 'success');
            $this->pdo->prepare('INSERT INTO audit_log (user_id, site_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $siteId, 'rollback', json_encode([
                    'deployment_id' => $deploymentId,
                    'from_release_id' => $site['current_release_id'] === null ? null : (int) $site['current_release_id'],
                    'release_id' => $releaseId,
                ]), dodo_now()]);

            return $this->summary($deploymentId);
        } catch (Throwable $e) {
            $exception = $e instanceof DeployException ? $e : new DeployException($e->getMessage(), 500, $e);
            if ($deploymentId !== null) {
                $this->log('Rollback failed: ' . $e->getMessage());
                $this->finish($deploymentId, 'failed', $e->getMessage());
                $exception->deployment = $this->summary($deploymentId);
            }

            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Releases of a site that can be rolled back to: not live now, were live before and still
     * kept on disk. Most recently live first. rollback() checks the same rules.
     *
     * @return list<array> Release rows, plus 'live_at': when the release last went live.
     */
    public static function rollbackTargets(PDO $pdo, int $siteId): array
    {
        $statement = $pdo->prepare("SELECT r.*, MAX(d.finished_at) AS live_at
                                    FROM releases r
                                    JOIN sites s ON s.id = r.site_id
                                    JOIN deployments d ON d.release_id = r.id AND d.status = 'success'
                                    WHERE r.site_id = ? AND r.pruned_at IS NULL
                                      AND (s.current_release_id IS NULL OR r.id <> s.current_release_id)
                                    GROUP BY r.id
                                    ORDER BY MAX(d.id) DESC");
        $statement->execute([$siteId]);

        return $statement->fetchAll();
    }

    private function checkRollbackTarget(array $site, int $releaseId): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM releases WHERE id = ? AND site_id = ?');
        $statement->execute([$releaseId, $site['id']]);
        $release = $statement->fetch() ?: throw new DeployException("Release #{$releaseId} does not belong to this site.", 404);

        if ((int) $site['current_release_id'] === $releaseId) {
            throw new DeployException("Release #{$releaseId} is already live.", 422);
        }
        if ($release['pruned_at'] !== null) {
            throw new DeployException("Release #{$releaseId} was removed to make room for newer releases, so it can't be rolled back to.", 422);
        }

        $statement = $this->pdo->prepare("SELECT 1 FROM deployments WHERE release_id = ? AND status = 'success'");
        $statement->execute([$releaseId]);
        if ($statement->fetchColumn() === false) {
            throw new DeployException("Release #{$releaseId} was never live, so it can't be rolled back to.", 422);
        }

        if (!is_dir($this->releaseDir((int) $site['id'], $releaseId))) {
            throw new DeployException("The folder for release #{$releaseId} is missing from the data directory.", 422);
        }
    }

    /**
     * Copies a release into the site's target folder and records it as live. Files the live
     * release had and this one doesn't are deleted. If copying fails, the live release is
     * copied back and the exception is rethrown.
     */
    private function activate(array $site, int $releaseId): void
    {
        $releaseDir = $this->releaseDir((int) $site['id'], $releaseId);
        $previousDir = $this->currentReleaseDir($site);
        $excluded = json_decode($site['excluded_paths'], true) ?: [];

        $this->log("Copying files into {$site['target_path']}.");
        $sync = new Sync($site['target_path'], $excluded, $this->log(...));
        try {
            $sync->run($releaseDir, $previousDir);
        } catch (Throwable $e) {
            $this->stats = $sync->stats;
            $this->log('Copying failed: ' . $e->getMessage());
            $this->restore($site, $previousDir, $releaseDir, $excluded);
            throw $e;
        }
        $this->stats = $sync->stats;
        $this->log(sprintf('Files: %d added, %d updated, %d unchanged, %d deleted.',
            $this->stats['added'], $this->stats['updated'], $this->stats['unchanged'], $this->stats['deleted']));

        $this->pdo->prepare('UPDATE sites SET current_release_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$releaseId, dodo_now(), $site['id']]);
    }

    /** @return resource */
    private function lock(int $siteId)
    {
        $file = "{$this->dataDir}/tmp/site-{$siteId}.lock";
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new DeployException("Could not open the deploy lock {$file}.", 500);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new DeployException('Another deployment is running for this site. Try again when it finishes.', 409);
        }

        return $handle;
    }

    private function site(int $siteId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $statement->execute([$siteId]);

        return $statement->fetch() ?: throw new DeployException('Site not found.', 404);
    }

    /** We hold the site lock, so any deployment still marked running died without finishing. */
    private function failInterrupted(int $siteId): void
    {
        $this->pdo->prepare("UPDATE deployments SET status = 'failed', error = ?, finished_at = ?
                             WHERE site_id = ? AND status = 'running'")
            ->execute(['Interrupted: the PHP process stopped before the deployment finished.', dodo_now(), $siteId]);
    }

    private function createDeployment(int $siteId, string $type, ?int $releaseId, ?int $tokenId, ?int $userId): int
    {
        $this->pdo->prepare("INSERT INTO deployments (site_id, release_id, token_id, user_id, type, status, started_at)
                             VALUES (?, ?, ?, ?, ?, 'running', ?)")
            ->execute([$siteId, $releaseId, $tokenId, $userId, $type, dodo_now()]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createRelease(int $siteId, string $archiveName, int $size, string $sha256, int $fileCount, array $meta): int
    {
        $this->pdo->prepare('INSERT INTO releases (site_id, source, archive_name, archive_size, archive_sha256, file_count,
                                                   ref, commit_sha, message, path, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$siteId, 'upload', $archiveName, $size, $sha256, $fileCount,
                $meta['ref'], $meta['commit'], $meta['message'], '', dodo_now()]);
        $releaseId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE releases SET path = ? WHERE id = ?')
            ->execute(["releases/{$siteId}/{$releaseId}", $releaseId]);

        return $releaseId;
    }

    private function releaseDir(int $siteId, int $releaseId): string
    {
        return "{$this->dataDir}/releases/{$siteId}/{$releaseId}";
    }

    /** The live release's folder, used to work out which files to delete. Null on the first deploy. */
    private function currentReleaseDir(array $site): ?string
    {
        if ($site['current_release_id'] === null) {
            $this->log('First deployment: existing files in the target are kept.');
            return null;
        }

        $dir = $this->releaseDir((int) $site['id'], (int) $site['current_release_id']);
        if (!is_dir($dir)) {
            $this->log("Warning: the live release folder is missing, so files removed since then won't be deleted.");
            return null;
        }

        return $dir;
    }

    /** Best effort: copy the previous release back over a half-finished copy. */
    private function restore(array $site, ?string $previousDir, string $failedDir, array $excluded): void
    {
        if ($previousDir === null) {
            $this->log('No previous release to restore. The site may contain a mix of old and new files.');
            return;
        }

        $this->log('Restoring the previous release.');
        try {
            (new Sync($site['target_path'], $excluded, $this->log(...)))->run($previousDir, $failedDir);
            $this->log('Previous release restored.');
        } catch (Throwable $e) {
            $this->log('Restoring failed: ' . $e->getMessage() . '. The site may contain a mix of old and new files.');
        }
    }

    private function pruneReleases(int $siteId, int $liveReleaseId): void
    {
        $statement = $this->pdo->prepare('SELECT id, path FROM releases
                                          WHERE site_id = ? AND id <> ? AND pruned_at IS NULL ORDER BY id DESC');
        $statement->execute([$siteId, $liveReleaseId]);
        $old = array_slice($statement->fetchAll(), self::KEEP_RELEASES - 1);

        foreach ($old as $release) {
            try {
                Filesystem::deleteDirectory("{$this->dataDir}/{$release['path']}");
                $this->pdo->prepare('UPDATE releases SET pruned_at = ? WHERE id = ?')->execute([dodo_now(), $release['id']]);
                $this->log("Removed old release #{$release['id']}.");
            } catch (Throwable $e) {
                $this->log("Warning: could not remove old release #{$release['id']}: " . $e->getMessage());
            }
        }
    }

    /** A zip of the "dist" folder instead of its contents would deploy everything under dist/. */
    private function warnAboutWrapperFolder(string $dir): void
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir("{$dir}/{$entries[0]}")) {
            $this->log("Warning: the archive has a single top-level folder \"{$entries[0]}/\", so files will be "
                . "deployed inside {$entries[0]}/. Zip the folder's contents instead if that's not intended.");
        }
    }

    private function finish(int $deploymentId, string $status, ?string $error = null): void
    {
        $this->pdo->prepare('UPDATE deployments SET status = ?, stats = ?, log = ?, error = ?, finished_at = ? WHERE id = ?')
            ->execute([
                $status,
                $this->stats === null ? null : json_encode($this->stats),
                implode("\n", $this->log),
                $error,
                dodo_now(),
                $deploymentId,
            ]);
    }

    private function summary(int $deploymentId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM deployments WHERE id = ?');
        $statement->execute([$deploymentId]);
        $row = $statement->fetch();

        return [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'release_id' => $row['release_id'] === null ? null : (int) $row['release_id'],
            'stats' => $row['stats'] === null ? null : json_decode($row['stats'], true),
            'error' => $row['error'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
            'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'log' => $this->log,
        ];
    }

    private function log(string $message): void
    {
        $this->log[] = sprintf('[%6.2fs] %s', microtime(true) - $this->startedAt, $message);
    }
}
