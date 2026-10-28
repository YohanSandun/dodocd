<?php
/**
 * @var string $token
 * @var string $siteName
 */
?>
<h2>DodoCD is installed</h2>
<p>This is the deploy token for <strong><?= e($siteName) ?></strong>. Store it as a secret in your CI,
    for example <code>DODOCD_TOKEN</code> in GitHub Actions.</p>

<div class="alert warn">Copy it now. Only a hash is stored, so it won't be shown again.</div>

<div class="token">
    <code id="token"><?= e($token) ?></code>
    <button type="button" class="secondary" id="copy-token">Copy</button>
</div>

<div class="actions">
    <a class="button" href="<?= e(dodo_url('/')) ?>">Go to DodoCD</a>
</div>

<script>
    document.getElementById('copy-token').addEventListener('click', async (event) => {
        await navigator.clipboard.writeText(document.getElementById('token').textContent);
        event.currentTarget.textContent = 'Copied';
    });
</script>
