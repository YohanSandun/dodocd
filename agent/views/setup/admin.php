<?php
/**
 * @var list<string> $errors
 * @var array{name: string, email: string} $admin
 */
?>
<h2>Admin account</h2>
<p class="muted">You'll use this account to sign in to the DodoCD dashboard.</p>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<form method="post" action="<?= e(dodo_url('/setup/admin')) ?>">
    <?= dodo_csrf_field() ?>

    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($admin['name']) ?>" required autocomplete="name">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($admin['email']) ?>" required autocomplete="email">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
    <p class="hint">At least 10 characters.</p>

    <label for="password_confirm">Confirm password</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="10" autocomplete="new-password">

    <div class="actions">
        <button type="submit">Save and continue</button>
    </div>
</form>
