<?php
/**
 * @var array $deployment The deployment joined with its site, release, token and user.
 * @var bool $canRollBack Whether the site can be rolled back to this deployment's release.
 */
$d = $deployment;
$stats = $d['stats'] === null ? null : json_decode($d['stats'], true);
?>
<p><a href="<?= e(dodo_url('/')) ?>">← Dashboard</a></p>

<h2>Deployment #<?= e($d['id']) ?> <?= dodo_status_badge($d['status']) ?></h2>
<p class="muted"><?= $d['type'] === 'rollback' ? 'Rollback of' : 'Deploy to' ?> <?= e($d['site_name']) ?></p>

<?php if ($d['error'] !== null): ?>
    <div class="alert fail"><?= e($d['error']) ?></div>
<?php elseif ($d['status'] === 'running'): ?>
    <div class="alert warn">This deployment is still running. The page refreshes until it finishes.</div>
<?php endif ?>

<table>
    <tr>
        <th>Started</th>
        <td><?= dodo_time($d['started_at']) ?></td>
    </tr>
    <tr>
        <th>Duration</th>
        <td><?= e(dodo_duration($d['started_at'], $d['finished_at'])) ?></td>
    </tr>
    <tr>
        <th>Triggered by</th>
        <td>
            <?php if ($d['user_name'] !== null): ?>
                <?= e($d['user_name']) ?>
            <?php elseif ($d['token_name'] !== null): ?>
                <?= e($d['token_name']) ?> <code><?= e($d['token_prefix']) ?>…</code>
            <?php else: ?>
                <span class="muted">—</span>
            <?php endif ?>
        </td>
    </tr>
    <tr>
        <th>Release</th>
        <td>
            <?php if ($d['release_id'] === null): ?>
                <span class="muted">None (failed before the release was created)</span>
            <?php else: ?>
                #<?= e($d['release_id']) ?>
                <?php if ((int) $d['release_id'] === (int) $d['current_release_id']): ?><span class="badge ok">Live</span><?php endif ?>
                <?php if ($d['pruned_at'] !== null): ?><div class="hint">Its files were removed <?= dodo_time($d['pruned_at']) ?> to make room for newer releases.</div><?php endif ?>
                <?php if ($canRollBack): ?>
                    <div class="buttons">
                        <?= dodo_view('_rollback_button', [
                            'release' => ['id' => $d['release_id']],
                            'site' => ['name' => $d['site_name'], 'target_path' => $d['target_path'], 'current_release_id' => $d['current_release_id']],
                        ]) ?>
                    </div>
                <?php endif ?>
            <?php endif ?>
        </td>
    </tr>
    <?php if ($d['release_id'] !== null): ?>
        <tr>
            <th>Ref</th>
            <td><?= $d['ref'] === null ? '<span class="muted">—</span>' : e($d['ref']) ?></td>
        </tr>
        <tr>
            <th>Commit</th>
            <td><?= $d['commit_sha'] === null ? '<span class="muted">—</span>' : '<code>' . e($d['commit_sha']) . '</code>' ?></td>
        </tr>
        <?php if ($d['message'] !== null): ?>
            <tr>
                <th>Message</th>
                <td class="prewrap"><?= e($d['message']) ?></td>
            </tr>
        <?php endif ?>
        <tr>
            <th>Archive</th>
            <td><?= e($d['archive_name']) ?> (<?= e(dodo_format_bytes((int) $d['archive_size'])) ?>, <?= e($d['file_count']) ?> files)</td>
        </tr>
    <?php endif ?>
    <?php if ($stats !== null): ?>
        <tr>
            <th>Files</th>
            <td>
                <?= e($stats['added'] ?? 0) ?> added, <?= e($stats['updated'] ?? 0) ?> updated,
                <?= e($stats['unchanged'] ?? 0) ?> unchanged, <?= e($stats['deleted'] ?? 0) ?> deleted
                <?php if (!empty($stats['skipped'])): ?>, <?= e($stats['skipped']) ?> skipped<?php endif ?>
            </td>
        </tr>
    <?php endif ?>
</table>

<h3>Log</h3>
<?php if ($d['log'] === null || $d['log'] === ''): ?>
    <p class="muted">The log is saved when the deployment finishes.</p>
<?php else: ?>
    <pre class="log"><?= e($d['log']) ?></pre>
<?php endif ?>

<?php if ($d['status'] === 'running'): ?>
    <script>setTimeout(() => location.reload(), 5000);</script>
<?php endif ?>
