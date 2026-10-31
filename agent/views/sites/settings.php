<?php
/**
 * @var array $site The stored site.
 * @var array{name: string, url: string, target_path: string} $form
 * @var list<string> $errors
 */
?>
<?= dodo_view('sites/_nav', ['site' => $site, 'current' => 'settings']) ?>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<form method="post" action="<?= e(dodo_url('/sites/' . $site['slug'] . '/settings')) ?>">
    <?= dodo_csrf_field() ?>
    <?= dodo_view('sites/_fields', ['site' => $form]) ?>
    <p class="hint">If you change the folder, nothing is moved. The next deploy copies the release into the new folder
        and is treated as the first deploy there, so no files in it are deleted.</p>

    <label>Deploy endpoint</label>
    <code><?= e(dodo_origin() . dodo_url('/api/sites/' . $site['slug'] . '/deploy')) ?></code>
    <p class="hint">The slug <code><?= e($site['slug']) ?></code> stays the same when you rename the site, so CI keeps working.</p>

    <div class="actions">
        <button type="submit">Save</button>
    </div>
</form>
