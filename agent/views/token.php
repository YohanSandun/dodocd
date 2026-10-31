<?php
/**
 * A deploy token shown once, with a copy button.
 *
 * @var string $token
 */
?>
<div class="alert warn">Copy it now. Only a hash is stored, so it won't be shown again.</div>

<div class="token">
    <code id="token"><?= e($token) ?></code>
    <button type="button" class="secondary" id="copy-token">Copy</button>
</div>

<script>
    document.getElementById('copy-token').addEventListener('click', async (event) => {
        await navigator.clipboard.writeText(document.getElementById('token').textContent);
        event.currentTarget.textContent = 'Copied';
    });
</script>
