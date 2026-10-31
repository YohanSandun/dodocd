<?php
/**
 * @var array $site
 * @var list<array> $tokens ApiToken::forSite(), active ones first.
 * @var array{token: string, name: string}|null $newToken A token just created, shown once.
 * @var string $name The name typed into the create form.
 * @var list<string> $errors
 */
$base = '/sites/' . $site['slug'] . '/tokens';
$activeCount = count(array_filter($tokens, fn (array $token) => $token['revoked_at'] === null));
?>
<?= dodo_view('sites/_nav', ['site' => $site, 'current' => 'tokens']) ?>

<?php if ($newToken !== null): ?>
    <h3>New token: <?= e($newToken['name']) ?></h3>
    <p>Store it as a secret in your CI, for example <code>DODOCD_TOKEN</code> in GitHub Actions.</p>
    <?= dodo_view('token', ['token' => $newToken['token']]) ?>
<?php endif ?>

<p class="muted">CI sends a deploy token with each upload to
    <code><?= e(dodo_origin() . dodo_url('/api/sites/' . $site['slug'] . '/deploy')) ?></code>.
    Tokens only work for this site, and only their hash is stored. To rotate a token, create a new one,
    update your CI secret, then revoke the old one.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<?php if ($activeCount === 0): ?>
    <div class="alert warn">This site has no active deploy token, so deploys are refused. Create one below.</div>
<?php endif ?>

<?php if ($tokens): ?>
    <div class="scroll">
        <table class="list">
            <thead><tr><th>Name</th><th>Status</th><th>Created</th><th>Last used</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $token): ?>
                <tr>
                    <td class="release">
                        <?= e($token['name']) ?>
                        <div class="hint"><code><?= e($token['prefix']) ?>…</code></div>
                    </td>
                    <td>
                        <?php if ($token['revoked_at'] === null): ?>
                            <span class="badge ok">Active</span>
                        <?php else: ?>
                            <span class="badge fail">Revoked</span>
                            <div class="hint"><?= dodo_time($token['revoked_at']) ?></div>
                        <?php endif ?>
                    </td>
                    <td><?= dodo_time($token['created_at']) ?></td>
                    <td><?= $token['last_used_at'] === null ? '<span class="muted">Never</span>' : dodo_time($token['last_used_at']) ?></td>
                    <td>
                        <?php if ($token['revoked_at'] === null): ?>
                            <?php
                            $confirm = "Revoke \"{$token['name']}\"? CI using it can no longer deploy.";
                            if ($activeCount === 1) {
                                $confirm .= ' It is the only active token, so deploys are refused until you create a new one.';
                            }
                            ?>
                            <form method="post" action="<?= e(dodo_url($base)) ?>" class="inline" data-confirm="<?= e($confirm) ?>">
                                <?= dodo_csrf_field() ?>
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="token_id" value="<?= e($token['id']) ?>">
                                <button type="submit" class="danger small">Revoke</button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<h3>Create a token</h3>
<form method="post" action="<?= e(dodo_url($base)) ?>">
    <?= dodo_csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="<?= e(\Dodo\Auth\ApiToken::NAME_MAX_LENGTH) ?>"
           required autocomplete="off" placeholder="e.g. GitHub Actions">
    <p class="hint">Shown in the deploy history, so you can tell which CI deployed. The token itself is shown once.</p>
    <div class="actions">
        <button type="submit">Create token</button>
    </div>
</form>
