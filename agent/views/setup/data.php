<?php
/**
 * @var list<string> $errors
 * @var string $dataDir
 * @var bool $insideWebroot
 * @var string|null $outsideCandidate
 */
?>
<h2>Data directory</h2>
<p>DodoCD keeps its own database, uploaded archives, releases, backups and logs here.
    It is safest <strong>outside</strong> the public web root, where nothing in it can be downloaded.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if ($insideWebroot): ?>
    <div class="alert warn">
        This location is inside the web root. DodoCD will block web access to it with an
        <code>.htaccess</code> file and check that the block works, but a folder outside the web root is safer.
        <?php if ($outsideCandidate !== null && $outsideCandidate !== $dataDir): ?>
            PHP could not use <code><?= e($outsideCandidate) ?></code>. Your host may allow another folder next to the web root.
        <?php endif ?>
    </div>
<?php endif ?>

<form method="post" action="<?= e(dodo_url('/setup/data')) ?>">
    <?= dodo_csrf_field() ?>
    <label for="data_dir">Path</label>
    <input type="text" id="data_dir" name="data_dir" value="<?= e($dataDir) ?>" required spellcheck="false">
    <p class="hint">An absolute path. It is created if missing, with the subfolders
        <?= e(implode(', ', \Dodo\Installer\DataDir::SUBDIRS)) ?>.</p>

    <div class="actions">
        <button type="submit">Save and continue</button>
    </div>
</form>
