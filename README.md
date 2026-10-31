# DodoCD

Self-hosted CI/CD for PHP sites on shared hosting (Apache or LiteSpeed). Your CI pipeline builds a zip and sends it to DodoCD, which puts it live on your site.

**Status:** early development. Done so far: the setup wizard, the zip deploy endpoint, a dashboard with deploy history, rollbacks and multiple sites, and SQL migrations for each site's own MySQL database. Planned: database backups before migrating, deploy token management, status monitoring.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo_sqlite`, `zip`, `json`, and `sodium` or `openssl` |
| SQLite library | 3.7.17 or newer (3.25+ recommended) |
| Web server | Apache with `mod_rewrite`, or LiteSpeed, with `.htaccess` enabled |
| Optional | `pdo_mysql`, needed for database migrations |

The largest zip you can deploy is limited by PHP's `upload_max_filesize` and `post_max_size`. The setup wizard checks all of this for you.

## Installation

1. Upload DodoCD to a folder inside your web root, e.g. `public_html/dodocd`.
2. Open `https://example.com/dodocd/` in a browser. You'll be taken to the setup wizard.
3. Go through the steps:
   - **Requirements:** checks the server. Red items must be fixed first.
   - **Data directory:** where DodoCD keeps its database, releases and logs. The default is a folder next to your web root, e.g. `/home/you/.dodocd-data`, so nothing in it can be downloaded. If PHP can't write there, it uses `dodocd/storage` and blocks web access to it.
   - **Admin account:** for signing in to the dashboard.
   - **Main site:** the site's URL and the folder deploys are written to, usually `public_html`.
   - **App database (optional):** your site's MySQL database, used for [migrations](#database-migrations). It can be skipped and added later. The password is stored encrypted.
   - **Finish:** installs DodoCD and shows the site's **deploy token**.
4. Copy the deploy token into your CI's secrets (e.g. `DODOCD_TOKEN`). It is shown only once, because only a hash of it is stored.

To run setup again, delete `dodocd/agent/config.php`. Any existing database is renamed to `dodo.sqlite.bak-<timestamp>` rather than deleted.

## Deploying

```
POST /dodocd/api/sites/{site}/deploy
Authorization: Bearer <deploy token>
Content-Type: multipart/form-data
```

`{site}` is the site's slug. Setup creates `main-site` for a site named "Main site".

| Field | Required | Description |
|---|---|---|
| `archive` | yes | The zip to deploy |
| `ref` | no | Branch or tag, for the deploy history |
| `commit` | no | Commit SHA |
| `message` | no | Commit message or note |

If your host strips the `Authorization` header, send the token as `X-Dodo-Token: <token>` instead.

### Example with curl

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $DODOCD_TOKEN" \
  -F archive=@release.zip \
  --form-string ref=main \
  --form-string commit="$(git rev-parse HEAD)" \
  https://example.com/dodocd/api/sites/main-site/deploy
```

Send text fields with `--form-string`. With `-F`, curl treats a value starting with `@` or `<` as a file to read.

### Example GitHub Actions workflow

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      # Build here: shared hosting usually can't run Composer or npm, so ship vendor/ and built assets.
      # - run: composer install --no-dev --optimize-autoloader
      # - run: npm ci && npm run build

      - name: Package
        run: zip -r ../release.zip . -x '.git/*' '.github/*' 'node_modules/*' 'tests/*'

      - name: Deploy
        env:
          DODOCD_TOKEN: ${{ secrets.DODOCD_TOKEN }}
          COMMIT_MESSAGE: ${{ github.event.head_commit.message }}
        run: |
          curl --fail-with-body \
            -H "Authorization: Bearer $DODOCD_TOKEN" \
            -F archive=@../release.zip \
            --form-string ref="$GITHUB_REF_NAME" \
            --form-string commit="$GITHUB_SHA" \
            --form-string message="$COMMIT_MESSAGE" \
            https://example.com/dodocd/api/sites/main-site/deploy
```

Zip the **contents** of your build, not the folder that holds them. A zip with a single top-level `dist/` folder deploys everything under `dist/`; DodoCD logs a warning when it sees that.

### Response

The request waits until the deploy finishes, then returns JSON:

```json
{
    "ok": true,
    "deployment": {
        "id": 12,
        "status": "success",
        "release_id": 7,
        "stats": { "added": 1, "updated": 3, "unchanged": 120, "deleted": 2, "skipped": 0 },
        "error": null,
        "duration_ms": 1840,
        "migrations": [
            { "name": "0002_add_status.sql", "status": "applied", "statements_run": 1, "statements_total": 1, "duration_ms": 35, "error": null }
        ],
        "log": ["[  0.01s] Deployment #12 started for site \"main-site\".", "..."]
    }
}
```

