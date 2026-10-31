<?php /** @var array{name: string, url: string, target_path: string} $site */ ?>
<label for="name">Name</label>
<input type="text" id="name" name="name" value="<?= e($site['name']) ?>" required>

<label for="url">Site URL</label>
<input type="url" id="url" name="url" value="<?= e($site['url']) ?>" required>

<label for="target_path">Folder to deploy to</label>
<input type="text" id="target_path" name="target_path" value="<?= e($site['target_path']) ?>" required placeholder="/home/you/public_html">
<p class="hint">An absolute path PHP can write to. It can't overlap another site's folder, DodoCD's own folder or the data directory.</p>
