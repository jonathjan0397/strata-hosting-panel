# Changelog

## [Unreleased] — MVP Complete

### Added — 2026-04-05 (MVP Gap Pass 2)

**Email**
- Autoresponders: Dovecot Sieve vacation scripts, per-mailbox, subject + body + active toggle
- Spam filter stats UI: Rspamd stats page (scanned / spam / ham / actions), per-node

**DNS**
- Zone import: paste BIND-format zone file, SOA/NS skipped, all other record types supported

**Databases**
- DB user grants: grant additional MariaDB users to existing databases (with revoke)

**Security**
- SSH key management: add/remove SSH authorized keys per hosting account (user + admin)

**Backups**
- Remote backup destinations: SFTP (scp/SSH key) and S3-compatible; admin-configurable;
  auto-push to all active destinations after each backup is created

**Navigation**
- Admin: Remote Backups, Spam Filter nav items added
- User: SSH Keys nav item added

---

### Added — 2026-04-05 (MVP Gap Pass 1)

**Agent**
- Firewall management (UFW rules): list, add, delete rules
- OS update management: list available packages, safe apply
- Custom SSL cert storage: store cert+key PEM, parse expiry, re-provision vhost

**Panel**
- Custom SSL cert upload (user + admin domain views)
- Domain redirects (301/302) — JSON stored, rendered as nginx/apache directives
- Custom Nginx/Apache directives UI — textarea, save & apply
- DNS zone export — BIND format download (user + admin)
- Firewall management admin UI
- OS update management admin UI
- `redirects` column on domains table

---

### Added — 2026-04-04 to 2026-04-05

**Phase 6 — Reseller Portal**
- Reseller dashboard with quota meters
- Create/manage client accounts with quota enforcement
- Suspend/unsuspend/delete client accounts
- Client detail page with resource limit editing
- Admin: create/view/update/delete reseller quotas
- White-label branding per reseller (panel name + accent colour)

**Phase 7 — Billing API (partial)**
- REST provisioning API: create, suspend, unsuspend, terminate, usage
- Bearer token auth (Laravel Sanctum) + admin token management UI

**Phase 5 — End User Portal + Agent**
- End user portal: domains, email, databases, FTP, DNS
- File manager (Go agent + Vue UI)
- Backup system (files + databases, nightly schedule, manual trigger, restore)
- 2FA (TOTP) — admin, reseller, end user
- Audit log viewer (admin, filterable, paginated)
- SSL auto-renew (artisan scheduler)
- Browser-based SSH terminal (admin, per-node, xterm.js + PTY)
- Email deliverability troubleshooter (MX, SPF, DKIM, DMARC, PTR, blacklists)

**Phase 3 + 4**
- Mail stack: Postfix + Dovecot + Rspamd + OpenDKIM
- DKIM/SPF/DMARC auto-setup on domain add
- PowerDNS zone management + full record type support
- MariaDB per-user databases
- Pure-FTPd jailed accounts
