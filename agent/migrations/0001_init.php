<?php

declare(strict_types=1);

// Initial schema: just what a fresh install needs. Releases, deployments and
// app-migration tracking arrive as later migrations.
return static function (PDO $pdo): void {
    $statements = [
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT \'admin\',
            created_at TEXT NOT NULL,
            last_login_at TEXT
        )',

        'CREATE TABLE sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            target_path TEXT NOT NULL,
            excluded_paths TEXT NOT NULL DEFAULT \'[]\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )',

        'CREATE TABLE site_databases (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            driver TEXT NOT NULL DEFAULT \'mysql\',
            host TEXT NOT NULL,
            port INTEGER NOT NULL DEFAULT 3306,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            password_enc TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )',

        'CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            prefix TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            last_used_at TEXT,
            revoked_at TEXT
        )',

        'CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )',

        'CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            site_id INTEGER REFERENCES sites(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            details TEXT,
            created_at TEXT NOT NULL
        )',

        'CREATE INDEX audit_log_created_at ON audit_log (created_at)',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
};