On failure, `ok` is `false` and `error` says why. The `deployment` object (with its log) is included whenever the failure happened after the deployment was recorded. `migrations` lists the migrations this deploy ran, including one that failed.

| Status | Meaning |
|---|---|
| 200 | Deployed |
| 401 | Missing or invalid token |
| 403 | The token belongs to a different site |
| 409 | Another deploy is running for this site, or migrations are already running against its database |
| 413 | The upload is larger than PHP's upload limits |
| 422 | Bad request, invalid archive (e.g. a path with `../`, a symlink, an empty zip), or a migration problem: a failing statement, an edited migration, a database that needs a baseline, or migrations with no database configured |
| 500 | The deploy failed while copying files. The previous release was restored if possible. |
| 503 | DodoCD isn't installed yet, or the site's database can't be reached |

## Dashboard

Open `https://example.com/dodocd/` and sign in with the admin account from setup. For each site, the dashboard shows:

- the live release, with its ref and commit
- the site's folder, database and deploy endpoint URL to use in CI
- the 20 most recent deployments. Click one to see its details, the migrations it ran and the full log.

Pages showing a running deployment refresh every few seconds until it finishes. You're signed out after 8 hours without activity.

Each site has **Settings**, **Database** and **Migrations** pages, linked from the dashboard.

### Sites

Click **Add site** to deploy another site. Each site has its own folder, deploy token, deploy endpoint and, optionally, its own database. The token is shown once, right after the site is added.

- **Folders can't overlap.** A site's folder can't be the same as, inside or around another site's folder, and can't be inside DodoCD's own folder or its data directory. If sites shared files, a deploy of one could delete files of the other.
- **The slug stays.** The slug in the deploy URL (e.g. `main-site`) is set when the site is added and doesn't change when you rename it, so CI keeps working.
- **Changing a site's folder** doesn't move any files. The next deploy copies the release into the new folder and is treated as a first deploy there, so nothing in it is deleted.
- **Database settings:** the password is stored encrypted and never shown again. Leave the field empty to keep the stored password. If you change the host, port or user, you have to enter the password again, so the stored one can't be sent to a different server.
- **No changes during a deploy.** A site's settings, database and baselines can't be changed while a deploy or rollback is running for it. Try again when it finishes.

### Rolling back

Under **Roll back**, the dashboard lists the site's earlier releases that can be made live again, most recently live first. A deployment's page also has a button when its release can be rolled back to. A release qualifies if it:

- was live at some point (a failed deploy's release doesn't count)
- is still kept on disk (the 5 most recent releases are kept)
- isn't the live release.

A rollback works like a deploy, without uploading anything:

- The chosen release is copied into the site folder. Files that the live release had and the chosen one doesn't are deleted. Files DodoCD never deployed are left alone.
- If copying fails, the live release is copied back.
- It takes the same per-site lock, so it can't run at the same time as a deploy.
- It's listed in the deploy history as a rollback, with who started it.

After rolling back, you can switch forward again the same way: newer kept releases appear as **Redeploy #N**.

Rolling back only changes files. It never runs or undoes database migrations. The confirmation and the rollback's log list any migrations that stay applied, or that the release has but aren't applied.

## Database migrations

DodoCD can change your site's MySQL / MariaDB database as part of a deploy. Each site uses its own database, set on the site's **Database** page (or in setup for the main site).

Put plain SQL files in a `.dodocd/migrations/` folder **in the zip**:

```
.dodocd/
  migrations/
    0001_create_orders.sql
    0002_add_order_status.sql
index.php
...
```

```sql
-- 0002_add_order_status.sql
ALTER TABLE orders ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new';
```

How they run:

- **Before the files are copied.** If a migration fails, the deploy stops, no files are copied, and the previous release stays live.
- **In filename order**, each file once. Use names that sort, like `0001_…` or `20261030_1200_…`. Only names with letters, digits, `.`, `_` and `-` ending in `.sql` are allowed in that folder.
- **One statement at a time.** Comments are fine, and `DELIMITER` works for triggers and procedures, like in the `mysql` client.
- **Tracked in your database.** Applied migrations are recorded in a `dodocd_migrations` table in the site's database, so the record survives reinstalling DodoCD and matches your data if you restore a database backup.
- **`.dodocd/` is never copied** into the site folder, so your SQL can't be downloaded.

Rules:

