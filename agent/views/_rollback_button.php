<?php
/**
 * POST form that rolls a site back to one of its kept releases.
 *
 * @var array $release The release row (id, ref, commit_sha), optionally with 'drift' from MigrationLog::drift().
 * @var array $site The site row (name, target_path, current_release_id).
 * @var string|null $label Button text; defaults to "Roll back to #N" or "Redeploy #N" for a newer release.
 */
$newer = $site['current_release_id'] !== null && (int) $release['id'] > (int) $site['current_release_id'];
$label ??= ($newer ? 'Redeploy' : 'Roll back to') . ' #' . $release['id']
    . (empty($release['commit_sha']) ? '' : ' · ' . substr($release['commit_sha'], 0, 7));
$confirm = sprintf('%s %s to release #%d? Files in %s will be replaced.',
    $newer ? 'Switch' : 'Roll back', $site['name'], $release['id'], $site['target_path']);

// Switching releases never runs or undoes migrations, so say what that means for the database.
$drift = $release['drift'] ?? ['stay_applied' => [], 'not_applied' => []];
if ($drift['stay_applied']) {
    $count = count($drift['stay_applied']);
    $confirm .= "\n\n" . ($count === 1 ? '1 database migration that this release doesn\'t have stays applied: '
            : "{$count} database migrations that this release doesn't have stay applied: ")
        . implode(', ', $drift['stay_applied']) . '. Rollbacks don\'t undo database changes.';
}
if ($drift['not_applied']) {
    $confirm .= "\n\nThis release has migrations that aren't applied and won't be run: "
        . implode(', ', $drift['not_applied']) . '. Deploy it again to run them.';
}
?>
<form class="inline" method="post" action="<?= e(dodo_url('/releases/' . $release['id'] . '/rollback')) ?>"
      data-confirm="<?= e($confirm) ?>">
    <?= dodo_csrf_field() ?>
    <button type="submit" class="secondary small"><?= e($label) ?></button>
</form>
