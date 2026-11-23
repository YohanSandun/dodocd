# Security policy

DodoCD holds deploy tokens and database passwords, writes into your web root and updates itself. Security reports are taken seriously and are always welcome.

## Supported versions

Security fixes are released for the latest version only. Update from the dashboard's **Updates** page, or download the newest package from the [releases page](https://github.com/YohanSandun/dodocd/releases).

| Version | Supported |
|---|---|
| Latest release | Yes |
| Older releases | No. Update to the latest release. |

## Reporting a vulnerability

**Please don't open a public issue, pull request or discussion for a security problem.**

Report it privately through GitHub instead:

1. Go to the repository's [**Security** tab](https://github.com/YohanSandun/dodocd/security) and click **Report a vulnerability**, or open [a new advisory](https://github.com/YohanSandun/dodocd/security/advisories/new) directly.
2. Describe the problem. Helpful details:
   - the DodoCD version, and the PHP version and web server (Apache or LiteSpeed) if they matter
   - what an attacker needs: network access only, a deploy token, a dashboard account, write access to the server
   - steps or a proof of concept that shows the problem
   - what an attacker gains, e.g. reading secrets, writing files outside a site's folder, running code

Only the maintainer and you can see the report.

## What happens next

DodoCD is maintained by one person, so these are goals rather than guarantees:

- **Within 5 days:** you get a reply confirming the report was received.
- **Within 14 days:** you hear whether it's confirmed as a vulnerability, and roughly when a fix will be released.
- **Fix:** a new signed release is published, followed by a GitHub security advisory that describes the problem and the fixed version. Request a CVE in the report if you'd like one.
- **Credit:** you're credited in the advisory, unless you'd rather not be.

Please give a fix time to be released before you disclose the problem publicly. If there's no fix 90 days after the report, you're free to disclose it.

## Scope

In scope: the code in this repository and the release packages published on its releases page. For example:

- bypassing sign-in, deploy token checks or the sign-in rate limit
- a zip, migration or update package that writes or deletes files outside where it should
- reading stored secrets, deploy tokens or another site's data
- installing a tampered update package that DodoCD accepts as signed
- cross-site scripting, cross-site request forgery or SQL injection in the dashboard or API

Out of scope:

- problems that need an attacker to already control `agent/config.php`, the data directory or the server
- finishing setup on a fresh install before the owner does. This is a known limitation, described in the README's [security notes](README.md#security-notes).
- installing an unsigned package after confirming the warning on the **Updates** page
- your hosting provider's configuration, e.g. a server that ignores `.htaccess`
- missing security headers, or reports from automated scanners without a demonstrated impact

## Verifying a release

Release packages contain `agent/manifest.json`, which lists the SHA-256 of every file, and `agent/manifest.json.sig`, an ECDSA P-256 signature of the manifest. DodoCD checks both before installing an update and refuses a package whose signature doesn't match. Each release also has a `.sha256` file for the zip.
