# DodoCD

Self-hosted CI/CD for PHP sites on shared hosting (Apache or LiteSpeed). Your CI pipeline builds a zip and sends it to DodoCD, which puts it live on your site.

**Status:** early development. Done so far: the setup wizard, the zip deploy endpoint, and a read-only dashboard. Planned: rollbacks, site settings and token management, app database migrations, status monitoring.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo_sqlite`, `zip`, `json`, and `sodium` or `openssl` |
| SQLite library | 3.7.17 or newer (3.25+ recommended) |
| Web server | Apache with `mod_rewrite`, or LiteSpeed, with `.htaccess` enabled |
| Optional | `pdo_mysql` (needed for app database features later) |

The largest zip you can deploy is limited by PHP's `upload_max_filesize` and `post_max_size`. The setup wizard checks all of this for you.

## Installation

1. Upload DodoCD to a folder inside your web root, e.g. `public_html/dodocd`.
2. Open `https://example.com/dodocd/` in a browser. You'll be taken to the setup wizard.
3. Go through the steps:
   - **Requirements:** checks the server. Red items must be fixed first.
   - **Data directory:** where DodoCD keeps its database, releases and logs. The default is a folder next to your web root, e.g. `/home/you/.dodocd-data`, so nothing in it can be downloaded. If PHP can't write there, it uses `dodocd/storage` and blocks web access to it.
   - **Admin account:** for signing in to the dashboard.
   - **Main site:** the site's URL and the folder deploys are written to, usually `public_html`.
   - **App database (optional):** your site's MySQL database. It can be skipped. The password is stored encrypted.
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
        "log": ["[  0.01s] Deployment #12 started for site \"main-site\".", "..."]
    }
}
```

On failure, `ok` is `false` and `error` says why. The `deployment` object (with its log) is included whenever the failure happened after the deployment was recorded.

| Status | Meaning |
|---|---|
| 200 | Deployed |
| 401 | Missing or invalid token |
| 403 | The token belongs to a different site |
| 409 | Another deploy is running for this site |
| 413 | The upload is larger than PHP's upload limits |
| 422 | Bad request or invalid archive (e.g. a path with `../`, a symlink, an empty zip) |
| 500 | The deploy failed while copying files. The previous release was restored if possible. |
| 503 | DodoCD isn't installed yet |

## Dashboard

Open `https://example.com/dodocd/` and sign in with the admin account from setup. For each site, the dashboard shows:

- the live release, with its ref and commit
- the deploy endpoint URL to use in CI
- the 20 most recent deployments. Click one to see its details and full log.

Pages showing a running deployment refresh every few seconds until it finishes. You're signed out after 8 hours without activity.

The dashboard is read-only for now. Rollbacks, site settings and deploy tokens will be managed from here later.

## How a deploy works

1. The zip is checked before anything is extracted. Entries with `../`, absolute paths or backslashes are rejected, as are symlinks, more than 100,000 entries, and more than 1 GB of uncompressed content.
2. It's extracted into a new release folder in the data directory.
3. The release is copied into the site's folder:
   - Files in the zip are copied in. Unchanged files are skipped, and each file is written to a temp name and then renamed, so no file is ever half-written.
   - Files that were in the **previous release** but not this one are deleted.
   - Files DodoCD never deployed, such as `.env`, user uploads and caches, are **never deleted**.
   - Protected paths are never written or deleted. This includes DodoCD's own folder when it lives inside the site folder.
4. If copying fails, the previous release is copied back.
5. The 5 most recent release folders are kept, for rollbacks later.

Only one deploy runs per site at a time. A deploy keeps going even if the CI connection drops.

Things to know:

- **Same path means overwrite.** Any file in the site folder with the same path as a file in the zip is replaced.
- **First deploy:** nothing is deleted, because there's no previous release to compare against.
- **Mixed files:** while files are being copied, visitors can briefly see a mix of old and new files.

## Testing locally with XAMPP

Point the site at **its own folder**, e.g. `D:/xampp8/htdocs/myapp`, not at `htdocs` itself:

- phpMyAdmin is safe either way: it lives in `xampp/phpMyAdmin`, outside `htdocs`.
- If the site's folder is `htdocs` itself, your zip's `index.php` would replace XAMPP's own `htdocs/index.php`. Any other paths your zip shares with existing files would be overwritten too. Other projects in `htdocs` are only touched if the zip contains files at the same paths.

There's no settings screen yet. To change the site's folder, run setup again, or update `sites.target_path` in the SQLite database.

## Security notes

- `agent/config.php` holds the key that decrypts stored secrets. Never commit or share it. It's gitignored.
- Deploy tokens are stored only as SHA-256 hashes. Keep them in your CI's secret store, never in files in your repo.
- `.htaccess` blocks web access to `agent/`, `storage/` and dotfiles, so only `index.php` can be requested.
- After 5 failed sign-ins for the same email from one IP address, or 20 from one IP address, sign-in is blocked for 15 minutes.
- Dashboard sessions are stored in the data directory, not in the host's shared session folder.
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
| `agent/migrations/` | DodoCD's own SQLite schema |

Plain PHP: no Composer, no build step. Classes in the `Dodo\` namespace are autoloaded from `agent/src/`.

DodoCD's own schema changes go in a new numbered file in `agent/migrations/`. They're applied automatically on the next request. Keep them compatible with SQLite 3.7.17: no `UPSERT`, `RENAME COLUMN`, `DROP COLUMN`, window functions or `RETURNING`.
