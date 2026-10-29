<?php

declare(strict_types=1);

// Failed dashboard sign-ins, used to throttle password guessing. Rows older than a day are purged.
return static function (PDO $pdo): void {
    $statements = [
        'CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL COLLATE NOCASE,
            ip TEXT NOT NULL,
            created_at TEXT NOT NULL
        )',

        'CREATE INDEX login_attempts_ip ON login_attempts (ip, created_at)',
        'CREATE INDEX login_attempts_created_at ON login_attempts (created_at)',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
};
