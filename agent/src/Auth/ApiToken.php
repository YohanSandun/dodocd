<?php

declare(strict_types=1);

namespace Dodo\Auth;

use PDO;

/** Deploy tokens: "dodo_" + 64 hex chars, stored only as a SHA-256 hash. */
final class ApiToken
{
    /** Reads the token from "Authorization: Bearer …", or the X-Dodo-Token header as a fallback. */
    public static function fromRequest(): ?string
    {
        // CGI/FastCGI setups often drop the Authorization header; .htaccess copies it into
        // HTTP_AUTHORIZATION, which shows up as REDIRECT_HTTP_AUTHORIZATION after the rewrite.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                }
            }
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return $matches[1];
        }

        $fallback = trim($_SERVER['HTTP_X_DODO_TOKEN'] ?? '');

        return $fallback !== '' ? $fallback : null;
    }

    /** @return array{token: array, site: array}|null The token and its site, or null if invalid or revoked. */
    public static function authenticate(PDO $pdo, string $token): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL');
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $statement = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $statement->execute([$row['site_id']]);
        $site = $statement->fetch();
        if ($site === false) {
            return null;
        }

        $pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([dodo_now(), $row['id']]);

        return ['token' => $row, 'site' => $site];
    }
}
