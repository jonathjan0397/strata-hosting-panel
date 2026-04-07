# Alpha Release Checklist

Target release: `v1.0.0-alpha.1`

Target validation OS: Debian 13 (trixie)

Validation host: Debian 13.4 on `stratadevplatform.net`, final pass from public `main` at `8e20c4e`.

## Release Gate

- [x] Fresh Debian 13 install completes from the public `main` branch installer.
- [x] Minimal Debian prerequisite check is documented: `curl` and `ca-certificates` may need to be installed before using the one-line installer.
- [x] Reset/uninstall script can remove the installation from the validation host.
- [x] Panel app boots over HTTPS or the installer clearly falls back to a self-signed certificate.
- [x] Agent service starts and responds locally.
- [x] Laravel migrations run from a clean database.
- [x] Queue worker is installed and active.
- [x] `npm run build` passes from the panel workspace.
- [x] PHP syntax lint passes on changed release files.
- [x] Go tests pass for a Linux target.

## Smoke Test Scope

- [x] Admin login page renders with installer-created app configuration.
- [x] Node inventory registers the primary node and agent responds.
- [x] Account create/delete works.
- [x] Domain create/delete and vhost reprovisioning work.
- [x] DNS zone/record provisioning works.
- [x] Mailbox creation and Sieve sync work.
- [x] MySQL/MariaDB database create/delete works.
- [x] PostgreSQL database create/delete works.
- [x] Backup create/delete works.
- [x] Malware scan runs against a jailed account path.
- [x] Migration cutover blocker visibility works for accounts with mail/database state.

## Known Alpha Limitations

- On minimal Debian installs, install `curl` and `ca-certificates` before using the one-line installer command.
- Migration cutover is intentionally conservative and blocks accounts with mailboxes, forwarders, FTP users, databases, database grants, or app installs.
- Full node-to-node migration prepare/transfer/restore was not exercised in the Alpha smoke test because this validation used a single-node host.
- cPanel backup import compatibility is not implemented yet; current import support registers existing Strata backup archives.
- phpMyAdmin and phpPgAdmin are exposed as launch/connection guidance, not automatic SSO.
- Web Disk parity is currently FTPS connection guidance backed by jailed FTP accounts, not a full WebDAV server.
- Some long-running workflows outside account migrations still run synchronously.
- The Alpha target is validation on Debian 13; Debian 11/12 remain intended targets but should be tested before claiming production-grade support.

## Tagging Criteria

Tag `v1.0.0-alpha.1` only after the release gate passes or any remaining failures are documented here as accepted Alpha limitations.
