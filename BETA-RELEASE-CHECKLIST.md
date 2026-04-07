# Beta Release Checklist

Target release: `v1.0.0-beta.1`

Target validation OS: Debian 13 (trixie)

Validation host: Debian 13.4 on `stratadevplatform.net`, upgraded from public `main`.

## Release Gate

- [ ] Public `main` branch upgrade completes using `installer/upgrade.sh --branch main`.
- [ ] Tagged release upgrade path is documented using `installer/upgrade.sh --version v1.0.0-beta.1`.
- [ ] Rollback backup is created before upgrade.
- [ ] Remote node agent cascade command runs after branch/tag upgrades.
- [ ] Panel login page returns HTTP 200.
- [ ] Webmail page returns HTTP 200.
- [ ] Core services are active: `strata-agent`, `strata-queue`, `nginx`, `php8.4-fpm`, `dovecot`, `postfix`, `fail2ban`.
- [ ] Laravel migrations run cleanly.
- [ ] Frontend production build passes.
- [ ] PHP syntax lint passes on release-touched files.
- [ ] Bash syntax lint passes on installer scripts.
- [ ] Linux-targeted Go tests pass.

## Smoke Test Scope

- [ ] Public demo admin, reseller, user, and reseller-client credentials remain documented.
- [ ] Admin can access dashboard and core admin menus.
- [ ] Domain add/delete/re-add works and cleans up DNS zones.
- [ ] Admin can view and manage all DNS zones.
- [ ] New hosted DNS zones include web and mail records with public node IPs.
- [ ] Mail domain enablement provisions DKIM/SPF/DMARC and SnappyMail profiles.
- [ ] SnappyMail uses IMAPS `993` and full mailbox logins instead of `localhost:143`.
- [ ] Dovecot authentication succeeds for a seeded demo mailbox.
- [ ] Fail2Ban administration shows default jails and service actions work.
- [ ] phpMyAdmin and phpPgAdmin routes return a non-404 response.
- [ ] Existing public demo seed can be rerun if needed.

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
