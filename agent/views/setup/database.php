<?php
/**
 * @var list<string> $errors
 * @var array{host: string, port: int, name: string, user: string} $db
 * @var bool $mysqlAvailable
 */
?>
<h2>App database <span class="muted" style="font-weight: 400">(optional)</span></h2>
<p class="muted">The MySQL / MariaDB database your site uses. DodoCD will use it to run your app's migrations
    and take backups before deploys. You can skip this and add it later. The password is stored encrypted.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if (!$mysqlAvailable): ?>
    <div class="alert warn">The <code>pdo_mysql</code> PHP extension is not available, so this step can only be skipped for now.</div>
<?php endif ?>

<form method="post" action="<?= e(dodo_url('/setup/database')) ?>" id="db-form">
    <?= dodo_csrf_field() ?>

    <div class="row">
        <div>
            <label for="db_host">Host</label>
            <input type="text" id="db_host" name="db_host" value="<?= e($db['host']) ?>" required>
        </div>
        <div>
            <label for="db_port">Port</label>
            <input type="number" id="db_port" name="db_port" value="<?= e($db['port']) ?>" min="1" max="65535" required>
        </div>
    </div>

    <label for="db_name">Database name</label>
    <input type="text" id="db_name" name="db_name" value="<?= e($db['name']) ?>" required>

    <label for="db_user">User</label>
    <input type="text" id="db_user" name="db_user" value="<?= e($db['user']) ?>" required autocomplete="off">

    <label for="db_pass">Password</label>
    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">

    <div class="alert" id="db-test-result" role="status" style="margin-top: 16px" hidden></div>

    <div class="actions">
        <button type="submit" name="action" value="save" <?= $mysqlAvailable ? '' : 'disabled' ?>>Save and continue</button>
        <button type="button" class="secondary" id="db-test" <?= $mysqlAvailable ? '' : 'disabled' ?>>Test connection</button>
        <button type="submit" class="secondary" name="action" value="skip" formnovalidate>Skip for now</button>
    </div>
</form>

<script>
    document.getElementById('db-test').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const result = document.getElementById('db-test-result');

        button.disabled = true;
        result.hidden = false;
        result.className = 'alert';
        result.textContent = 'Testing connection…';

        try {
            const response = await fetch(<?= json_encode(dodo_url('/setup/test-db'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>, {
                method: 'POST',
                body: new FormData(document.getElementById('db-form')),
            });
            const data = await response.json();
            result.className = 'alert ' + (data.ok ? 'ok' : 'fail');
            result.textContent = data.message;
        } catch (error) {
            result.className = 'alert fail';
            result.textContent = 'The test request failed. Check your connection and try again.';
        } finally {
            button.disabled = false;
        }
    });
</script>
