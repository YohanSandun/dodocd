<?php
/**
 * @var list<string> $errors
 * @var array{name: string, url: string, target_path: string} $site
 * @var list<string> $excluded
 */
?>
<h2>Main site</h2>
<p class="muted">The site DodoCD deploys to. You can add more sites, such as subdomains on this server, later.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<form method="post" action="<?= e(dodo_url('/setup/site')) ?>">
    <?= dodo_csrf_field() ?>

    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($site['name']) ?>" required>

    <label for="url">URL</label>
    <input type="url" id="url" name="url" value="<?= e($site['url']) ?>" required placeholder="https://example.com">

    <label for="target_path">Target path</label>
    <input type="text" id="target_path" name="target_path" value="<?= e($site['target_path']) ?>" required spellcheck="false">
    <p class="hint">The folder your site is served from, usually the web root (e.g. <code>public_html</code>). Deploys are written here.</p>

    <?php if ($excluded): ?>
        <div class="alert warn" style="margin-top: 14px">
            DodoCD lives inside this folder, so deploys will always leave these paths alone:
            <?php foreach ($excluded as $path): ?>
                <code><?= e($path) ?></code>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <div class="actions">
        <button type="submit">Save and continue</button>
    </div>
</form>
