<?php

declare(strict_types=1);

namespace Dodo;

use InvalidArgumentException;
use RuntimeException;

/**
 * Symmetric encryption for secrets stored in SQLite (e.g. app database passwords).
 * Uses libsodium when available and falls back to OpenSSL AES-256-GCM, since
 * many shared hosts (and XAMPP) ship without the sodium extension enabled.
 * The payload is prefixed with the backend name so either can decrypt its own output.
 */
final class Crypto
{
    private const KEY_BYTES = 32;
    private const GCM_IV_BYTES = 12;
    private const GCM_TAG_BYTES = 16;

    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('Invalid app key.');
        }

        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /** "sodium", "openssl", or null when neither is usable. */
    public static function backend(): ?string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'sodium';
        }
        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            return 'openssl';
        }

        return null;
    }

    public function encrypt(string $plaintext): string
    {
        return match (self::backend()) {
            'sodium' => 'sodium:' . $this->encryptSodium($plaintext),
            'openssl' => 'openssl:' . $this->encryptOpenssl($plaintext),
            default => throw new RuntimeException('No encryption backend is available.'),
        };
    }

    public function decrypt(string $payload): string
    {
        [$backend, $data] = array_pad(explode(':', $payload, 2), 2, '');
        $raw = base64_decode($data, true);

        $plaintext = match ($backend) {
            'sodium' => $raw === false ? false : $this->decryptSodium($raw),
            'openssl' => $raw === false ? false : $this->decryptOpenssl($raw),
            default => false,
        };

        if ($plaintext === false) {
            throw new RuntimeException('Could not decrypt value.');
        }

        return $plaintext;
    }

    private function encryptSodium(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    private function decryptSodium(string $raw): string|false
    {
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
    }

    private function encryptOpenssl(string $plaintext): string
    {
        $iv = random_bytes(self::GCM_IV_BYTES);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_BYTES);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private function decryptOpenssl(string $raw): string|false
    {
        $iv = substr($raw, 0, self::GCM_IV_BYTES);
        $tag = substr($raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES);
        $cipher = substr($raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES);

        return openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}
