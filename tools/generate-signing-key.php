<?php

declare(strict_types=1);

/**
 * Creates the ECDSA P-256 key pair that signs DodoCD release packages.
 *
 * Usage: php tools/generate-signing-key.php <private-key-file>
 *
 * The private key goes in the GitHub secret DODOCD_SIGNING_KEY and a password manager,
 * never in the repository. The public key it prints goes in Dodo\Update\Signature::PUBLIC_KEYS.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$file = $argv[1] ?? null;
if ($file === null) {
    fwrite(STDERR, "Usage: php tools/generate-signing-key.php <private-key-file>\n");
    exit(1);
}
if (file_exists($file)) {
    fwrite(STDERR, "{$file} already exists. Refusing to overwrite a signing key.\n");
    exit(1);
}

$repo = realpath(dirname(__DIR__));
$parent = realpath(dirname($file));
if ($parent === false) {
    fwrite(STDERR, 'Folder ' . dirname($file) . " does not exist.\n");
    exit(1);
}
if (str_starts_with(strtolower($parent . DIRECTORY_SEPARATOR), strtolower($repo . DIRECTORY_SEPARATOR))) {
    fwrite(STDERR, "Keep the private key outside the repository.\n");
    exit(1);
}

$options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
// Windows PHP builds often can't find openssl.cnf on their own.
if (getenv('OPENSSL_CONF') === false && is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
    $options['config'] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
}

$key = openssl_pkey_new($options);
if ($key === false || !openssl_pkey_export($key, $private, null, $options)) {
    fwrite(STDERR, 'Could not create the key: ' . openssl_error_string() . "\n");
    exit(1);
}

$old = umask(077);
$written = file_put_contents($file, $private);
umask($old);
if ($written === false) {
    fwrite(STDERR, "Could not write {$file}.\n");
    exit(1);
}

echo "Private key written to {$file}.\n";
echo "Store it as the GitHub secret DODOCD_SIGNING_KEY, then keep a copy somewhere safe.\n\n";
echo "Public key, for Dodo\\Update\\Signature::PUBLIC_KEYS:\n\n";
echo openssl_pkey_get_details($key)['key'];
