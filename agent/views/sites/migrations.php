<?php
/**
 * @var array $site
 * @var list<array> $releases Kept releases, newest first, with was_live.
 * @var int $releaseId The release whose migrations are shown (0 when the site has none).
 * @var array<string, array{path: string, checksum: string}> $files
 * @var array|null $status MigrationRunner::status() for $files, or null without a database connection.
 * @var bool $hasDatabase
 * @var list<array> $log Recent app_migrations rows.
 * @var list<string> $errors
 */
$base = '/sites/' . $site['slug'] . '/migrations';
$pending = $status['pending'] ?? [];
?>
<?= dodo_view('sites/_nav', ['site' => $site, 'current' => 'migrations']) ?>

<p class="muted">Migrations are the <code>.sql</code> files in a release's <code>.dodocd/migrations</code> folder.
    They run in filename order against the site's database when a release is deployed, before its files are copied.
    Which ones have run is recorded in a <code>dodocd_migrations</code> table in that database.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if (!$hasDatabase): ?>
    <div class="alert warn">This site has no database configured.
        <a href="<?= e(dodo_url('/sites/' . $site['slug'] . '/database')) ?>">Add one</a> to use migrations.</div>
<?php endif ?>

<?php if (!$releases): ?>
    <p class="muted">No releases yet. Deploy one to see its migrations here.</p>
<?php else: ?>
    <form method="get" action="<?= e(dodo_url($base)) ?>">
        <label for="release">Release</label>
        <div class="token">
            <select id="release" name="release">
                <?php foreach ($releases as $release): ?>
                    <?php
                    $tag = (int) $release['id'] === (int) $site['current_release_id'] ? 'live'
                        : ($release['was_live'] ? 'was live' : 'never live');
                    ?>
                    <option value="<?= e($release['id']) ?>" <?= (int) $release['id'] === $releaseId ? 'selected' : '' ?>>
                        #<?= e($release['id']) ?><?= $release['ref'] !== null ? ' · ' . e($release['ref']) : '' ?><?= $release['commit_sha'] !== null ? ' · ' . e(substr($release['commit_sha'], 0, 7)) : '' ?> (<?= e($tag) ?>)
                    </option>
                <?php endforeach ?>
            </select>
            <button type="submit" class="secondary">Show</button>
        </div>
    </form>

    <h3>Migrations in release #<?= e($releaseId) ?></h3>
    <?php if ($status !== null && !$status['tracked']): ?>
        <div class="alert warn">
            This database has no migration history yet.
            <?php if ($status['has_tables']): ?>
                It already has tables, so deploys with migrations are refused until you baseline it below:
                mark the migrations whose changes are already in the database as applied.
            <?php else: ?>
                It's empty, so the next deploy runs every migration.
            <?php endif ?>
        </div>
    <?php endif ?>

    <?php if (!$files): ?>
        <p class="muted">This release has no migrations.</p>
    <?php else: ?>
        <div class="scroll">
            <table class="list">
                <thead><tr><th>File</th><th>Status</th><th>Applied</th></tr></thead>
                <tbody>
                <?php foreach ($files as $name => $file): ?>
                    <?php $applied = $status['applied'][$name] ?? null; ?>
                    <tr>
                        <td><code><?= e($name) ?></code></td>
                        <td>
                            <?php if ($status === null): ?>
                                <span class="muted">—</span>
                            <?php elseif (in_array($name, $status['changed'], true)): ?>
                                <span class="badge fail">Changed</span>
                            <?php elseif ($applied !== null): ?>
                                <span class="badge ok"><?= $applied['baseline'] ? 'Baselined' : 'Applied' ?></span>
                            <?php else: ?>
                                <span class="badge warn">Pending</span>
                            <?php endif ?>
                        </td>
                        <td><?= $applied === null ? '<span class="muted">—</span>' : dodo_time($applied['applied_at']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($status !== null && $status['changed']): ?>
            <p class="hint fail-text">Changed files were edited after they were applied, so deploys of this release are refused.
                Restore their original content and add a new migration instead.</p>
        <?php endif ?>
    <?php endif ?>

    <?php if ($status !== null && $status['missing']): ?>
        <p class="hint">Applied but not in this release: <?= e(implode(', ', $status['missing'])) ?>.</p>
    <?php endif ?>

    <?php if ($status !== null && ($pending || !$status['tracked'])): ?>
        <h3>Baseline</h3>
        <p class="muted">For a database that already has some of these changes, for example one set up before DodoCD.
            Baselining marks migrations as applied <strong>without running them</strong>. The next deploy runs the rest.</p>
        <form method="post" action="<?= e(dodo_url($base . '/baseline')) ?>"
              data-confirm="Mark these migrations as applied without running them?">
            <?= dodo_csrf_field() ?>
            <input type="hidden" name="release_id" value="<?= e($releaseId) ?>">
            <label for="through">Mark as applied</label>
            <select id="through" name="through">
                <?php if (!$status['tracked']): ?>
                    <option value="">Nothing: only start tracking</option>
                <?php endif ?>
                <?php foreach ($pending as $name): ?>
                    <option value="<?= e($name) ?>">Everything up to and including <?= e($name) ?></option>
                <?php endforeach ?>
            </select>
            <div class="actions">
                <button type="submit" class="secondary">Baseline</button>
            </div>
        </form>
    <?php endif ?>
<?php endif ?>

<h3>History</h3>
<?php if (!$log): ?>
    <p class="muted">No migrations have run for this site yet.</p>
<?php else: ?>
    <div class="scroll">
        <table class="list">
            <thead><tr><th>When</th><th>File</th><th>Status</th><th>Deployment</th></tr></thead>
            <tbody>
            <?php foreach ($log as $row): ?>
                <tr>
                    <td><?= dodo_time($row['created_at']) ?></td>
                    <td class="release">
                        <code><?= e($row['name']) ?></code>
                        <?php if ($row['error'] !== null): ?><div class="hint fail-text"><?= e($row['error']) ?></div><?php endif ?>
                    </td>
                    <td><?= dodo_migration_badge($row['status']) ?></td>
                    <td>
                        <?php if ($row['deployment_id'] !== null): ?>
                            <a href="<?= e(dodo_url('/deployments/' . $row['deployment_id'])) ?>">#<?= e($row['deployment_id']) ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
