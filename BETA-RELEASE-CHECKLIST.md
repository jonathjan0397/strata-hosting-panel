# Beta Release Checklist

Target release: `v1.0.0-alpha.4`

Target validation OS: Debian 13 (trixie)

Validation host: Debian 13.4 on `stratadevplatform.net`, upgraded from public `main`.

## Release Gate

- [x] Public `main` branch upgrade completes using `installer/upgrade.sh --branch main`.
- [x] Tagged release upgrade path is documented using `installer/upgrade.sh --version v1.0.0-alpha.4`.
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
- [ ] Browser verification passes for admin, reseller, and user sessions with no console errors.
- [ ] Admin sidebar renders `Resellers`, `Security`, `System`, `Infrastructure`, and `Hosting`.
- [ ] Ziggy route payload includes the routes used by the visible sidebar sections.

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

- Validated release commit: `611dfc3`.
- Smoke server upgrade created rollback backup `/opt/strata-panel-backups/20260407-193535`.
- Remote agent cascade completed on the single-node smoke install and reported no remote online nodes requiring upgrade.
- Verified public demo credential hashes for the admin, reseller, user, and reseller-client demo accounts.
- Verified SnappyMail `default` and `stratadevplatform.net` profiles use IMAPS `993`, authenticated SMTP `587`, and full mailbox logins.
- Verified active Fail2Ban jails: `dovecot`, `nginx-http-auth`, `postfix`, `postfix-sasl`, and `sshd`.

## Known Alpha.3 Limitations

- Migration cutover is intentionally conservative and blocks accounts with mailboxes, FTP users, databases, database grants, or app installs. Credentialless forwarders are now re-provisioned during cutover.
- Full two-node migration prepare/transfer/restore/cutover should be tested before claiming production-grade multi-node migration support.
- cPanel/CWP backup import compatibility currently converts website files and detected SQL dumps into Strata full-backup jobs and previews detected domains, DNS zones, mailboxes, and forwarders. It does not recover original mailbox/FTP/database passwords or proprietary control-panel settings.
- phpMyAdmin and phpPgAdmin use manual login, not automatic SSO.
- Web Disk parity is currently FTPS connection guidance backed by jailed FTP accounts, not a full WebDAV server.
- Migration, malware scans, manual backup creation, backup restore, and path restore are queued. Some less common maintenance workflows may still run synchronously.
- Debian 13 is the primary alpha validation target; Debian 11/12 are intended targets but need fresh-install alpha validation before stronger claims.

## Tagging Criteria

Tag `v1.0.0-alpha.4` only after the release gate passes or any remaining failures are documented here as accepted alpha limitations.
