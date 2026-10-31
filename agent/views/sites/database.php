<?php
/**
 * @var array $site
 * @var array{host: string, port: int, name: string, username: string} $form
 * @var bool $hasDatabase
 * @var list<string> $sharedWith Other sites using the same database.
 * @var bool $mysqlAvailable
 * @var list<string> $errors
 */
?>
<?= dodo_view('sites/_nav', ['site' => $site, 'current' => 'database']) ?>

<p class="muted">The MySQL / MariaDB database this site uses. Migrations in a release's
    <code>.dodocd/migrations</code> folder run against it before the files are copied. The password is stored encrypted.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if (!$mysqlAvailable): ?>
    <div class="alert warn">The <code>pdo_mysql</code> PHP extension is not available on this server.</div>
<?php endif ?>
<?php if ($sharedWith): ?>
    <div class="alert warn">The same database is also set for <?= e(implode(', ', $sharedWith)) ?>. Sites that share a
        database share its migration history too, so their migrations must not overlap.</div>
<?php endif ?>

<form method="post" action="<?= e(dodo_url('/sites/' . $site['slug'] . '/database')) ?>" id="db-form">
    <?= dodo_csrf_field() ?>

    <div class="row">
        <div>
            <label for="db_host">Host</label>
            <input type="text" id="db_host" name="db_host" value="<?= e($form['host']) ?>" required>
        </div>
        <div>
            <label for="db_port">Port</label>
            <input type="number" id="db_port" name="db_port" value="<?= e($form['port']) ?>" min="1" max="65535" required>
        </div>
    </div>

    <label for="db_name">Database name</label>
    <input type="text" id="db_name" name="db_name" value="<?= e($form['name']) ?>" required>

    <label for="db_user">User</label>
    <input type="text" id="db_user" name="db_user" value="<?= e($form['username']) ?>" required autocomplete="off">

    <label for="db_pass">Password</label>
    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
    <?php if ($hasDatabase): ?>
        <p class="hint">Leave empty to keep the stored password. You have to enter it again if you change the host, port or user.</p>
    <?php endif ?>

    <div class="alert" id="db-test-result" role="status" style="margin-top: 16px" hidden></div>

    <div class="actions">
        <button type="submit" name="action" value="save" <?= $mysqlAvailable ? '' : 'disabled' ?>>Save</button>
        <button type="button" class="secondary" id="db-test" <?= $mysqlAvailable ? '' : 'disabled' ?>>Test connection</button>
    </div>
</form>

<?php if ($hasDatabase): ?>
    <form method="post" action="<?= e(dodo_url('/sites/' . $site['slug'] . '/database')) ?>"
          data-confirm="Remove the database settings for <?= e($site['name']) ?>? The database itself is not changed.">
        <?= dodo_csrf_field() ?>
        <input type="hidden" name="action" value="remove">
        <div class="actions">
            <button type="submit" class="danger">Remove database settings</button>
        </div>
    </form>
<?php endif ?>

<?= dodo_view('_db_test', ['testUrl' => '/sites/' . $site['slug'] . '/database/test']) ?>