- **Never edit a migration after it has run.** DodoCD keeps a checksum of each applied file and refuses a deploy where one has changed (line endings and a UTF-8 BOM don't count). Add a new migration instead.
- **Keep migrations compatible with the previous release.** While files are copied, and after a rollback, the old code runs against the new schema. Add columns and tables first, and remove old ones in a later release ("expand, then contract").
- **One change per file where you can.** MySQL commits schema changes (`CREATE`, `ALTER`, …) immediately, so a file that fails halfway can leave the database partly changed. The error says which statement failed and how many had already run. You can use `START TRANSACTION` / `COMMIT` in a file for data changes, but a file must not leave a transaction open.
- If the site has no database configured, a release that contains migrations is refused.

### Existing databases: baseline first

If the database already has tables but no `dodocd_migrations` table, for example because the site existed before DodoCD, the first deploy with migrations is refused **before running anything**. Open the site's **Migrations** page, choose that release, and **baseline**: mark the migrations whose changes are already in the database as applied, without running them. Then deploy again to run the rest. An empty database needs no baseline.

The **Migrations** page also shows, for any kept release, which of its migrations are applied, pending or changed, and the history of migrations DodoCD has run for the site.

## How a deploy works

1. The zip is checked before anything is extracted. Entries with `../`, absolute paths or backslashes are rejected, as are symlinks, more than 100,000 entries, and more than 1 GB of uncompressed content.
2. It's extracted into a new release folder in the data directory.
3. Its [database migrations](#database-migrations), if any, run against the site's database.
4. The release is copied into the site's folder:
   - Files in the zip are copied in. Unchanged files are skipped, and each file is written to a temp name and then renamed, so no file is ever half-written.
   - Files that were in the **previous release** but not this one are deleted.
   - Files DodoCD never deployed, such as `.env`, user uploads and caches, are **never deleted**.
   - Protected paths are never written or deleted. This includes DodoCD's own folder when it lives inside the site folder.
   - The `.dodocd/` folder isn't copied.
5. If copying fails, the previous release is copied back. If migrations had run, the error says so: the previous release's files now run against the new schema.
6. The live release and the 4 most recent releases that were live before it are kept, so you can roll back to them. Of the releases that never went live (failed deploys), only the newest is kept, so its migrations can be baselined. Older release folders are removed.

Only one deploy runs per site at a time. A deploy keeps going even if the CI connection drops.

Things to know:

- **Same path means overwrite.** Any file in the site folder with the same path as a file in the zip is replaced.
- **First deploy:** nothing is deleted, because there's no previous release to compare against.
- **Mixed files:** while files are being copied, visitors can briefly see a mix of old and new files.

## Testing locally with XAMPP

Point the site at **its own folder**, e.g. `D:/xampp8/htdocs/myapp`, not at `htdocs` itself:

- phpMyAdmin is safe either way: it lives in `xampp/phpMyAdmin`, outside `htdocs`.
- If the site's folder is `htdocs` itself, your zip's `index.php` would replace XAMPP's own `htdocs/index.php`. Any other paths your zip shares with existing files would be overwritten too. Other projects in `htdocs` are only touched if the zip contains files at the same paths.

To try migrations, start MySQL in the XAMPP control panel and create an empty database for the site in phpMyAdmin. Then set it on the site's **Database** page (host `127.0.0.1`, user `root`, empty password on a default XAMPP).

## Security notes

- `agent/config.php` holds the key that decrypts stored secrets. Never commit or share it. It's gitignored.
- Deploy tokens are stored only as SHA-256 hashes. Keep them in your CI's secret store, never in files in your repo.
- `.htaccess` blocks web access to `agent/`, `storage/` and dotfiles, so only `index.php` can be requested.
- After 5 failed sign-ins for the same email from one IP address, or 20 from one IP address, sign-in is blocked for 15 minutes.
- Dashboard sessions are stored in the data directory, not in the host's shared session folder.
- Site database passwords are encrypted with the key in `agent/config.php`. If that file is replaced, enter the passwords again on each site's **Database** page.
- Until setup finishes, anyone who opens the setup URL can install. Complete setup right after uploading. A second browser can't run setup while one is in progress.

## Development

| Path | What it is |
|---|---|
| `index.php` | Front controller and routing |
| `agent/setup.php`, `agent/views/setup/` | Setup wizard |
| `agent/app.php`, `agent/views/` | Sign-in and dashboard |
| `agent/api.php` | JSON API for CI |
| `agent/src/Auth/` | Deploy tokens, sign-in, sessions |
| `agent/src/Installer/` | Requirement checks, data directory, install |
| `agent/src/Deploy/` | Zip validation, file copying, deploy orchestration |
| `agent/src/AppDb/` | Site database connections, SQL splitting, migration runner and log |
| `agent/src/Sites/` | Adding and validating sites and their database settings |
| `agent/migrations/` | DodoCD's own SQLite schema |

Plain PHP: no Composer, no build step. Classes in the `Dodo\` namespace are autoloaded from `agent/src/`.

DodoCD's own schema changes go in a new numbered file in `agent/migrations/`. They're applied automatically on the next request. Keep them compatible with SQLite 3.7.17: no `UPSERT`, `RENAME COLUMN`, `DROP COLUMN`, window functions or `RETURNING`.
