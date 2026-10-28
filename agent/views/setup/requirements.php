<?php
/**
 * @var list<array{label: string, status: string, detail: string}> $checks
 * @var bool $passes
 */
$badges = ['ok' => 'OK', 'warn' => 'Warning', 'fail' => 'Missing'];
?>
<h2>Server requirements</h2>
<p class="muted">Red items must be fixed before DodoCD can be installed. Yellow items only limit some features.</p>

<table>
    <?php foreach ($checks as $check): ?>
        <tr>
            <td style="width: 90px"><span class="badge <?= e($check['status']) ?>"><?= $badges[$check['status']] ?></span></td>
            <td>
                <strong><?= e($check['label']) ?></strong><br>
                <span class="muted"><?= e($check['detail']) ?></span>
            </td>
        </tr>
    <?php endforeach ?>
</table>

<form method="post" action="<?= e(dodo_url('/setup/requirements')) ?>" class="actions">
    <?= dodo_csrf_field() ?>
    <button type="submit" <?= $passes ? '' : 'disabled' ?>>Continue</button>
    <?php if (!$passes): ?>
        <a class="button secondary" href="<?= e(dodo_url('/setup/requirements')) ?>">Check again</a>
    <?php endif ?>
</form>
