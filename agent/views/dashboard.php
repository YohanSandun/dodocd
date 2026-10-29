<?php
/** @var list<array> $sites Each with its current release fields and its recent 'deployments'. */
$running = false;
?>
<?php foreach ($sites as $site): ?>
    <section class="site">
        <h2><?= e($site['name']) ?></h2>
        <p class="muted"><a href="<?= e($site['url']) ?>" rel="noopener" target="_blank"><?= e($site['url']) ?></a></p>

        <table>
            <tr>
                <th>Live release</th>
                <td>
                    <?php if ($site['current_release_id'] === null): ?>
                        <span class="muted">Nothing deployed yet</span>
                    <?php else: ?>
                        #<?= e($site['current_release_id']) ?>
                        <?php if ($site['ref'] !== null): ?> · <?= e($site['ref']) ?><?php endif ?>
                        <?php if ($site['commit_sha'] !== null): ?> · <code><?= e(substr($site['commit_sha'], 0, 7)) ?></code><?php endif ?>
                        <?php if ($site['live_since'] !== null): ?><div class="hint">Since <?= dodo_time($site['live_since']) ?></div><?php endif ?>
                    <?php endif ?>
                </td>
            </tr>
            <tr>
                <th>Deploys to</th>
                <td><code><?= e($site['target_path']) ?></code></td>
            </tr>
            <tr>
                <th>Deploy endpoint</th>
                <td><code><?= e(dodo_origin() . dodo_url('/api/sites/' . $site['slug'] . '/deploy')) ?></code></td>
            </tr>
        </table>

        <h3>Recent deployments</h3>
        <?php if (!$site['deployments']): ?>
            <p class="muted">No deployments yet. Send a zip from CI to the deploy endpoint above; see the README for an example workflow.</p>
        <?php else: ?>
            <div class="scroll">
                <table class="list">
                    <thead>
                    <tr><th>#</th><th>Status</th><th>Release</th><th>Started</th><th>Duration</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($site['deployments'] as $deployment): ?>
                        <?php $running = $running || $deployment['status'] === 'running'; ?>
                        <tr>
                            <td><a href="<?= e(dodo_url('/deployments/' . $deployment['id'])) ?>">#<?= e($deployment['id']) ?></a></td>
                            <td><?= dodo_status_badge($deployment['status']) ?></td>
                            <td class="release">
                                <?php if ($deployment['release_id'] === null): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    #<?= e($deployment['release_id']) ?>
                                    <?php if ($deployment['ref'] !== null): ?> · <?= e($deployment['ref']) ?><?php endif ?>
                                    <?php if ($deployment['commit_sha'] !== null): ?> · <code><?= e(substr($deployment['commit_sha'], 0, 7)) ?></code><?php endif ?>
                                <?php endif ?>
                                <?php if ($deployment['status'] === 'failed' && $deployment['error'] !== null): ?>
                                    <div class="hint fail-text"><?= e($deployment['error']) ?></div>
                                <?php elseif ($deployment['message'] !== null): ?>
                                    <div class="hint"><?= e(explode("\n", $deployment['message'], 2)[0]) ?></div>
                                <?php endif ?>
                            </td>
                            <td><?= dodo_time($deployment['started_at']) ?></td>
                            <td><?= e(dodo_duration($deployment['started_at'], $deployment['finished_at'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php endforeach ?>

<?php if ($running): ?>
    <script>setTimeout(() => location.reload(), 5000);</script>
<?php endif ?>
