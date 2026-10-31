<?php
/**
 * @var string $token
 * @var string $siteName
 */
?>
<h2>DodoCD is installed</h2>
<p>This is the deploy token for <strong><?= e($siteName) ?></strong>. Store it as a secret in your CI,
    for example <code>DODOCD_TOKEN</code> in GitHub Actions.</p>

<?= dodo_view('token', ['token' => $token]) ?>

<div class="actions">
    <a class="button" href="<?= e(dodo_url('/')) ?>">Go to DodoCD</a>
</div>
