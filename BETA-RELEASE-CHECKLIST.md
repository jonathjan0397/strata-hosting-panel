# Beta Release Checklist

Target release: `v1.0.0-beta.1`

Target validation OS: Debian 13 (trixie)

Validation host: Debian 13.4 on `stratadevplatform.net`, upgraded from public `main`.

## Release Gate

- [x] Public `main` branch upgrade completes using `installer/upgrade.sh --branch main`.
- [x] Tagged release upgrade path is documented using `installer/upgrade.sh --version v1.0.0-beta.1`.
- [x] Rollback backup is created before upgrade.
- [x] Remote node agent cascade command runs after branch/tag upgrades.
- [x] Panel login page returns HTTP 200.
- [x] Webmail page returns HTTP 200.
- [x] Core services are active: `strata-agent`, `strata-queue`, `nginx`, `php8.4-fpm`, `dovecot`, `postfix`, `fail2ban`.
- [x] Laravel migrations run cleanly.
- [x] Frontend production build passes.
- [x] PHP syntax lint passes on release-touched files.
- [x] Bash syntax lint passes on installer scripts.
- [x] Linux-targeted Go tests pass.

## Smoke Test Scope

- [x] Public demo admin, reseller, user, and reseller-client credentials remain documented.
- [x] Admin can access dashboard and core admin menus.
- [x] Domain add/delete/re-add works and cleans up DNS zones.
- [x] Admin can view and manage all DNS zones.
- [x] New hosted DNS zones include web and mail records with public node IPs.
- [x] Mail domain enablement provisions DKIM/SPF/DMARC and SnappyMail profiles.
- [x] SnappyMail uses IMAPS `993` and full mailbox logins instead of `localhost:143`.
- [x] Dovecot authentication succeeds for a seeded demo mailbox.
- [x] Fail2Ban administration shows default jails and service actions work.
- [x] phpMyAdmin and phpPgAdmin routes return a non-404 response.
- [x] Existing public demo seed can be rerun if needed.

## Validation Notes

- Validated release commit: `c53605b`.
- Smoke server upgrade created rollback backup `/opt/strata-panel-backups/20260407-162634`.
- Remote agent cascade completed on the single-node smoke install and reported no remote online nodes requiring upgrade.
- Verified public demo credential hashes for the admin, reseller, user, and reseller-client demo accounts.
- Verified SnappyMail `default` and `stratadevplatform.net` profiles use IMAPS `993`, authenticated SMTP `587`, and full mailbox logins.
- Verified active Fail2Ban jails: `dovecot`, `nginx-http-auth`, `postfix`, `postfix-sasl`, and `sshd`.

## Known Beta Limitations

- Migration cutover is intentionally conservative and blocks accounts with mailboxes, forwarders, FTP users, databases, database grants, or app installs.
- Full two-node migration prepare/transfer/restore/cutover should be tested before claiming production-grade multi-node migration support.
- cPanel backup import compatibility is not implemented yet; current import support registers existing Strata backup archives.
- phpMyAdmin and phpPgAdmin use manual login, not automatic SSO.
- Web Disk parity is currently FTPS connection guidance backed by jailed FTP accounts, not a full WebDAV server.
- Some long-running workflows outside account migrations still run synchronously.
- Debian 13 is the primary beta validation target; Debian 11/12 are intended targets but need fresh-install beta validation before stronger claims.

## Tagging Criteria

Tag `v1.0.0-beta.1` only after the release gate passes or any remaining failures are documented here as accepted beta limitations.
