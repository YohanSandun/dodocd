<?php

declare(strict_types=1);

namespace Dodo\Auth;

use PDO;
use RuntimeException;

/**
 * The signed-in admin's session. Session files live in {data_dir}/sessions rather than
 * the host's shared session folder, so other sites on the server can't read them and
 * the host's garbage collection settings don't sign people out early.
 */
final class Session
{
    public const NAME = 'dodocd';
    /** Signed out after this many seconds without a request. */
    public const IDLE_TIMEOUT = 8 * 3600;

    private static ?array $user = null;

    public static function start(string $dataDir): void
    {
        $dir = "{$dataDir}/sessions";
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'path' => dodo_url('/'),
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => dodo_is_https(),
        ]);
        session_start([
            'save_path' => $dir,
            'use_strict_mode' => 1,
            'gc_maxlifetime' => self::IDLE_TIMEOUT,
            // Hosts that clean their own session folder by cron often set this to 0; nothing cleans ours.
            'gc_probability' => 1,
            'gc_divisor' => 100,
        ]);

        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** The signed-in user, or null. Ends the session if it has been idle too long or the password changed. */
    public static function user(PDO $pdo): ?array
    {
        if (self::$user !== null || !isset($_SESSION['user_id'])) {
            return self::$user;
        }

        if (time() - ($_SESSION['last_seen'] ?? 0) > self::IDLE_TIMEOUT) {
            self::signOut();
            return null;
        }

        $statement = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$_SESSION['user_id']]);
        $user = $statement->fetch();

        if ($user === false || !hash_equals($_SESSION['auth'] ?? '', self::fingerprint($user))) {
            self::signOut();
            return null;
        }

        $_SESSION['last_seen'] = time();

        return self::$user = $user;
    }

    public static function signIn(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'auth' => self::fingerprint($user),
            'last_seen' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
        self::$user = $user;
    }

    public static function signOut(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = null;
    }

    public static function csrfValid(): bool
    {
        return hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
    }

    /** Changes when the password does, which signs out every other session. */
    private static function fingerprint(array $user): string
    {
        return hash('sha256', $user['id'] . '|' . $user['password_hash']);
    }
}
