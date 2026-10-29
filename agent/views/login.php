<?php
/**
 * @var list<string> $errors
 * @var string $email
 */
?>
<h2>Sign in</h2>

<?= dodo_view('setup/_errors', ['errors' => $errors]) ?>

<form method="post" action="<?= e(dodo_url('/login')) ?>">
    <?= dodo_csrf_field() ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="username" <?= $email === '' ? 'autofocus' : '' ?>>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password" <?= $email !== '' ? 'autofocus' : '' ?>>

    <div class="actions">
        <button type="submit">Sign in</button>
    </div>
</form>
