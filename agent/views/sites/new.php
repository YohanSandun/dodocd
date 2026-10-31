<?php
/**
 * @var list<string> $errors
 * @var array{name: string, url: string, target_path: string} $site
 */
?>
<p><a href="<?= e(dodo_url('/')) ?>">← Dashboard</a></p>
<h2>Add site</h2>
<p class="muted">Each site has its own folder, deploy token and, optionally, its own database.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<form method="post" action="<?= e(dodo_url('/sites/new')) ?>">
    <?= dodo_csrf_field() ?>
    <?= dodo_view('sites/_fields', ['site' => $site]) ?>

    <div class="actions">
        <button type="submit">Add site</button>
    </div>
</form>
