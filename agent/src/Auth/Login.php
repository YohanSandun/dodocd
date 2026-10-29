<?php

declare(strict_types=1);

namespace Dodo\Auth;

use PDO;

/**
 * Dashboard sign-in: checks an email and password, and throttles repeated failures
 * per email + IP and per IP, so passwords can't be guessed quickly.
 */
final class Login
{
    public const WINDOW_MINUTES = 15;
    public const MAX_PER_ACCOUNT = 5;
    public const MAX_PER_IP = 20;

    /** Verified against when the email is unknown, so both cases take the same time. */
    private const DUMMY_HASH = '$2y$10$xCi9SoUZQuKtYfzWjQoIy.ON00yFiOclju8yT9R47dJjDbPiXdfhm';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lockedOut(string $email, string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?');
        $statement->execute([$ip, $since]);
        if ((int) $statement->fetchColumn() >= self::MAX_PER_IP) {
            return true;
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND created_at >= ?');
        $statement->execute([$email, $ip, $since]);

        return (int) $statement->fetchColumn() >= self::MAX_PER_ACCOUNT;
    }

    /** @return array|null The user, or null if the email or password is wrong (the failure is recorded). */
    public function attempt(string $email, string $password, string $ip): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch() ?: null;

        if (!password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) || $user === null) {
            $this->pdo->prepare('DELETE FROM login_attempts WHERE created_at < ?')
                ->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
            $this->pdo->prepare('INSERT INTO login_attempts (email, ip, created_at) VALUES (?, ?, ?)')
                ->execute([$email, $ip, dodo_now()]);

            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$user['password_hash'], $user['id']]);
        }

        $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute([$email, $ip]);
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([dodo_now(), $user['id']]);
        $this->pdo->prepare('INSERT INTO audit_log (user_id, action, details, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], 'login', json_encode(['ip' => $ip]), dodo_now()]);

        return $user;
    }
}
