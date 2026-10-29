<?php
/**
 * @var string $message
 * @var string|null $backPath
 * @var string|null $backLabel
 */
$backPath ??= '/setup';
$backLabel ??= 'Back to setup';
?>
<div class="alert fail"><?= e($message) ?></div>
<a class="button secondary" href="<?= e(dodo_url($backPath)) ?>"><?= e($backLabel) ?></a>
