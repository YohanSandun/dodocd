<?php

declare(strict_types=1);

use Dodo\AppDb\Connection;
use Dodo\Crypto;
use Dodo\Installer\DataDir;
use Dodo\Installer\Installer;
use Dodo\Installer\Requirements;
use Dodo\Installer\SetupLock;
use Dodo\Path;
use Dodo\Sites\Sites;

/**
 * Setup wizard controller. Included by index.php only while DodoCD is not installed.
 *
 * @var string $setupPath The part of the URL after /setup, e.g. "" or "/data".
 */

const SETUP_STEPS = [
    'requirements' => 'Requirements',
    'data' => 'Data directory',
    'admin' => 'Admin account',
    'site' => 'Main site',
    'database' => 'App database',
    'finish' => 'Finish',
];

session_name('dodocd_setup');
session_set_cookie_params([
    'path' => dodo_url('/'),
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => dodo_is_https(),
]);
session_start();

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['setup'] ??= ['owner' => bin2hex(random_bytes(16)), 'done' => []];
$state = &$_SESSION['setup'];

$step = trim($setupPath, '/');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

function setup_render(string $step, array $vars = []): never
{
    global $state;

    echo dodo_view('layout', [
        'title' => SETUP_STEPS[$step] ?? 'Setup',
        'steps' => SETUP_STEPS,
        'current' => $step,
        'done' => $state['done'],
        'content' => dodo_view('setup/' . $step, $vars + ['errors' => []]),
    ]);
    exit;
}

function setup_fail(int $status, string $message): never
{
    global $step;

    http_response_code($status);
    if ($step === 'test-db') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }

    echo dodo_view('layout', ['title' => 'Setup', 'content' => dodo_view('message', ['message' => $message])]);
    exit;
}

/** Marks a step as done and moves on to the next one. */
function setup_complete(string $step): never
{
    global $state;

    $state['done'][$step] = true;
    $keys = array_keys(SETUP_STEPS);
    dodo_redirect('/setup/' . $keys[array_search($step, $keys, true) + 1]);
}

if ($isPost) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        setup_fail(400, 'This form has expired. Reload the page and try again.');
    }
    if (!SetupLock::claim($state['owner'])) {
        setup_fail(423, 'Setup is already in progress in another browser. Finish it there, or wait up to '
            . (SetupLock::TTL_SECONDS / 60) . ' minutes and try again.');
    }
}

if ($step === 'test-db' && $isPost) {
    $db = Connection::fromPost();
    $errors = Connection::validate($db);
    $error = $errors ? implode(' ', $errors) : Connection::test($db);

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => $error === null,
        'message' => $error ?? 'Connected successfully.',
    ]);
    exit;
}

// Steps must be completed in order; send the visitor to the first unfinished one.
foreach (array_keys(SETUP_STEPS) as $key) {
    if ($key === $step) {
        break;
    }
    if (empty($state['done'][$key])) {
        dodo_redirect('/setup/' . $key);
    }
}

if (!isset(SETUP_STEPS[$step])) {
    setup_fail(404, 'Unknown setup step.');
}

switch ($step) {
    case 'requirements':
        $checks = Requirements::check();
        $passes = Requirements::passes($checks);
        if ($isPost && $passes) {
            setup_complete($step);
        }

        setup_render($step, ['checks' => $checks, 'passes' => $passes]);

    case 'data':
        $errors = [];
        $dir = $state['data_dir'] ?? DataDir::propose();

        if ($isPost) {
            $dir = trim((string) ($_POST['data_dir'] ?? ''));
            $error = DataDir::validate($dir);

            if ($error !== null) {
                $errors[] = $error;
            } else {
                $dir = Path::normalize($dir);
                try {
                    DataDir::prepare($dir);
                    $state['data_dir'] = $dir;
                    $state['data_dir_exposed'] = DataDir::probePublicAccess($dir);
                    setup_complete($step);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        setup_render($step, [
            'errors' => $errors,
            'dataDir' => $dir,
            'insideWebroot' => Path::isAbsolute($dir) && DataDir::isInsideWebroot($dir),
            'outsideCandidate' => DataDir::outsideWebrootCandidate(),
        ]);

    case 'admin':
        $errors = [];
        $admin = $state['admin'] ?? ['name' => '', 'email' => ''];

        if ($isPost) {
            $admin = [
                'name' => trim((string) ($_POST['name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
            ];
            $password = (string) ($_POST['password'] ?? '');

            if ($admin['name'] === '') {
                $errors[] = 'Name is required.';
            }
            if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Enter a valid email address.';
            }
            if (strlen($password) < 10) {
                $errors[] = 'Password must be at least 10 characters.';
            }
            if ($password !== ($_POST['password_confirm'] ?? '')) {
                $errors[] = 'Passwords do not match.';
            }

            if (!$errors) {
                $state['admin'] = $admin + ['password_hash' => password_hash($password, PASSWORD_DEFAULT)];
                setup_complete($step);
            }
        }

        setup_render($step, ['errors' => $errors, 'admin' => $admin]);

    case 'site':
        $errors = [];
        $site = $state['site'] ?? [
            'name' => 'Main site',
            'url' => dodo_origin(),
            'target_path' => Path::canonical($_SERVER['DOCUMENT_ROOT'] ?? dirname(DODO_ROOT)),
        ];

        if ($isPost) {
            $site = Sites::fromPost();
            $errors = Sites::validate($site, $state['data_dir']);

            if (!$errors) {
                $site['target_path'] = Path::canonical($site['target_path']);
                $state['site'] = $site;
                setup_complete($step);
            }
        }

        setup_render($step, [
            'errors' => $errors,
            'site' => $site,
            'excluded' => Path::isAbsolute($site['target_path'])
                ? Installer::excludedPaths($site['target_path'], $state['data_dir'])
                : [],
        ]);

    case 'database':
        $errors = [];
        $db = $state['database'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'username' => ''];

        if ($isPost) {
            if (($_POST['action'] ?? '') === 'skip') {
                $state['database'] = null;
                setup_complete($step);
            }

            $db = Connection::fromPost();
            $errors = Connection::validate($db);

            if (!$errors) {
                $error = Connection::test($db);
                if ($error === null) {
                    $state['database'] = $db;
                    setup_complete($step);
                }
                $errors[] = $error;
            }
        }

        setup_render($step, ['errors' => $errors, 'db' => $db, 'mysqlAvailable' => extension_loaded('pdo_mysql')]);

    case 'finish':
        $errors = [];
        $exposed = $state['data_dir_exposed'] ?? null;

        if ($isPost && $exposed === true) {
            $errors[] = 'The data directory is publicly reachable. Choose another location first.';
        } elseif ($isPost) {
            try {
                $token = (new Installer())->install([
                    'data_dir' => $state['data_dir'],
                    'admin' => $state['admin'],
                    'site' => $state['site'],
                    'database' => $state['database'],
                ]);

                SetupLock::release();
                $siteName = $state['site']['name'];
                // The setup session holds the admin hash and app DB password; drop it entirely.
                $_SESSION = [];
                session_destroy();

                echo dodo_view('layout', [
                    'title' => 'Installed',
                    'content' => dodo_view('setup/done', ['token' => $token, 'siteName' => $siteName]),
                ]);
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage();
            }
        }

        setup_render($step, [
            'errors' => $errors,
            'state' => $state,
            'exposed' => $exposed,
            'crypto' => Crypto::backend(),
            'excluded' => Installer::excludedPaths($state['site']['target_path'], $state['data_dir']),
        ]);
}
