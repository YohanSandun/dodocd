<?php

declare(strict_types=1);

use Dodo\Auth\ApiToken;
use Dodo\Deploy\Deployer;
use Dodo\Deploy\DeployException;

/**
 * JSON API controller, called by CI pipelines. Included by index.php.
 *
 * POST /api/sites/{slug}/deploy
 *   Authorization: Bearer <deploy token>
 *   multipart fields: archive (zip file, required), ref, commit, message (optional)
 *
 * @var string $apiPath The part of the URL after /api, e.g. "/sites/main-site/deploy".
 */

function api_respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit;
}

function api_error(int $status, string $message, ?array $deployment = null): never
{
    api_respond($status, ['ok' => false, 'error' => $message] + ($deployment ? ['deployment' => $deployment] : []));
}

/** Optional text field from the request, trimmed and capped. */
function api_field(string $name, int $maxLength): ?string
{
    $value = trim((string) ($_POST[$name] ?? ''));

    if ($value === '') {
        return null;
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

$config = dodo_config();
if ($config === null) {
    api_error(503, 'DodoCD is not installed yet.');
}

if (!preg_match('#^/sites/([a-z0-9-]+)/deploy$#', $apiPath, $matches)) {
    api_error(404, 'Unknown API endpoint.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_error(405, 'Use POST to deploy.');
}

$token = ApiToken::fromRequest();
$auth = $token === null ? null : ApiToken::authenticate(dodo_db(), $token);
if ($auth === null) {
    header('WWW-Authenticate: Bearer');
    api_error(401, 'Missing or invalid deploy token.');
}
if ($auth['site']['slug'] !== $matches[1]) {
    api_error(403, 'This token cannot deploy to that site.');
}

// When the body is larger than post_max_size, PHP silently discards all of it.
$postMax = dodo_ini_bytes((string) ini_get('post_max_size'));
if ($postMax > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
    api_error(413, 'The request is larger than the server\'s post_max_size (' . ini_get('post_max_size') . ').');
}

$upload = $_FILES['archive'] ?? null;
if ($upload === null || is_array($upload['error'])) {
    api_error(422, 'Send the zip as a single multipart form field named "archive".');
}

switch ($upload['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        api_error(413, 'The archive is larger than the server\'s upload_max_filesize (' . ini_get('upload_max_filesize') . ').');
    case UPLOAD_ERR_PARTIAL:
        api_error(400, 'The upload was interrupted. Try again.');
    case UPLOAD_ERR_NO_FILE:
        api_error(422, 'The "archive" field is empty.');
    default:
        api_error(500, "The server could not store the upload (PHP upload error {$upload['error']}).");
}

// A deploy must not stop halfway because the CI client disconnected or PHP's time limit hit.
ignore_user_abort(true);
@set_time_limit(0);

try {
    $deployment = (new Deployer(dodo_db(), $config['data_dir']))->deploy(
        (int) $auth['site']['id'],
        (int) $auth['token']['id'],
        $upload,
        [
            'ref' => api_field('ref', 255),
            'commit' => api_field('commit', 64),
            'message' => api_field('message', 1000),
        ],
    );
    api_respond(200, ['ok' => true, 'deployment' => $deployment]);
} catch (DeployException $e) {
    api_error($e->status, $e->getMessage(), $e->deployment);
}
