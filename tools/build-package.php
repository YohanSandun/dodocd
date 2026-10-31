<?php

declare(strict_types=1);

/**
 * Builds the DodoCD package: {out}/dodocd-{version}.zip, used both for fresh installs (unzip and
 * upload the "dodocd" folder) and for updates from the dashboard. It contains the repository's files
 * (tracked and untracked, minus ignored ones and development files) and agent/manifest.json with
 * every file's SHA-256, signed with the release key when one is given.
 *
 * Usage: php tools/build-package.php [--out build] [--tag v1.2.3] [--key key.pem] [--require-signature]
 *
 * The private key comes from --key or the DODOCD_SIGNING_KEY environment variable (PEM contents).
 */

use Dodo\Installer\Requirements;
use Dodo\Update\Package;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/agent/bootstrap.php';

/** Repository paths that are for development, not for running DodoCD. */
const DEV_ONLY = ['.github/', 'tools/', 'build/', '.gitignore', '.gitattributes', 'CLAUDE.md'];

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function git(string $args): string
{
    exec('git -C ' . escapeshellarg(DODO_ROOT) . " {$args}", $lines, $status);

    return $status === 0 ? implode("\n", $lines) : fail("git {$args} failed. Is git installed?");
}

$options = getopt('', ['out:', 'tag:', 'key:', 'require-signature']);
$out = $options['out'] ?? DODO_ROOT . '/build';
$version = DODO_VERSION;

if (isset($options['tag']) && $options['tag'] !== "v{$version}") {
    fail("The tag {$options['tag']} doesn't match DODO_VERSION {$version} in agent/bootstrap.php. Bump one of them.");
}

$keyPem = isset($options['key']) ? (string) @file_get_contents($options['key']) : (string) getenv('DODOCD_SIGNING_KEY');
if ($keyPem === '' && isset($options['require-signature'])) {
    fail('No signing key. Pass --key or set DODOCD_SIGNING_KEY.');
}

$files = [];
foreach (array_filter(explode("\0", git('ls-files -z --cached --others --exclude-standard'))) as $path) {
    $devOnly = false;
    foreach (DEV_ONLY as $prefix) {
        $devOnly = $devOnly || $path === rtrim($prefix, '/') || str_starts_with($path, $prefix);
    }
    foreach (Package::INSTALL_STATE as $state) {
        $devOnly = $devOnly || $path === $state || str_starts_with($path, $state . '/');
    }
    if (!$devOnly && !in_array($path, [Package::MANIFEST, Package::SIGNATURE], true) && is_file(DODO_ROOT . '/' . $path)) {
        $files[$path] = hash_file('sha256', DODO_ROOT . '/' . $path);
    }
}
ksort($files, SORT_STRING);

if (trim(git('status --porcelain')) !== '') {
    fwrite(STDERR, "warning: the working tree has uncommitted changes, and they are included in the package.\n");
}

$manifest = json_encode([
    'name' => 'dodocd',
    'version' => $version,
    'commit' => trim(git('rev-parse HEAD')),
    'built_at' => gmdate('Y-m-d H:i:s'),
    'requires' => [
        'php' => Requirements::MIN_PHP,
        'sqlite' => Requirements::MIN_SQLITE,
        'extensions' => ['pdo_sqlite', 'zip', 'json'],
    ],
    'files' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$signature = null;
if ($keyPem !== '') {
    $key = openssl_pkey_get_private($keyPem) ?: fail('The signing key is not a valid PEM private key.');
    if (!openssl_sign($manifest, $raw, $key, OPENSSL_ALGO_SHA256)) {
        fail('Signing failed: ' . openssl_error_string());
    }
    $signature = base64_encode($raw) . "\n";

    // Catches a key that installs wouldn't accept, before anything is published.
    try {
        Dodo\Update\Signature::verify($manifest, $signature);
    } catch (Dodo\Update\UpdateException) {
        fail('The signing key does not match any key in Dodo\Update\Signature::PUBLIC_KEYS.');
    }
} else {
    fwrite(STDERR, "warning: no signing key, so the package is UNSIGNED. Installs will warn before using it.\n");
}

if (!is_dir($out) && !mkdir($out, 0755, true)) {
    fail("Could not create {$out}.");
}
$zipFile = "{$out}/dodocd-{$version}.zip";
@unlink($zipFile);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fail("Could not create {$zipFile}.");
}
foreach (array_keys($files) as $path) {
    $zip->addFile(DODO_ROOT . '/' . $path, Package::ROOT . '/' . $path);
}
$zip->addFromString(Package::ROOT . '/' . Package::MANIFEST, $manifest);
if ($signature !== null) {
    $zip->addFromString(Package::ROOT . '/' . Package::SIGNATURE, $signature);
}
if (!$zip->close()) {
    fail("Could not write {$zipFile}.");
}

$sha256 = hash_file('sha256', $zipFile);
file_put_contents("{$zipFile}.sha256", "{$sha256}  " . basename($zipFile) . "\n");

printf("Built %s: %d files, %s, %s.\nsha256 %s\n", $zipFile, count($files), dodo_format_bytes((int) filesize($zipFile)),
    $signature !== null ? 'signed' : 'UNSIGNED', $sha256);
