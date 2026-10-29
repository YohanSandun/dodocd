<?php

declare(strict_types=1);

use Dodo\Auth\Login;
use Dodo\Auth\Session;
use Dodo\Deploy\Deployer;
use Dodo\Deploy\DeployException;

/**
 * Dashboard controller for the signed-in admin. Included by index.php once DodoCD is installed.
 *
 * @var string $path The request path, e.g. "/" or "/deployments/12".
 */

$pdo = dodo_db();
Session::start(dodo_config()['data_dir']);
$user = Session::user($pdo);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function app_render(string $title, string $view, array $vars = [], int $status = 200): never
{
    global $user;

    http_response_code($status);
    echo dodo_view('layout', ['title' => $title, 'user' => $user, 'content' => dodo_view($view, $vars + ['errors' => []])]);
    exit;
}

function app_fail(int $status, string $message): never
{
    app_render('Error', 'message', ['message' => $message, 'backPath' => '/', 'backLabel' => 'Back to the dashboard'], $status);
}

if ($path === '/login') {
    if ($user !== null) {
        dodo_redirect('/');
    }

    $email = '';
    $errors = [];

    if ($isPost) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $login = new Login($pdo);

        if (!Session::csrfValid()) {
            $errors[] = 'This form has expired. Try again.';
        } elseif ($login->lockedOut($email, $ip)) {
            $errors[] = 'Too many failed sign-in attempts. Wait ' . Login::WINDOW_MINUTES . ' minutes and try again.';
        } elseif (($signedIn = $login->attempt($email, (string) ($_POST['password'] ?? ''), $ip)) === null) {
            $errors[] = 'Wrong email or password.';
        } else {
            $next = $_SESSION['after_login'] ?? '/';
            Session::signIn($signedIn);
            dodo_redirect($next);
        }
    }

    app_render('Sign in', 'login', ['email' => $email, 'errors' => $errors], $errors ? 422 : 200);
}

if ($path === '/logout') {
    if ($isPost && $user !== null && Session::csrfValid()) {
        $pdo->prepare('INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], 'logout', dodo_now()]);
        Session::signOut();
    }
    dodo_redirect('/login');
}

if ($user === null) {
    // Only a path DodoCD routed itself is stored, so this can't redirect off-site. Page loads
    // only: the browser's favicon request would otherwise overwrite the page it came from.
    if (!$isPost && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
        $_SESSION['after_login'] = $path;
    }
    dodo_redirect('/login');
}

if ($isPost && !Session::csrfValid()) {
    app_fail(400, 'This form has expired. Reload the page and try again.');
}

if ($path === '/') {
    $sites = $pdo->query("SELECT s.*, r.ref, r.commit_sha, r.message,
                                 (SELECT MAX(d.finished_at) FROM deployments d
                                  WHERE d.release_id = s.current_release_id AND d.status = 'success') AS live_since
                          FROM sites s LEFT JOIN releases r ON r.id = s.current_release_id
                          ORDER BY s.name")->fetchAll();

    $statement = $pdo->prepare('SELECT d.id, d.type, d.status, d.release_id, d.error, d.started_at, d.finished_at,
                                       r.ref, r.commit_sha, r.message
                                FROM deployments d LEFT JOIN releases r ON r.id = d.release_id
                                WHERE d.site_id = ? ORDER BY d.id DESC LIMIT 20');
    foreach ($sites as &$site) {
        $statement->execute([$site['id']]);
        $site['deployments'] = $statement->fetchAll();
        $site['rollback_targets'] = Deployer::rollbackTargets($pdo, (int) $site['id']);
    }
    unset($site);

    app_render('Dashboard', 'dashboard', ['sites' => $sites]);
}

if (preg_match('#^/releases/(\d+)/rollback$#', $path, $matches)) {
    if (!$isPost) {
        header('Allow: POST');
        app_fail(405, 'Use the Roll back button on the dashboard.');
    }

    $statement = $pdo->prepare('SELECT site_id FROM releases WHERE id = ?');
    $statement->execute([(int) $matches[1]]);
    $siteId = $statement->fetchColumn();
    if ($siteId === false) {
        app_fail(404, 'That release does not exist.');
    }

    // Like a deploy, a rollback must not stop halfway if the browser goes away.
    ignore_user_abort(true);
    @set_time_limit(0);
    session_write_close();

    try {
        $result = (new Deployer($pdo, dodo_config()['data_dir']))->rollback((int) $siteId, (int) $matches[1], (int) $user['id']);
        dodo_redirect('/deployments/' . $result['id']);
    } catch (DeployException $e) {
        // Once the rollback is recorded, its page shows the error and the log.
        if ($e->deployment !== null) {
            dodo_redirect('/deployments/' . $e->deployment['id']);
        }
        app_fail($e->status, $e->getMessage());
    }
}

if (preg_match('#^/deployments/(\d+)$#', $path, $matches)) {
    $statement = $pdo->prepare('SELECT d.*, s.name AS site_name, s.url AS site_url, s.target_path, s.current_release_id,
                                       r.ref, r.commit_sha, r.message, r.archive_name, r.archive_size, r.file_count, r.pruned_at,
                                       t.name AS token_name, t.prefix AS token_prefix, u.name AS user_name
                                FROM deployments d
                                JOIN sites s ON s.id = d.site_id
                                LEFT JOIN releases r ON r.id = d.release_id
                                LEFT JOIN api_tokens t ON t.id = d.token_id
                                LEFT JOIN users u ON u.id = d.user_id
                                WHERE d.id = ?');
    $statement->execute([(int) $matches[1]]);
    $deployment = $statement->fetch() ?: app_fail(404, 'That deployment does not exist.');
    $canRollBack = $deployment['release_id'] !== null && in_array(
        (int) $deployment['release_id'],
        array_map('intval', array_column(Deployer::rollbackTargets($pdo, (int) $deployment['site_id']), 'id')),
        true,
    );

    app_render("Deployment #{$deployment['id']}", 'deployment', ['deployment' => $deployment, 'canRollBack' => $canRollBack]);
}

app_fail(404, 'Page not found.');
