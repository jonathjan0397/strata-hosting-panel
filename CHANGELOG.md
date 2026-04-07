# Changelog

## [v1.0-Beta] — 2026-04-05

### Added — Agent hardening

**Agent**
- Firewall APIs now require `ufw` to already be installed on the node instead of auto-installing and enabling it during status reads
- Fail2ban APIs now require `fail2ban-client` to already be installed on the node instead of auto-installing and enabling fail2ban during status reads
- OS update handler now runs `apt-get update -q` before `apt-get upgrade` to refresh the package index and prevent stale-metadata failures

### Added — Admin features

**Backup Schedules**
- Per-account backup schedule configuration: frequency (disabled / daily / weekly), time (HH:MM), day of week (weekly only)
- New migration: `backup_schedule`, `backup_time`, `backup_day` columns on `accounts` table
- `BackupRun` command updated with `--scheduled` flag: filters accounts whose schedule matches the current hour window; respects daily vs weekly
- Scheduler changed from `->dailyAt('02:00')` to `->hourly()` with `--scheduled`; admin UI at Admin → Backup Schedules

**Server DNS Zones**
- Manage standalone DNS zones not tied to any hosted account (server hostname, custom delegations, etc.)
- Full record CRUD (A, AAAA, CNAME, MX, TXT, SRV, CAA, NS) via same PowerDNS agent API
- New migration: `domain_id` and `account_id` made nullable on `dns_zones`; `zone_name` unique index added
- Admin UI at Admin → Server DNS; routes `admin.dns.server.*`

---

## [v0.9] — 2026-04-05

### Added — MVP Gap Pass 2

**Email**
- Autoresponders: Dovecot Sieve vacation scripts, per-mailbox, subject + body + active toggle
- Spam filter stats UI: Rspamd stats page (scanned / spam / ham / actions), per-node

**DNS**
- Zone import: paste BIND-format zone file, SOA/NS skipped, all other record types supported

**Databases**
- DB user grants: grant additional MariaDB users to existing databases (with revoke)
- PostgreSQL database/user lifecycle support through the same Databases workspace

**Security**
- SSH key management: add/remove SSH authorized keys per hosting account (user + admin)
- ClamAV malware scanner for jailed account paths with optional quarantine

**Backups**
- Remote backup destinations: SFTP (scp/SSH key) and S3-compatible; admin-configurable; auto-push to all active destinations after each backup is created

**Navigation**
- Admin: Remote Backups, Spam Filter nav items added
- User: SSH Keys nav item added

---

## [v0.8] — 2026-04-05

### Added — MVP Gap Pass 1

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

---

## [v0.7] — 2026-04-04 to 2026-04-05

### Added — Phase 6 + Phase 7 partial

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

---

## [v0.5] — 2026-04-03 to 2026-04-04

### Added — Phase 5

**End User Portal + Agent**
- End user portal: domains, email, databases, FTP, DNS
- File manager (Go agent + Vue UI)
- Backup system (files + databases, nightly schedule, manual trigger, restore)
- 2FA (TOTP) — admin, reseller, end user
- Audit log viewer (admin, filterable, paginated)
- SSL auto-renew (artisan scheduler)
- Browser-based SSH terminal (admin, per-node, xterm.js + PTY)
- Email deliverability troubleshooter (MX, SPF, DKIM, DMARC, PTR, blacklists)

---

## [v0.3] — 2026-04-01 to 2026-04-03

### Added — Phase 3 + Phase 4

**Mail Stack**
- Postfix + Dovecot provisioning
- DKIM/SPF/DMARC auto-setup on domain add
- Rspamd integration

**DNS + Databases + FTP**
- PowerDNS zone management + full record type support
- MariaDB/PostgreSQL per-user databases
- Pure-FTPd jailed accounts

---

## [v0.1] — 2026-03-31 to 2026-04-01

### Added — Phase 1 + Phase 2

**Foundation**
- Laravel scaffold, authentication (Admin/Reseller/User roles)
- Node/agent system (Go binary, HMAC-SHA256, health reporting, systemd)
- Basic UI shell (AppLayout, nav, dark theme)

**Core Server Functions**
- Account management + system user provisioning
- Domain + vhost management (Nginx + Apache)
- SSL via acme.sh (Let's Encrypt)
- PHP-FPM multi-version (8.1 / 8.2 / 8.3)
- One-line bash installer (`installer/install.sh`)
