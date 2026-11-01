# Contributing to DodoCD

See `README.md` for what DodoCD does and how it's used. This file covers working on DodoCD itself.

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

## Releasing

A release is a GitHub release with `dodocd-x.y.z.zip` and its `.sha256`. The zip holds a single `dodocd` folder with:
- the repository's files, without `.github/`, `tools/`, `CLAUDE.md`, `CONTRIBUTING.md` and `.gitignore`;
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
