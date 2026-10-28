<?php
/**
 * @var list<string> $errors
 * @var array $state
 * @var bool|null $exposed
 * @var string|null $crypto
 * @var list<string> $excluded
 */
$site = $state['site'];
$database = $state['database'];
?>
<h2>Review and install</h2>
<p class="muted">Check the details below. Use the steps above to change anything.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if ($exposed === true): ?>
    <div class="alert fail">Files in the data directory can be downloaded over the web. Your server ignored the
        <code>.htaccess</code> block. <a href="<?= e(dodo_url('/setup/data')) ?>">Choose another location</a> outside the web root.</div>
<?php elseif ($exposed === null): ?>
    <div class="alert warn">DodoCD could not confirm that the data directory is blocked from the web (the server
        refused a request to itself). Check that <code><?= e($state['data_dir']) ?>/.htaccess</code> is honored by your host.</div>
<?php endif ?>

<table>
    <tr><th>Data directory</th><td><code><?= e($state['data_dir']) ?></code></td></tr>
    <tr><th>Admin</th><td><?= e($state['admin']['name']) ?> &lt;<?= e($state['admin']['email']) ?>&gt;</td></tr>
    <tr><th>Site</th><td><?= e($site['name']) ?> · <?= e($site['url']) ?></td></tr>
    <tr><th>Target path</th><td><code><?= e($site['target_path']) ?></code></td></tr>
    <tr>
        <th>Protected paths</th>
        <td>
            <?php if ($excluded): ?>
                <?php foreach ($excluded as $path): ?><code><?= e($path) ?></code> <?php endforeach ?>
            <?php else: ?>
                <span class="muted">None. DodoCD is outside the target path.</span>
            <?php endif ?>
        </td>
    </tr>
    <tr>
        <th>App database</th>
        <td>
            <?php if ($database): ?>
                <?= e($database['user']) ?>@<?= e($database['host']) ?>:<?= e($database['port']) ?> / <?= e($database['name']) ?>
            <?php else: ?>
                <span class="muted">Skipped</span>
            <?php endif ?>
        </td>
    </tr>
    <tr><th>Secret encryption</th><td><?= e($crypto) ?></td></tr>
</table>

<form method="post" action="<?= e(dodo_url('/setup/finish')) ?>" class="actions">
    <?= dodo_csrf_field() ?>
    <button type="submit" <?= $exposed === true ? 'disabled' : '' ?>>Install DodoCD</button>
</form>
