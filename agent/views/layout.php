<?php
/**
 * @var string $title
 * @var string $content
 * @var array<string, string>|null $steps
 * @var string|null $current
 * @var array<string, bool>|null $done
 * @var array|null $user The signed-in user, if any.
 */
$user ??= null;
$steps ??= null;
$current ??= null;
$done ??= [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · DodoCD</title>
    <style>
        :root {
            --bg: #f5f6f8; --card: #ffffff; --text: #1d2329; --muted: #5f6b76; --border: #dce1e6;
            --accent: #2f6fde; --accent-text: #ffffff;
            --ok: #1f8a4c; --ok-bg: #e7f5ec; --warn: #9a5b00; --warn-bg: #fdf3e2; --fail: #c62828; --fail-bg: #fdecec;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14171a; --card: #1d2125; --text: #e6e9ec; --muted: #9aa5b0; --border: #2f353b;
                --accent: #6c9cff; --accent-text: #0f1215;
                --ok: #5ccf8b; --ok-bg: #16291e; --warn: #f0b04a; --warn-bg: #2d2412; --fail: #ff7a7a; --fail-bg: #331a1a;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
        .brand { margin: 0 0 20px; font-size: 22px; letter-spacing: -0.01em; }
        .steps { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; padding: 0; margin: 0 0 16px; counter-reset: step; }
        .steps li { counter-increment: step; font-size: 13px; color: var(--muted); padding: 4px 10px; border: 1px solid var(--border); border-radius: 999px; }
        .steps li::before { content: counter(step) ". "; }
        .steps li.done { color: var(--ok); border-color: var(--ok); }
        .steps li.current { color: var(--accent-text); background: var(--accent); border-color: var(--accent); }
        .steps a { color: inherit; text-decoration: none; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 24px; }
        h2 { margin: 0 0 8px; font-size: 19px; }
        p { margin: 0 0 14px; }
        .muted, .hint { color: var(--muted); }
        .hint { font-size: 13px; margin: 4px 0 0; }
        code { font: 13px ui-monospace, SFMono-Regular, Consolas, monospace; background: var(--bg); padding: 1px 5px; border-radius: 4px; overflow-wrap: anywhere; }
        label { display: block; font-weight: 600; margin: 16px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=url], input[type=number] {
            width: 100%; padding: 9px 11px; font: inherit; color: var(--text); background: var(--bg);
            border: 1px solid var(--border); border-radius: 6px;
        }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .row { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        button, .button {
            display: inline-block; padding: 9px 16px; font: inherit; font-weight: 600; border-radius: 6px; cursor: pointer;
            border: 1px solid var(--accent); background: var(--accent); color: var(--accent-text); text-decoration: none;
        }
        button.secondary, .button.secondary { background: transparent; color: var(--accent); }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .alert { padding: 10px 14px; border-radius: 6px; margin: 0 0 14px; border: 1px solid var(--border); }
        .alert.ok { color: var(--ok); background: var(--ok-bg); border-color: transparent; }
        .alert.warn { color: var(--warn); background: var(--warn-bg); border-color: transparent; }
        .alert.fail { color: var(--fail); background: var(--fail-bg); border-color: transparent; }
        .alert ul { margin: 0; padding-left: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td, th { text-align: left; vertical-align: top; padding: 9px 8px; border-top: 1px solid var(--border); }
        th { width: 34%; font-weight: 600; color: var(--muted); }
        .badge { display: inline-block; min-width: 64px; text-align: center; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
        .badge.ok { color: var(--ok); background: var(--ok-bg); }
        .badge.warn { color: var(--warn); background: var(--warn-bg); }
        .badge.fail { color: var(--fail); background: var(--fail-bg); }
        .token { display: flex; gap: 8px; align-items: stretch; }
        .token code { flex: 1; padding: 10px; font-size: 13px; }
        a { color: var(--accent); }
        h3 { margin: 22px 0 8px; font-size: 16px; }
        .topbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin: 0 0 20px; }
        .topbar .brand { margin: 0; }
        .topbar .brand a { color: inherit; text-decoration: none; }
        .topbar form { display: flex; align-items: center; gap: 10px; color: var(--muted); }
        .topbar button { padding: 5px 12px; font-size: 13px; }
        .site + .site { margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
        .scroll { overflow-x: auto; }
        table.list th { width: auto; font-size: 13px; }
        table.list td { white-space: nowrap; }
        table.list td.release { white-space: normal; width: 100%; min-width: 180px; }
        .badge + .badge, h2 .badge { margin-left: 6px; vertical-align: middle; }
        .fail-text { color: var(--fail); }
        .prewrap { white-space: pre-wrap; overflow-wrap: anywhere; }
        pre.log { margin: 0; padding: 12px; max-height: 70vh; overflow: auto; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; font: 12.5px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace; }
    </style>
</head>
<body>
<main>
    <?php if ($user): ?>
        <header class="topbar">
            <h1 class="brand"><a href="<?= e(dodo_url('/')) ?>">DodoCD</a></h1>
            <form method="post" action="<?= e(dodo_url('/logout')) ?>">
                <?= dodo_csrf_field() ?>
                <span><?= e($user['name']) ?></span>
                <button type="submit" class="secondary">Sign out</button>
            </form>
        </header>
    <?php else: ?>
        <h1 class="brand">DodoCD</h1>
    <?php endif ?>

    <?php if ($steps): ?>
        <ol class="steps">
            <?php foreach ($steps as $key => $label): ?>
                <?php $class = $key === $current ? 'current' : (!empty($done[$key]) ? 'done' : ''); ?>
                <li class="<?= $class ?>">
                    <?php if ($class === 'done'): ?>
                        <a href="<?= e(dodo_url('/setup/' . $key)) ?>"><?= e($label) ?></a>
                    <?php else: ?>
                        <?= e($label) ?>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>

    <section class="card">
        <?= $content ?>
    </section>
</main>
<script>
    // Timestamps are rendered in UTC; show them in the viewer's own time zone.
    for (const el of document.querySelectorAll('time[datetime]')) {
        const date = new Date(el.getAttribute('datetime'));
        if (!isNaN(date)) {
            el.textContent = date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        }
    }
</script>
</body>
</html>
