<?php

declare(strict_types=1);

// A release is an extracted archive kept under {data_dir}/releases/{site_id}/{release_id}.
// A deployment is one attempt to make a release live (later also rollbacks).
return static function (PDO $pdo): void {
    $statements = [
        'CREATE TABLE releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            source TEXT NOT NULL DEFAULT \'upload\',
            archive_name TEXT,
            archive_size INTEGER,
            archive_sha256 TEXT,
            file_count INTEGER NOT NULL DEFAULT 0,
            ref TEXT,
            commit_sha TEXT,
            message TEXT,
            path TEXT NOT NULL,
            created_at TEXT NOT NULL,
            pruned_at TEXT
        )',

        'CREATE INDEX releases_site ON releases (site_id, id)',

        'CREATE TABLE deployments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            release_id INTEGER REFERENCES releases(id) ON DELETE SET NULL,
            token_id INTEGER REFERENCES api_tokens(id) ON DELETE SET NULL,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            type TEXT NOT NULL DEFAULT \'deploy\',
            status TEXT NOT NULL,
            stats TEXT,
            log TEXT,
            error TEXT,
            started_at TEXT NOT NULL,
            finished_at TEXT
        )',

        'CREATE INDEX deployments_site ON deployments (site_id, id)',

        'ALTER TABLE sites ADD COLUMN current_release_id INTEGER REFERENCES releases(id) ON DELETE SET NULL',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
};
