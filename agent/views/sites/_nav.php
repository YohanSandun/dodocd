<?php
/**
 * Heading and tabs for a site's pages.
 *
 * @var array $site
 * @var string $current One of settings, database, migrations.
 */
$tabs = ['settings' => 'Settings', 'database' => 'Database', 'migrations' => 'Migrations'];
?>
<p><a href="<?= e(dodo_url('/')) ?>">← Dashboard</a></p>
<h2><?= e($site['name']) ?></h2>
<nav class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e(dodo_url('/sites/' . $site['slug'] . '/' . $key)) ?>" <?= $key === $current ? 'class="current" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>
