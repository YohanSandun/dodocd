<?php

declare(strict_types=1);

namespace Dodo\Update;

/**
 * Checks the ECDSA P-256 / SHA-256 signature on a package manifest. The manifest lists every
 * file's hash, so a valid signature covers the whole package. tools/build-package.php signs it.
 */
final class Signature
{
    /** Release keys whose signatures are trusted. Add the new key before retiring an old one. */
    public const PUBLIC_KEYS = [
        <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEYRM9mqxNFpmZ+RDA3PKmXB3/Ftxg
        xFD+cmqgK11acdhmDqPKbPqdVcOdZS05ua4b1nraj6SQdE2cfC3B7a1xKg==
        -----END PUBLIC KEY-----
        PEM,
    ];

    /**
     * @param string|null $signature The base64 signature file's contents, or null when the package has none.
     * @return array{status: 'valid'|'unsigned'|'unverifiable', detail: string}
     * @throws UpdateException When there is a signature and it doesn't match: the package was changed after signing.
     */
    public static function verify(string $manifest, ?string $signature): array
    {
        if ($signature === null) {
            return ['status' => 'unsigned', 'detail' => 'The package has no signature.'];
        }
        if (!function_exists('openssl_verify')) {
            return ['status' => 'unverifiable', 'detail' => 'The package is signed, but this server has no OpenSSL extension to check the signature.'];
        }

        $raw = base64_decode(trim($signature), true);
        if ($raw !== false) {
            foreach (self::PUBLIC_KEYS as $key) {
                if (openssl_verify($manifest, $raw, $key, OPENSSL_ALGO_SHA256) === 1) {
                    return ['status' => 'valid', 'detail' => 'Signed with the DodoCD release key.'];
                }
            }
        }

        throw new UpdateException('The package signature does not match. It was changed after it was built, '
            . 'or it was not signed with the DodoCD release key. Download it again from the release page.');
    }
}
