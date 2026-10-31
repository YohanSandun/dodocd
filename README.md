# DodoCD

Self-hosted CI/CD for PHP sites on shared hosting (Apache or LiteSpeed). Your CI pipeline builds a zip and sends it to DodoCD, which puts it live on your site.

**Version 1.0.** A setup wizard, the zip deploy endpoint, a dashboard with deploy history, rollbacks, multiple sites, deploy token management, SQL migrations for each site's own MySQL database, and updates from signed packages. Planned: database backups before migrating, status monitoring.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo_sqlite`, `zip`, `json`, and `sodium` or `openssl` |
| SQLite library | 3.7.17 or newer (3.25+ recommended) |
| Web server | Apache with `mod_rewrite`, or LiteSpeed, with `.htaccess` enabled |
| Optional | `pdo_mysql`, needed for database migrations. `curl` or `allow_url_fopen`, needed to check for updates |

The largest zip you can deploy is limited by PHP's `upload_max_filesize` and `post_max_size`. The setup wizard checks all of this for you.

## Installation

1. Download `dodocd-x.y.z.zip` from the [releases page](https://github.com/YohanSandun/dodocd/releases), unzip it, and upload the `dodocd` folder into your web root, e.g. as `public_html/dodocd`. A copy of this repository works too, but only an install made from a package lets [updates](#updating-dodocd) delete files that newer versions no longer have.
2. Open `https://example.com/dodocd/` in a browser. You'll be taken to the setup wizard.
3. Go through the steps:
   - **Requirements:** checks the server. Red items must be fixed first.
   - **Data directory:** where DodoCD keeps its database, releases and logs. The default is a folder next to your web root, e.g. `/home/you/.dodocd-data`, so nothing in it can be downloaded. If PHP can't write there, it uses `dodocd/storage` and blocks web access to it.
   - **Admin account:** for signing in to the dashboard.
   - **Main site:** the site's URL and the folder deploys are written to, usually `public_html`.
   - **App database (optional):** your site's MySQL database, used for [migrations](#database-migrations). It can be skipped and added later. The password is stored encrypted.
   - **Finish:** installs DodoCD and shows the site's **deploy token**.
4. Copy the deploy token into your CI's secrets (e.g. `DODOCD_TOKEN`). It is shown only once, because only a hash of it is stored. If you lose it, create a new one on the site's **Deploy tokens** page.

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
| 503 | DodoCD isn't installed yet, is [updating itself](#updating-dodocd), or the site's database can't be reached |

## Dashboard

Open `https://example.com/dodocd/` and sign in with the admin account from setup. For each site, the dashboard shows:

- the live release, with its ref and commit
- the site's folder, database and deploy endpoint URL to use in CI
- the 20 most recent deployments. Click one to see its details, the migrations it ran and the full log.

Pages showing a running deployment refresh every few seconds until it finishes. You're signed out after 8 hours without activity.

Each site has **Settings**, **Deploy tokens**, **Database** and **Migrations** pages, linked from the dashboard.

### Sites

Click **Add site** to deploy another site. Each site has its own folder, deploy token, deploy endpoint and, optionally, its own database. The token is shown once, right after the site is added.

- **Folders can't overlap.** A site's folder can't be the same as, inside or around another site's folder, and can't be inside DodoCD's own folder or its data directory. If sites shared files, a deploy of one could delete files of the other.
- **The slug stays.** The slug in the deploy URL (e.g. `main-site`) is set when the site is added and doesn't change when you rename it, so CI keeps working.
- **Changing a site's folder** doesn't move any files. The next deploy copies the release into the new folder and is treated as a first deploy there, so nothing in it is deleted.
- **Database settings:** the password is stored encrypted and never shown again. Leave the field empty to keep the stored password. If you change the host, port or user, you have to enter the password again, so the stored one can't be sent to a different server.
- **No changes during a deploy.** A site's settings, database and baselines can't be changed while a deploy or rollback is running for it. Try again when it finishes.

### Deploy tokens

A site's **Deploy tokens** page lists its tokens with when each was created and last used, and lets you create and revoke them. A site can have several active tokens, for example one per CI or repository.

- **Shown once.** A new token is shown right after you create it. Only its hash is stored, so it can't be shown again. Give it a name; the deploy history shows which token deployed.
- **Revoking** stops the token working immediately. A deploy that already started with it still finishes. Revoked tokens stay listed, so old deployments still show who deployed.
- **Rotating:** create a new token, update your CI secret, then revoke the old one.
- With no active token, every deploy to the site is refused with 401. The dashboard warns about this.

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

## Updating DodoCD

The **Updates** page, linked at the top of the dashboard, shows the running version and checks GitHub for a newer release. It checks when you open the page, at most every 12 hours, or when you click **Check now**. When a newer release exists, a **New** badge appears next to **Updates** on every page.

To update:

1. Download `dodocd-x.y.z.zip` from the [releases page](https://github.com/YohanSandun/dodocd/releases). The Updates page links to it.
2. Upload it on the Updates page. Nothing changes yet.
3. Review it: the version, whether it's signed, which files are added, changed and removed, and whether this server meets its requirements.
4. Click **Install**. It takes a few seconds.

What **Install** does:

- **Checks the package again.** Every file must match the SHA-256 listed in the package's manifest, and the manifest's signature must match DodoCD's release key.
- **Pauses deploys.** It doesn't start while a deploy or rollback is running. While it installs, every request, including deploys from CI, gets a 503 "DodoCD is updating itself".
- **Backs up** DodoCD's code and its database to `backups/update-…` in the data directory, with a `RESTORE.txt` that explains how to go back. The 3 newest backups are kept.
- **Copies the new files in**, the same way a deploy does. Files the old version had and the new one doesn't are deleted. `agent/config.php`, `storage/` and the data directory are never touched. If copying fails, the old files are copied back.
- The new version's database changes run on the next page load.

Things to know:

- **Signed packages.** Packages on the releases page are signed, and the review page says so. A package that isn't signed, for example one you built yourself, can still be installed, but only after a strong warning and a confirmation: installing a package runs its code with full access to your sites, database passwords and deploy tokens. A package whose signature doesn't match is always refused.
- **No downgrades.** DodoCD's database changes only go forward, so an older version is refused. To go back, follow the `RESTORE.txt` in the backup.
- **Installs from a copy of the repository** have no manifest, so the first update leaves files that the new version no longer has in place. That's harmless, and later updates delete removed files.
- **Updating by hand** still works: replace DodoCD's files, keeping `agent/config.php` and `storage/`, then open the dashboard.

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
- Update packages are checked against the release key built into DodoCD before anything is installed. Install an unsigned package only if you built it yourself.

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
| `agent/src/Update/` | Update packages, signature checks, the update check and installing updates |
| `agent/migrations/` | DodoCD's own SQLite schema |
| `tools/` | Building release packages and creating the signing key |
| `.github/workflows/release.yml` | Builds and publishes a release when a version tag is pushed |

Plain PHP: no Composer, no build step. Classes in the `Dodo\` namespace are autoloaded from `agent/src/`.

DodoCD's own schema changes go in a new numbered file in `agent/migrations/`. They're applied automatically on the next request. Keep them compatible with SQLite 3.7.17: no `UPSERT`, `RENAME COLUMN`, `DROP COLUMN`, window functions or `RETURNING`.

### Releasing

A release is a GitHub release with `dodocd-x.y.z.zip` and its `.sha256`. The zip holds a single `dodocd` folder with:
- the repository's files, without `.github/`, `tools/`, `CLAUDE.md` and `.gitignore`;
- `agent/manifest.json`, with the version, the requirements and every file's SHA-256;
- `agent/manifest.json.sig`, the manifest's signature.

1. Set `DODO_VERSION` in `agent/bootstrap.php` to the new version. Commit and push.
2. Tag the commit and push the tag:

   ```bash
   git tag -a v1.2.0 -m "DodoCD 1.2.0"
   git push origin v1.2.0
   ```

3. The **Release** workflow lints, builds and signs the package, and publishes the release with generated notes. It fails if the tag doesn't match `DODO_VERSION`, or if the signing key is missing or isn't one DodoCD trusts.

Running the workflow by hand (Actions → Release → Run workflow) builds a package as a workflow artifact without publishing anything.

To build locally, run `php tools/build-package.php [--key path/to/key.pem]`. It writes `build/dodocd-x.y.z.zip`, unsigned without a key, and includes uncommitted changes with a warning.

**Signing key.** Packages are signed with ECDSA P-256. The private key is the repository secret `DODOCD_SIGNING_KEY`, and the public keys DodoCD trusts are in `Dodo\Update\Signature::PUBLIC_KEYS`. Keep a copy of the private key in a password manager.

- **Create a key:** `php tools/generate-signing-key.php <file outside the repository>`. It prints the public key to add to `PUBLIC_KEYS`.
- **Replace a key:** add the new public key to `PUBLIC_KEYS` and release that version, signed with the old key. Then switch the secret to the new key, and remove the old public key in a later release. Installs only learn about a new key through an update.
- **If the private key leaks,** anyone can make packages that installs trust. Replace it straight away, as above.
