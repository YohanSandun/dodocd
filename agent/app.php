<?php

declare(strict_types=1);

use Dodo\AppDb\Connection;
use Dodo\AppDb\MigrationException;
use Dodo\AppDb\MigrationLog;
use Dodo\AppDb\MigrationRunner;
use Dodo\Auth\ApiToken;
use Dodo\Auth\Login;
use Dodo\Auth\Session;
use Dodo\Deploy\Deployer;
use Dodo\Deploy\DeployException;
use Dodo\Sites\Sites;

/**
 * Dashboard controller for the signed-in admin. Included by index.php once DodoCD is installed.
 *
 * @var string $path The request path, e.g. "/" or "/deployments/12".
 */

$pdo = dodo_db();
$dataDir = dodo_config()['data_dir'];
Session::start($dataDir);
$user = Session::user($pdo);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function app_render(string $title, string $view, array $vars = [], int $status = 200): never
{
    global $user;

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    http_response_code($status);
    echo dodo_view('layout', [
        'title' => $title,
        'user' => $user,
        'flash' => $flash,
        'content' => dodo_view($view, $vars + ['errors' => []]),
    ]);
    exit;
}

function app_fail(int $status, string $message): never
{
    app_render('Error', 'message', ['message' => $message, 'backPath' => '/', 'backLabel' => 'Back to the dashboard'], $status);
}

