<?php
/**
 * @var array $site
 * @var string|null $token Null when it was already shown.
 */
$endpoint = dodo_origin() . dodo_url('/api/sites/' . $site['slug'] . '/deploy');
?>
<p><a href="<?= e(dodo_url('/')) ?>">← Dashboard</a></p>
<h2><?= e($site['name']) ?> was added</h2>

<?php if ($token === null): ?>
    <p class="muted">The deploy token was shown once, right after the site was added, and only its hash is stored.
        If you lost it, create a new one under <a href="<?= e(dodo_url('/sites/' . $site['slug'] . '/tokens')) ?>">Deploy tokens</a>.</p>
<?php else: ?>
    <p>This is the deploy token for <strong><?= e($site['name']) ?></strong>. Store it as a secret in your CI,
        for example <code>DODOCD_TOKEN</code> in GitHub Actions.</p>
    <?= dodo_view('token', ['token' => $token]) ?>
<?php endif ?>

<p style="margin-top: 16px">Deploy endpoint: <code><?= e($endpoint) ?></code></p>

<div class="actions">
    <a class="button" href="<?= e(dodo_url('/sites/' . $site['slug'] . '/database')) ?>">Set up its database</a>
    <a class="button secondary" href="<?= e(dodo_url('/')) ?>">Go to the dashboard</a>
</div>
