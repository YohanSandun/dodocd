# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

DodoCD is a self-hosted CI/CD tool for PHP sites on shared hosting (Apache/LiteSpeed). CI uploads a zip to an API endpoint and DodoCD copies it into the site's folder. See `README.md` for the user-facing behaviour and API contract; keep it in sync when behaviour changes.

## Environment and commands

- Plain PHP 8.1+, no Composer, no build step, no test suite.
- Use XAMPP's PHP for CLI work: `/d/xampp8/php/php.exe` (8.1.25, same as Apache). The `php` on PATH (`C:\php`, 8.5) has no `pdo_sqlite` and will fail.
- Lint everything: `for f in $(git ls-files '*.php'); do /d/xampp8/php/php.exe -l "$f" >/dev/null || echo "FAIL $f"; done`
- The app is served at `http://localhost/dodocd/`. **This checkout is also the developer's live install** (`agent/config.php` exists, data dir `D:/xampp8/.dodocd-data`). Don't delete `agent/config.php` or the data dir to re-test setup. For end-to-end tests, copy the repo to a throwaway folder in `htdocs` (and a throwaway target-site folder), install there via HTTP, and delete both afterwards.
- XAMPP's PHP has no `sodium`; the OpenSSL fallback in `Crypto` is what runs locally.
- XAMPP's MariaDB (10.4, `root` with no password on `127.0.0.1:3306`) is what migrations are tested against. Use throwaway `dodotest_*` databases and drop them afterwards.
- Apache may not be running. `php -S 127.0.0.1:8099 index.php` from a throwaway copy works for end-to-end tests (base path is then empty, and `.htaccess` isn't applied).

## Architecture

**Request flow.** `.htaccess` sends everything to `index.php` and returns 403 for `agent/`, `storage/` and dotfiles, so `index.php` is the only entry point. `index.php` loads `agent/bootstrap.php` (constants, `Dodo\` → `agent/src/` autoloader, `src/helpers.php`), then routes: `/api*` → `agent/api.php`, `/setup*` → `agent/setup.php` (404 once installed), everything else redirects to `/setup` until installed and then goes to `agent/app.php` (sign-in and dashboard). Controllers are plain scripts that receive `$apiPath` / `$setupPath` / `$path` from `index.php`. The base path comes from `SCRIPT_NAME`, so never hardcode `/dodocd`; build URLs with `dodo_url()`.

**Install state.** "Installed" means `agent/config.php` exists. It's written last and atomically by `Installer::writeConfig` and holds only what's needed to open SQLite: `data_dir`, `app_key` (encrypts stored secrets via `Crypto`), `base_path`, `version`. Everything else lives in `{data_dir}/dodo.sqlite`. The data dir is normally outside the webroot (`DataDir::propose`), falling back to `storage/` with deny rules.

**DodoCD's own schema.** `dodo_db()` opens SQLite (`Db\Sqlite`, WAL + busy_timeout) and runs `Db\Migrator` on every request, so new files in `agent/migrations/` (`NNNN_name.php` returning `function (PDO $pdo)`) apply automatically to existing installs. Never edit an applied migration; add a new one. Must stay compatible with SQLite 3.7.17 (the floor in `Installer\Requirements`): no UPSERT, `RENAME COLUMN`, `DROP COLUMN`, window functions or `RETURNING`. These tables are DodoCD's own data, separate from the deployed app's optional MySQL DB (`site_databases`, password encrypted).

**Deploy pipeline** (`agent/api.php` → `Deploy\Deployer::deploy`):
1. `Auth\ApiToken` checks the bearer token (or `X-Dodo-Token`) against SHA-256 hashes in `api_tokens`; each token is bound to one site.
2. A per-site `flock` in `{data_dir}/tmp` makes concurrent deploys return 409; stale `running` rows are marked failed.
3. `Deploy\Archive` validates every zip entry (traversal, absolute paths, backslashes, symlinks, entry/size caps) *before* extracting to `tmp/deploy-{id}`, which is then renamed to `releases/{site_id}/{release_id}`.
4. `Deployer::migrate` runs the release's `.dodocd/migrations/*.sql` against the site's MySQL database *before* any file is copied (see App database migrations below). A failure stops the deploy with the old release still live.
5. `Deploy\Sync` copies the release into `sites.target_path`. Its invariants are the core safety guarantee, so preserve them:
   - It only deletes files that were in the **previous release** and aren't in the new one. Files DodoCD never deployed (`.env`, uploads) are never touched.
   - It never reads or writes `excluded_paths` (DodoCD's own folder and data dir when they're inside the target; see `Installer::excludedPaths`).
   - `Sync::RELEASE_ONLY` (`.dodocd/`) is filtered out of the *source* list only, so it's never copied, but copies made by older releases still get deleted.
   - It writes each file to a temp name and then renames it, and skips unchanged files (same size and xxh128 hash).
6. If copying fails, Deployer syncs the previous release back. On success it sets `sites.current_release_id`. `pruneReleases` runs after every deploy, including failed ones. It keeps the live release, the `KEEP_RELEASES - 1` newest previously-live releases, and the newest never-live release if it's newer than the live one (for baselining).
7. Failures throw `DeployException` with an HTTP status (`MigrationException` statuses are carried over); `api.php` turns it into JSON along with the deployment summary, migrations and log.

**Rollback** (`POST /releases/{id}/rollback` in `app.php` → `Deployer::rollback`) reuses the same lock and `activate()` (sync, restore on failure, set `current_release_id`), and records a `deployments` row with `type = 'rollback'` and `user_id`. It doesn't prune or run migrations; it only logs drift (`MigrationLog::drift`). Valid targets are releases that aren't live, aren't pruned, and had a successful deployment. `Deployer::rollbackTargets()` lists them for the UI, and `checkRollbackTarget()` enforces the same rules, so keep the two in sync.

**App database migrations** (`agent/src/AppDb/`): each site has at most one MySQL/MariaDB database (`site_databases`, password encrypted with `dodo_crypto()`).
- **Connection shape:** always `{host, port, name, username, password}`. `Connection::open` uses emulated prepares (needed for `CREATE TRIGGER`/`PROCEDURE`), sets `autocommit = 1`, and rethrows `PDOException` without the original exception, so the password stays out of traces.
- **`SqlSplitter`:** splits statements like the `mysql` client does. It handles quotes, `DELIMITER`, and comments, which it drops so PDO doesn't mistake a `?` in a comment for a placeholder; `/*! */` is kept. It also normalizes BOM/CRLF, which checksums use too.
- **`MigrationRunner`:** the **source of truth** is the `dodocd_migrations` table *in the app database*. It takes a per-database `GET_LOCK` (409 when busy).
  - Refuses an untracked database that already has tables (422, "baseline first") and refuses changed checksums.
  - Runs pending files in `strcmp` order, one statement at a time, and fails a file that leaves a transaction open.
  - `status()` is read-only, so GET pages may call it. `baseline()` marks files as applied without running them.
- **`MigrationLog`** (the SQLite `app_migrations` table) is display/warnings only. Never use it to decide what runs.

**Sites** (`Sites\Sites`, dashboard routes `/sites/new` and `/sites/{slug}/{settings,database,migrations}` in `app.php`): setup and the dashboard share `validate`, `create`, `update` and the database helpers.
- **Validation:** target paths are canonicalised, then rejected if they overlap another site, DodoCD's folder or the data dir.
- **Slugs** never change (CI URLs use them), and `new` is reserved.
- **Target path change:** clears `current_release_id`, so the next deploy deletes nothing in the new folder.
- **Stored DB password:** reused only when host, port and user are unchanged.
- **Site lock:** settings, database and baseline writes run under `Deployer::withSiteLock`, so they can't race a deploy.
- **New token:** shown once via a session flash and a redirect to `/sites/{slug}/token`.

**Setup wizard** (`agent/setup.php`, views in `agent/views/setup/`): ordered steps stored in the session, CSRF on every POST, and `Installer\SetupLock` so only one browser can run setup at a time. `Installer\Installer::install` does all the writes in the finish step.

**Dashboard auth** (`agent/app.php`, `Auth\Session`, `Auth\Login`): the session is named `dodocd`, stored in `{data_dir}/sessions` with its own GC settings, and has an 8-hour idle timeout. A fingerprint of the password hash is kept in the session, so changing the password signs out every session. Every route except `/login` requires a signed-in user, and every POST is checked against the CSRF token. Failed sign-ins go to `login_attempts` and are throttled per email + IP and per IP. Successful sign-ins and sign-outs are written to `audit_log`.

## Conventions

- Global helpers are prefixed `dodo_` (plus `e()` for HTML escaping); classes go under `Dodo\` in `agent/src/`, one per file.
- Views are plain PHP templates rendered with `dodo_view()` inside `layout.php`. Escape output with `e()` and add `dodo_csrf_field()` to every form. A form with `data-confirm` asks first; put a submitted action in a hidden field rather than on the button, because the button is disabled after submit.
- Writes in the dashboard go to `audit_log` via `app_audit()`, without secrets. Success messages after a redirect go through `app_flash()`.
- Timestamps are stored as UTC `Y-m-d H:i:s` from `dodo_now()`.
- Write files in the target folder or config atomically (temp file + `rename`). Shared hosts can disable functions like `symlink`, so check that they're available before relying on them (see `Requirements::functionAvailable`, which also reads `disable_functions`).
- `agent/deploy.php` and `agent/src/auth.php` are empty leftovers.
