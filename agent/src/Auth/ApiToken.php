<?php

declare(strict_types=1);

namespace Dodo\Auth;

use PDO;

/** Deploy tokens: "dodo_" + 64 hex chars, stored only as a SHA-256 hash. */
final class ApiToken
{
    public const NAME_MAX_LENGTH = 100;

    /** @return array{token: string, prefix: string, hash: string} A new token; only prefix and hash are stored. */
    public static function generate(): array
    {
        $token = 'dodo_' . bin2hex(random_bytes(32));

        return ['token' => $token, 'prefix' => substr($token, 0, 12), 'hash' => hash('sha256', $token)];
    }

    /** @return array{id: int, name: string, prefix: string, token: string} The token is returned only here; just its hash is stored. */
    public static function create(PDO $pdo, int $siteId, string $name): array
    {
        $token = self::generate();
        $pdo->prepare('INSERT INTO api_tokens (site_id, name, prefix, token_hash, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, $name, $token['prefix'], $token['hash'], dodo_now()]);

        return ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'prefix' => $token['prefix'], 'token' => $token['token']];
    }

    /** @return list<string> Problems with a new token's name. Names must be unique among the site's active tokens. */
    public static function validateName(PDO $pdo, int $siteId, string $name): array
    {
        if ($name === '') {
            return ['Give the token a name, for example the CI or repository that uses it.'];
        }
        if (preg_match('//u', $name) !== 1) {
            return ['The name contains invalid characters.'];
        }
        // Counts UTF-8 characters without mbstring, which isn't a requirement.
        if (preg_match_all('/./su', $name) > self::NAME_MAX_LENGTH) {
            return ['The name can be at most ' . self::NAME_MAX_LENGTH . ' characters.'];
        }

        $statement = $pdo->prepare('SELECT 1 FROM api_tokens WHERE site_id = ? AND name = ? COLLATE NOCASE AND revoked_at IS NULL');
        $statement->execute([$siteId, $name]);

        return $statement->fetchColumn() === false ? [] : ['This site already has an active token with that name.'];
    }

    /** @return list<array> The site's tokens, active ones first, newest first. Never includes hashes. */
    public static function forSite(PDO $pdo, int $siteId): array
    {
        $statement = $pdo->prepare('SELECT id, name, prefix, created_at, last_used_at, revoked_at FROM api_tokens
                                    WHERE site_id = ? ORDER BY revoked_at IS NOT NULL, id DESC');
        $statement->execute([$siteId]);

        return $statement->fetchAll();
    }

    /**
     * Revokes one of the site's tokens. Deploys already authenticated with it finish normally.
     *
     * @return array|null The revoked token, or null if the site has no such active token.
     */
    public static function revoke(PDO $pdo, int $siteId, int $tokenId): ?array
    {
        $statement = $pdo->prepare('SELECT id, name, prefix FROM api_tokens WHERE id = ? AND site_id = ? AND revoked_at IS NULL');
        $statement->execute([$tokenId, $siteId]);
        $token = $statement->fetch();
        if ($token === false) {
            return null;
        }

        $pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([dodo_now(), $tokenId]);

        return $token;
    }

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
