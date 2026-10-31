<?php
/**
 * @var \Dodo\Update\Package $package
 * @var array{added: list<string>, changed: list<string>, removed: list<string>, unchanged: int} $changes
 * @var bool $fromPackage Whether the running install has a package manifest (so removed files are known).
 * @var list<string> $errors
 */
$manifest = $package->manifest;
$version = $package->version();
$reinstall = version_compare($version, DODO_VERSION, '==');
?>
<p><a href="<?= e(dodo_url('/update')) ?>">← Updates</a></p>
<h2>Review update</h2>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if (!$package->signed()): ?>
    <div class="alert fail">
        <strong>This package is not signed.</strong> <?= e($package->signature['detail']) ?>
        DodoCD can't tell who built it or whether it was changed. Installing it runs its code on this server with full
        access to every site's files, database passwords and deploy tokens.
        Only install it if you built it yourself or got it from someone you trust.
    </div>
<?php endif ?>

<table>
    <tr>
        <th>Version</th>
        <td>
            <?= e(DODO_VERSION) ?> → <strong><?= e($version) ?></strong>
            <?php if ($reinstall): ?><div class="hint">Same version as running: this reinstalls it, replacing any changed files.</div><?php endif ?>
        </td>
    </tr>
    <tr>
        <th>Signature</th>
        <td>
            <?php if ($package->signed()): ?>
                <span class="badge ok">Valid</span> <?= e($package->signature['detail']) ?>
            <?php else: ?>
                <span class="badge fail"><?= $package->signature['status'] === 'unsigned' ? 'Unsigned' : 'Not checked' ?></span>
            <?php endif ?>
        </td>
    </tr>
    <?php if (!empty($manifest['commit']) || !empty($manifest['built_at'])): ?>
        <tr>
            <th>Built</th>
            <td>
                <?php if (!empty($manifest['built_at'])): ?><?= dodo_time($manifest['built_at']) ?><?php endif ?>
                <?php if (!empty($manifest['commit'])): ?> · commit <code><?= e(substr($manifest['commit'], 0, 7)) ?></code><?php endif ?>
            </td>
        </tr>
    <?php endif ?>
    <tr>
        <th>Files</th>
        <td>
            <?= count($changes['added']) ?> added, <?= count($changes['changed']) ?> changed,
            <?= count($changes['removed']) ?> removed, <?= e($changes['unchanged']) ?> unchanged
            <?php if (!$fromPackage): ?>
                <div class="hint">This install wasn't made from a package, so files that this version no longer has are left in place.</div>
            <?php endif ?>
            <?php foreach (['added' => 'Added', 'changed' => 'Changed', 'removed' => 'Removed'] as $key => $label): ?>
                <?php if ($changes[$key]): ?>
                    <details>
                        <summary><?= e($label) ?></summary>
                        <div class="hint prewrap"><?= e(implode("\n", $changes[$key])) ?></div>
                    </details>
                <?php endif ?>
            <?php endforeach ?>
        </td>
    </tr>
</table>

<?php if ($package->problems): ?>
    <div class="alert fail">
        This package can't be installed on this server:
        <ul>
            <?php foreach ($package->problems as $problem): ?><li><?= e($problem) ?></li><?php endforeach ?>
        </ul>
    </div>
<?php else: ?>
    <p class="muted">Installing takes a few seconds, and deploys and the dashboard are paused meanwhile. It can't start
        while a deploy is running. DodoCD first backs up its code and database to its data directory, and the new
        version's database changes run on the next page load.</p>

    <form method="post" action="<?= e(dodo_url('/update/review')) ?>"
          data-confirm="Install DodoCD <?= e($version) ?><?= $package->signed() ? '' : ' from an UNSIGNED package' ?>?">
        <?= dodo_csrf_field() ?>
        <input type="hidden" name="action" value="install">
        <?php if (!$package->signed()): ?>
            <label class="checkbox">
                <input type="checkbox" name="allow_unsigned" value="1" required>
                I understand this package is unsigned and I trust where it came from.
            </label>
        <?php endif ?>
        <div class="actions">
            <button type="submit" class="<?= $package->signed() ? '' : 'danger' ?>">Install <?= e($version) ?></button>
        </div>
    </form>
<?php endif ?>

<form method="post" action="<?= e(dodo_url('/update/review')) ?>">
    <?= dodo_csrf_field() ?>
    <input type="hidden" name="action" value="discard">
    <div class="actions">
        <button type="submit" class="secondary">Discard package</button>
    </div>
</form>
