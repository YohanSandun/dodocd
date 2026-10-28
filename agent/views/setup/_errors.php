<?php /** @var list<string> $errors */ ?>
<?php if ($errors): ?>
    <div class="alert fail" role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>
