# Changelog

## [v1.0-Beta] — 2026-04-05

### Added — Agent hardening

**Agent**
- Firewall APIs now require `ufw` to already be installed on the node instead of auto-installing and enabling it during status reads
- Fail2ban APIs now require `fail2ban-client` to already be installed on the node instead of auto-installing and enabling fail2ban during status reads
- Fail2Ban administration now has a dedicated admin menu/page under Firewall with jail ban/remove controls and service start/stop/restart actions
- Fail2Ban jail discovery now handles Debian `fail2ban-client status` tree-formatted output so active jails render in the admin page
- Installer Fail2Ban defaults now include SSH, Nginx auth, Postfix, Postfix SASL, and Dovecot jails with 10 failed attempts before a ban
- Installer mail setup now enables authenticated submission services on ports 587 and 465 and keeps unauthenticated relay restricted
- Installer setup now strongly recommends a dedicated panel subdomain, defaults to `panel.<base-domain>` from the server hostname, and warns if the apex/root domain is used for the panel
- New hosted document roots now receive an Apache/Nginx-style default `index.html` with a "Powered by Strata Hosting Panel" banner linking to GitHub when no `index.html` or `index.php` exists
- OS update handler now runs `apt-get update -q` before `apt-get upgrade` to refresh the package index and prevent stale-metadata failures

### Added — Admin features

**UI**
- Modern glassmorphism app shell with four persisted role-wide theme choices: Smoky Gray, Aurora Teal, Ember Gold, and Violet Bloom
- Theme preference picker is available in the shared top bar for admins, resellers, and users
- Admin sidebar now exposes Firewall and Fail2Ban in a dedicated Security group
- Shared Email Accounts sidebar entry is available for admins, resellers, and users
- Email Accounts password modal now uses a high-contrast field for readability across glass themes
- Mail client configuration cards now render directly from page data so secure port guidance is always visible
- Email Accounts now exposes shared DKIM/OpenDKIM key regeneration for admins, resellers, and users within their domain scope

**Accounts**
- Bulk package reassignment from the admin account list
- Admins and resellers can access an active sub-client hosting panel through audited support impersonation, with a persistent return banner
- Fixed reseller client detail loading by using the existing account database relationship

**API**
- Account migration workflow endpoints for list/detail/prepare/transfer/restore/cutover/source cleanup

**Migrations**
- Migration prepare, transfer, restore, cutover, and source cleanup actions now run through the queue worker with migration-row progress tracking

**Backups**
- Admin backup list supports bulk deletion while preserving panel records when node cleanup fails

**Domains**
- New hosted domains now automatically provision a managed PowerDNS zone with full default web/mail records during vhost creation
- Default DNS record seeding now refuses loopback/private node addresses such as `127.0.0.1` and falls back to the node hostname's public address when available
- Agent PowerDNS writes now quote TXT content automatically so SPF, DKIM, and DMARC records are accepted by PowerDNS while remaining readable in the panel
- Managed DNS zones now include nameserver records derived from the primary panel hostname, for example `panel.example.com` -> `ns1.example.com`
- DNS record writes and deletes are mirrored to other online nodes so successive nodes can act as backup DNS servers for managed zones
- Scheduled `dns:sync-backup-zones` backfills managed zones to online backup DNS nodes after a node comes online
- Users can delete their own domains from the domain detail page after a destructive warning; deletion removes the vhost, managed DNS zone, directory privacy settings, dedicated addon/subdomain document-root files, and the panel domain row so the domain can be re-added later
- Domain create validation now ignores and purges already-deleted domain rows before insert, preventing stale soft-deleted domains from blocking reuse
- Admin domain list supports bulk deletion while preserving panel records when server cleanup fails

**Databases**
- Admin per-account database page supports bulk deletion while preserving panel records when remote cleanup fails
- Database Tools now checks phpMyAdmin/phpPgAdmin availability before showing launch actions, preventing unavailable tool links from falling through to Laravel/nginx 404s
- Installer now attempts to install phpMyAdmin/phpPgAdmin and configures panel web aliases for `/phpmyadmin/` and `/phppgadmin/`
- Installer resets the default PHP CLI alternative back to the selected panel PHP version after optional phpMyAdmin/phpPgAdmin packages are installed

**Backup Schedules**
- Fixed scheduled backup invocation so Laravel passes `--scheduled` as a boolean flag instead of `--scheduled=1`
- Per-account backup schedule configuration: frequency (disabled / daily / weekly), time (HH:MM), day of week (weekly only)
- New migration: `backup_schedule`, `backup_time`, `backup_day` columns on `accounts` table
- `BackupRun` command updated with `--scheduled` flag: filters accounts whose schedule matches the current hour window; respects daily vs weekly
- Scheduler changed from `->dailyAt('02:00')` to `->hourly()` with `--scheduled`; admin UI at Admin → Backup Schedules

**Server DNS Zones**
- Manage all DNS zones from the server DNS page, including hosted account zones and standalone zones
- Full record CRUD (A, AAAA, CNAME, MX, TXT, SRV, CAA, NS) via same PowerDNS agent API
- New migration: `domain_id` and `account_id` made nullable on `dns_zones`; `zone_name` unique index added
- Admin UI at Admin → Server DNS; routes `admin.dns.server.*`

---

## [v0.9] — 2026-04-05

### Added — MVP Gap Pass 2

**Email**
- Autoresponders: Dovecot Sieve vacation scripts, per-mailbox, subject + body + active toggle
- Email Accounts workspace: add/remove mailboxes, change mailbox passwords, and show secure IMAP/POP3/SMTP configuration ports
- Email Accounts workspace can enable mail for domains in the current admin/reseller/user scope while preserving account/package mailbox limits
- Create Mailbox form now labels the mailbox local part as "name" for clearer user-facing wording
- Dovecot authentication now uses the agent-managed `/etc/dovecot/virtual_users` passwd-file backend instead of a removed panel password column, fixing newly created mailbox logins
- Spam filter stats UI: Rspamd stats page (scanned / spam / ham / actions), per-node
- Mailbox archive policy: copy incoming messages to the Archive folder through Sieve
- Domain Key Manager: user workflow to view/copy/regenerate DKIM domain keys and publish managed DNS records
- SPF Manager: user workflow to edit, validate, copy, and restore recommended SPF records while preserving other root TXT values

**DNS**
- Zone import: paste BIND-format zone file, SOA/NS skipped, all other record types supported

**Databases**
- DB user grants: grant additional MariaDB users to existing databases (with revoke)
- PostgreSQL database/user lifecycle support through the same Databases workspace
- Database tool launch guide for phpMyAdmin and phpPgAdmin with manual credential login

**Security**
- SSH key management: add/remove SSH authorized keys per hosting account (user + admin)
- ClamAV malware scanner for jailed account paths with optional quarantine
- Per-domain ModSecurity controls with blocking or detection-only mode
- Per-domain leech protection controls

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
