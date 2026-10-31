<?php

declare(strict_types=1);

// Log of app database migrations run by deploys or baselined from the dashboard.
// Display only: what has been applied is decided by the dodocd_migrations table
// in each site's own database.
return static function (PDO $pdo): void {
    $statements = [
        'CREATE TABLE app_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            deployment_id INTEGER REFERENCES deployments(id) ON DELETE SET NULL,
            release_id INTEGER REFERENCES releases(id) ON DELETE SET NULL,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            name TEXT NOT NULL,
            checksum TEXT NOT NULL,
            status TEXT NOT NULL,
            statements_run INTEGER NOT NULL DEFAULT 0,
            statements_total INTEGER NOT NULL DEFAULT 0,
            error TEXT,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )',

        'CREATE INDEX app_migrations_site ON app_migrations (site_id, id)',
        'CREATE INDEX app_migrations_deployment ON app_migrations (deployment_id)',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
};
