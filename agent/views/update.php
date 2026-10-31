<?php
/**
 * @var array|null $check Releases::cached(), refreshed when stale.
 * @var list<array> $history Recent update_installed audit rows.
 * @var bool $staged Whether a package is waiting for review.
 * @var string $uploadLimit
 * @var list<string> $errors
 */
$latest = $check['latest'] ?? null;
$newer = $latest !== null && version_compare($latest['version'], DODO_VERSION, '>');
?>
<p><a href="<?= e(dodo_url('/')) ?>">← Dashboard</a></p>
<h2>Updates</h2>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<table>
    <tr>
        <th>Running version</th>
        <td><?= e(DODO_VERSION) ?></td>
    </tr>
    <tr>
        <th>Latest release</th>
        <td>
            <?php if ($check === null): ?>
                <span class="muted">Not checked yet</span>
            <?php elseif ($check['error'] !== null): ?>
                <span class="fail-text">Could not check: <?= e($check['error']) ?></span>
            <?php elseif ($latest === null): ?>
                <span class="muted">No published releases found</span>
            <?php else: ?>
                <a href="<?= e($latest['url']) ?>" rel="noopener" target="_blank"><?= e($latest['name']) ?></a>
                <?php if ($newer): ?>
                    <span class="badge warn">Update available</span>
                <?php else: ?>
                    <span class="badge ok">Up to date</span>
                <?php endif ?>
                <?php if ($latest['published_at'] !== null): ?>
                    <div class="hint">Published <?= dodo_time(str_replace(['T', 'Z'], [' ', ''], $latest['published_at'])) ?></div>
                <?php endif ?>
            <?php endif ?>
            <?php if ($check !== null): ?>
                <div class="hint">Checked <?= dodo_time($check['checked_at']) ?> ·
                    <form method="post" action="<?= e(dodo_url('/update/check')) ?>" class="inline">
                        <?= dodo_csrf_field() ?>
                        <button type="submit" class="secondary small">Check now</button>
                    </form>
                </div>
            <?php endif ?>
        </td>
    </tr>
</table>

<?php if ($newer): ?>
    <p>To update, download
        <?php if ($latest['package_url'] !== null): ?>
            <a href="<?= e($latest['package_url']) ?>"><code>dodocd-<?= e($latest['version']) ?>.zip</code></a>
        <?php else: ?>
            <code>dodocd-<?= e($latest['version']) ?>.zip</code> from the <a href="<?= e($latest['url']) ?>" rel="noopener" target="_blank">release page</a>
        <?php endif ?>
        and upload it below.</p>
<?php endif ?>

<h3>Install a package</h3>
<?php if ($staged): ?>
    <div class="alert warn">A package is waiting for review. <a href="<?= e(dodo_url('/update/review')) ?>">Review it</a>,
        or upload another one to replace it.</div>
<?php endif ?>
<p class="muted">Upload a <code>dodocd-x.y.z.zip</code> from the <a href="<?= e(\Dodo\Update\Releases::pageUrl()) ?>" rel="noopener" target="_blank">releases page</a>.
    Nothing changes yet: you can review the package first. Before installing, DodoCD backs up its code and database.</p>
<form method="post" action="<?= e(dodo_url('/update/upload')) ?>" enctype="multipart/form-data">
    <?= dodo_csrf_field() ?>
    <label for="package">Package</label>
    <input type="file" id="package" name="package" accept=".zip,application/zip" required>
    <p class="hint">Uploads up to <?= e($uploadLimit) ?>.</p>
    <div class="actions">
        <button type="submit">Upload and review</button>
    </div>
</form>

<?php if ($history): ?>
    <h3>History</h3>
    <div class="scroll">
        <table class="list">
            <thead><tr><th>When</th><th>Update</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <?php $details = json_decode((string) $row['details'], true) ?: []; ?>
                <tr>
                    <td><?= dodo_time($row['created_at']) ?></td>
                    <td class="release">
                        <?= e(($details['from'] ?? '?') . ' → ' . ($details['to'] ?? '?')) ?>
                        <?php if (empty($details['signed'])): ?><span class="badge fail">Unsigned</span><?php endif ?>
                        <?php if (!empty($details['backup'])): ?><div class="hint">Backup: <code><?= e($details['backup']) ?></code></div><?php endif ?>
                    </td>
                    <td><?= $row['user_name'] === null ? '<span class="muted">—</span>' : e($row['user_name']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