/** Shows a success message on the next page, after a redirect. */
function app_flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function app_audit(string $action, ?int $siteId, array $details = []): void
{
    global $pdo, $user;

    $pdo->prepare('INSERT INTO audit_log (user_id, site_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$user['id'], $siteId, $action, $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null, dodo_now()]);
}

function app_site(string $slug): array
{
    global $pdo;

    return Sites::find($pdo, $slug) ?? app_fail(404, 'That site does not exist.');
}

function app_deployer(): Deployer
{
    global $pdo, $dataDir;

    return new Deployer($pdo, $dataDir, dodo_crypto());
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
                                  WHERE d.release_id = s.current_release_id AND d.status = 'success') AS live_since,
                                 (SELECT COUNT(*) FROM api_tokens t WHERE t.site_id = s.id AND t.revoked_at IS NULL) AS active_tokens
                          FROM sites s LEFT JOIN releases r ON r.id = s.current_release_id
                          ORDER BY s.name")->fetchAll();

    $statement = $pdo->prepare("SELECT d.id, d.type, d.status, d.release_id, d.error, d.started_at, d.finished_at,
                                       r.ref, r.commit_sha, r.message,
                                       (SELECT COUNT(*) FROM app_migrations m WHERE m.deployment_id = d.id AND m.status = 'applied') AS migrations
                                FROM deployments d LEFT JOIN releases r ON r.id = d.release_id
                                WHERE d.site_id = ? ORDER BY d.id DESC LIMIT 20");
    $deployer = app_deployer();
    foreach ($sites as &$site) {
        $siteId = (int) $site['id'];
        $statement->execute([$siteId]);
        $site['deployments'] = $statement->fetchAll();
        $site['database'] = Connection::row($pdo, $siteId);
        $site['rollback_targets'] = Deployer::rollbackTargets($pdo, $siteId);
        foreach ($site['rollback_targets'] as &$release) {
            $release['drift'] = MigrationLog::drift($pdo, $siteId, $deployer->releaseDir($siteId, (int) $release['id']));
        }
        unset($release);
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
        $result = app_deployer()->rollback((int) $siteId, (int) $matches[1], (int) $user['id']);
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
    $siteId = (int) $deployment['site_id'];
    $canRollBack = $deployment['release_id'] !== null && in_array(
        (int) $deployment['release_id'],
        array_map('intval', array_column(Deployer::rollbackTargets($pdo, $siteId), 'id')),
        true,
    );

    app_render("Deployment #{$deployment['id']}", 'deployment', [
        'deployment' => $deployment,
        'migrations' => MigrationLog::forDeployment($pdo, (int) $deployment['id']),
        'canRollBack' => $canRollBack,
        'drift' => $canRollBack
            ? MigrationLog::drift($pdo, $siteId, app_deployer()->releaseDir($siteId, (int) $deployment['release_id']))
            : null,
    ]);
}

if ($path === '/sites/new') {
    $site = ['name' => '', 'url' => 'https://', 'target_path' => ''];
    $errors = [];

    if ($isPost) {
        $site = Sites::fromPost();
        $errors = Sites::validate($site, $dataDir, $pdo);
        if (!$errors) {
            $created = Sites::create($pdo, $site, $dataDir);
            app_audit('site_created', $created['id'], ['slug' => $created['slug']]);
            // Shown once on the next page; redirecting first means a refresh can't create the site twice.
            $_SESSION['new_token'] = ['slug' => $created['slug'], 'token' => $created['token']];
            dodo_redirect('/sites/' . $created['slug'] . '/token');
        }
    }

    app_render('Add site', 'sites/new', ['site' => $site, 'errors' => $errors]);
}

if (!preg_match('#^/sites/([a-z0-9-]+)(/[a-z/-]+)$#', $path, $matches)) {
    app_fail(404, 'Page not found.');
}

$site = app_site($matches[1]);
$siteId = (int) $site['id'];
$sitePage = $matches[2];

if ($sitePage === '/token') {
    $flash = $_SESSION['new_token'] ?? null;
    unset($_SESSION['new_token']);
    $token = ($flash['slug'] ?? null) === $site['slug'] ? $flash['token'] : null;

    app_render('Deploy token', 'sites/token', ['site' => $site, 'token' => $token]);
}

if ($sitePage === '/tokens') {
    $name = '';
    $errors = [];

    if ($isPost && ($_POST['action'] ?? '') === 'revoke') {
        $revoked = ApiToken::revoke($pdo, $siteId, (int) ($_POST['token_id'] ?? 0));
        if ($revoked === null) {
            $errors[] = 'That token is already revoked or does not belong to this site.';
        } else {
            app_audit('token_revoked', $siteId, ['token_id' => (int) $revoked['id'], 'name' => $revoked['name'], 'prefix' => $revoked['prefix']]);
            app_flash("Revoked \"{$revoked['name']}\". CI using it can no longer deploy.");
            dodo_redirect('/sites/' . $site['slug'] . '/tokens');
        }
    } elseif ($isPost) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $errors = ApiToken::validateName($pdo, $siteId, $name);
        if (!$errors) {
            $created = ApiToken::create($pdo, $siteId, $name);
            app_audit('token_created', $siteId, ['token_id' => $created['id'], 'name' => $created['name'], 'prefix' => $created['prefix']]);
            // Shown once on the next page; redirecting first means a refresh can't create it twice.
            $_SESSION['new_token'] = ['slug' => $site['slug'], 'token' => $created['token'], 'name' => $created['name']];
            dodo_redirect('/sites/' . $site['slug'] . '/tokens');
        }
    }

    $flash = $_SESSION['new_token'] ?? null;
    unset($_SESSION['new_token']);

    app_render("{$site['name']} deploy tokens", 'sites/tokens', [
        'site' => $site,
        'tokens' => ApiToken::forSite($pdo, $siteId),
        'newToken' => ($flash['slug'] ?? null) === $site['slug'] ? $flash : null,
        'name' => $name,
        'errors' => $errors,
    ], $errors ? 422 : 200);
}

if ($sitePage === '/settings') {
    $form = $site;
    $errors = [];

    if ($isPost) {
        $form = Sites::fromPost();
        $errors = Sites::validate($form, $dataDir, $pdo, $siteId);
        if (!$errors) {
            try {
                $pathChanged = app_deployer()->withSiteLock($siteId, fn () => Sites::update($pdo, $site, $form, $dataDir));
                app_audit('site_updated', $siteId, ['target_path_changed' => $pathChanged]);
                app_flash($pathChanged
                    ? 'Saved. The next deploy is treated as the first one in the new folder, so nothing there is deleted.'
                    : 'Saved.');
                dodo_redirect('/sites/' . $site['slug'] . '/settings');
            } catch (DeployException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    app_render("{$site['name']} settings", 'sites/settings', ['site' => $site, 'form' => $form, 'errors' => $errors]);
}

if ($sitePage === '/database/test') {
    if (!$isPost) {
        app_fail(405, 'Use the Test connection button.');
    }

    [$db, $errors] = Sites::databaseInput(Connection::fromPost(), Connection::row($pdo, $siteId), dodo_crypto());
    $error = $errors ? implode(' ', $errors) : Connection::test($db);

    header('Content-Type: application/json');
    echo json_encode(['ok' => $error === null, 'message' => $error ?? 'Connected successfully.']);
    exit;
}

if ($sitePage === '/database') {
    $row = Connection::row($pdo, $siteId);
    $form = $row === null
        ? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'username' => '']
        : ['host' => $row['host'], 'port' => (int) $row['port'], 'name' => $row['name'], 'username' => $row['username']];
    $errors = [];

    if ($isPost && ($_POST['action'] ?? '') === 'remove') {
        try {
            app_deployer()->withSiteLock($siteId, fn () => Sites::removeDatabase($pdo, $siteId));
            app_audit('database_removed', $siteId);
            app_flash('Database settings removed. Releases with migrations can\'t be deployed to this site until a database is added.');
            dodo_redirect('/sites/' . $site['slug'] . '/database');
        } catch (DeployException $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($isPost) {
        [$db, $errors] = Sites::databaseInput(Connection::fromPost(), $row, dodo_crypto());
        $form = array_diff_key($db, ['password' => true]);

        if (!$errors && ($error = Connection::test($db)) !== null) {
            $errors[] = $error;
        }
        if (!$errors) {
            try {
                app_deployer()->withSiteLock($siteId, fn () => Sites::saveDatabase($pdo, $siteId, $db, dodo_crypto()));
                app_audit('database_updated', $siteId, ['host' => $db['host'], 'port' => $db['port'], 'name' => $db['name']]);
                app_flash('Database settings saved and the connection works.');
                dodo_redirect('/sites/' . $site['slug'] . '/database');
            } catch (DeployException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    app_render("{$site['name']} database", 'sites/database', [
        'site' => $site,
        'form' => $form,
        'hasDatabase' => $row !== null,
        'sharedWith' => $row === null ? [] : Sites::sharingDatabase($pdo, $siteId, $form),
        'mysqlAvailable' => extension_loaded('pdo_mysql'),
        'errors' => $errors,
    ]);
}

if ($sitePage === '/migrations' || $sitePage === '/migrations/baseline') {
    $deployer = app_deployer();
    $releases = $pdo->prepare("SELECT r.id, r.ref, r.commit_sha, r.created_at,
                                      EXISTS (SELECT 1 FROM deployments d WHERE d.release_id = r.id AND d.status = 'success') AS was_live
                               FROM releases r WHERE r.site_id = ? AND r.pruned_at IS NULL ORDER BY r.id DESC");
    $releases->execute([$siteId]);
    $releases = $releases->fetchAll();
    $liveId = (int) $site['current_release_id'];

    // Default: the newest release that never went live (e.g. a deploy refused with "baseline first"), else the live one.
    $default = null;
    foreach ($releases as $release) {
        if (!$release['was_live'] && (int) $release['id'] > $liveId) {
            $default = (int) $release['id'];
            break;
        }
    }
    $releaseId = (int) ($_POST['release_id'] ?? $_GET['release'] ?? $default ?? $liveId);
    if ($releaseId !== 0 && !in_array($releaseId, array_map('intval', array_column($releases, 'id')), true)) {
        app_fail(404, 'That release is not kept for this site.');
    }

    $files = [];
    $status = null;
    $errors = [];
    $db = null;
    $app = null;
    try {
        $files = $releaseId === 0 ? [] : MigrationRunner::files($deployer->releaseDir($siteId, $releaseId));
        $db = Connection::forSite($pdo, $siteId, dodo_crypto());
        if ($db !== null) {
            $app = Connection::open($db);
            $status = (new MigrationRunner($app, $db, fn () => null))->status($files);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if ($sitePage === '/migrations/baseline') {
        if (!$isPost) {
            app_fail(405, 'Use the Baseline form.');
        }
        if ($app !== null && !$errors) {
            $through = (string) ($_POST['through'] ?? '');
            try {
                $marked = $deployer->withSiteLock($siteId,
                    fn () => (new MigrationRunner($app, $db, fn () => null))->baseline($files, $through, $releaseId ?: null));
                foreach ($marked as $name) {
                    MigrationLog::record($pdo, $siteId, null, $releaseId ?: null, (int) $user['id'], [
                        'name' => $name, 'checksum' => $files[$name]['checksum'], 'status' => 'baseline',
                        'statements_run' => 0, 'statements_total' => 0, 'duration_ms' => 0, 'error' => null,
                    ]);
                }
                app_audit('migrations_baselined', $siteId, ['release_id' => $releaseId, 'through' => $through, 'marked' => $marked]);
                app_flash($marked
                    ? 'Marked ' . count($marked) . ' migration' . (count($marked) === 1 ? '' : 's') . ' as applied. Deploy again to run the rest.'
                    : 'Migration tracking started. Deploy again to run the migrations.');
                dodo_redirect('/sites/' . $site['slug'] . '/migrations?release=' . $releaseId);
            } catch (DeployException|MigrationException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    app_render("{$site['name']} migrations", 'sites/migrations', [
        'site' => $site,
        'releases' => $releases,
        'releaseId' => $releaseId,
        'files' => $files,
        'status' => $status,
        'hasDatabase' => $db !== null || Connection::row($pdo, $siteId) !== null,
        'log' => MigrationLog::recent($pdo, $siteId),
        'errors' => $errors,
    ]);
}

app_fail(404, 'Page not found.');
