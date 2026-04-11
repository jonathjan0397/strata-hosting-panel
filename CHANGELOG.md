# Changelog

## [1.0.0-BETA-3.23] - 2026-04-11

Public beta target: `1.0.0-BETA-3.23`.

### Changed - Database tool access and default website presentation

**Database Tools**
- Hosting databases now store their generated or assigned passwords encrypted in the panel database so users can access database tools for all databases on their account
- New database creation, password rotation, and app-installer-created databases now keep the saved database password in sync with the actual database user credential
- The user Database Tools page now shows per-database credentials, reveal/copy actions, and direct phpMyAdmin/phpPgAdmin launch links targeted at the selected database
- Existing app-installer-created databases are backfilled into the hosting database inventory password field through a migration when possible

**Default Website Template**
- Rebuilt the generated default `index.html` experience with a more modern, higher-contrast layout and tighter text containment
- The placeholder page now clearly shows the active Strata panel version and the underlying web server for the provisioned site
- Added partner acknowledgements for Hosted Technology Services and Simple Server Networks alongside GitHub and Buy Me a Coffee links

## [1.0.0-BETA-3.22] - 2026-04-11

Public beta target: `1.0.0-BETA-3.22`.

### Fixed - App installer credentials, WordPress setup, and repo hygiene

**App Installer**
- Automated app installs can now accept and persist application admin credentials when the installer supports them
- Installed-app views now surface generated database credentials and supported admin credentials so assisted setup has the information users actually need
- Successful automated installs now record the provisioned MySQL database in the panel database inventory and remove it from inventory on uninstall

**WordPress**
- The agent WordPress installer now honors provided admin usernames and passwords instead of forcing a hardcoded `admin` user
- WordPress downloads now include bundled default themes, and the installer will activate a fallback theme if no active theme is present after setup

**Mail / Domains / Repo**
- Forwarder deletion now falls back to the domain node when a forwarder row is missing a direct node relationship
- Domain PHP version validation remains aligned with the broader panel support for PHP `8.4`
- Repo ignore rules now hide generated Go caches, temp staging bundles, and incident-only local tooling so release work can return to a clean worktree

## [1.0.0-BETA-3.21] - 2026-04-11

Public beta target: `1.0.0-BETA-3.21`.

### Fixed - SSL gating, mail cleanup, and FTP home path control

**Force HTTPS / SSL**
- Fixed Force HTTPS gating so it no longer depends on the panel web runtime being able to read root-only certificate directories under `/etc/ssl/strata`
- Domains with installed certificates can now enable Force HTTPS without false negatives caused by panel-side filesystem permission checks

**Mail**
- Recreating a deleted mailbox now clears any soft-deleted duplicate row before insert, preventing duplicate-key failures on reused email addresses
- Mail-domain deprovisioning now removes stale Dovecot virtual users for deleted domains so orphan mailbox logins do not survive a domain cleanup
- Installer and upgrade paths now set OpenDKIM canonicalization to `relaxed/relaxed` to avoid brittle DKIM failures caused by `simple/simple`

**FTP**
- Admins and end users can now choose a specific starting directory for new FTP accounts
- FTP home directories remain jailed to the account home, with validation blocking paths outside `/var/www/<account>`

## [1.0.0-BETA-3.20] - 2026-04-11

Public beta target: `1.0.0-BETA-3.20`.

### Fixed - Upgrade activity tracking and node agent version state

**Upgrade Activity Viewer**
- Terminal upgrade and rollback log lines now override stale intermediate stage matches instead of leaving the progress view pinned at an older percentage like `42%`
- Stage detection now scans from the newest matching log lines backward so the visible stage reflects the latest real step, not the first one seen earlier in the log
- Remote agent queue completion now recognizes the actual command output and resolves to `Completed`

**Node Agent Upgrade State**
- Remote agent upgrades now track a pending target version separately from the currently reported agent version
- Nodes remain in `upgrading` until the health poll confirms the agent is actually reporting the target release
- Once the reported version matches the pending target, the upgrade state clears automatically and the node returns to `online`
- Node list and detail pages now show the current and targeted versions more clearly while an upgrade is in progress

## [1.0.0-BETA-3.19] - 2026-04-11

Public beta target: `1.0.0-BETA-3.19`.

### Fixed - Domain web-server display accuracy and default page readability

**Domain Web Server Display**
- New user-created domains now inherit the actual node web server instead of being stored as `nginx` by default
- Admin-created domains now also default to the account node's web server when no override is chosen
- Domain detail pages now prefer the effective node web server when showing Apache/Nginx-specific help and configuration labels

**Default Website Page**
- Tightened the default website placeholder typography and spacing so the page is easier to read
- Long values such as document roots now wrap cleanly instead of running off the page
- Step cards now use a darker high-contrast treatment instead of a washed-out light background

## [1.0.0-BETA-3.18] - 2026-04-11

Public beta target: `1.0.0-BETA-3.18`.

### Fixed - Apache domain delete and SSL issuance workflows

**Apache Domain Deletion**
- Fixed panel-side domain deprovisioning so Apache-backed domains pass the web server type to the agent during vhost removal
- This stops Apache installs from falling into the Nginx removal path and failing with `nginx config test failed` during domain deletion or rollback cleanup

**Apache SSL Issuance**
- Standard domain SSL issuance now uses the domain document root as the ACME webroot instead of forcing `acme.sh --nginx`
- This fixes Apache installs where certificate issuance failed because `nginx` was not present on the server

**Apache SSL Vhost Rendering**
- Fixed the Apache SSL vhost template so generated HTTPS sites do not include an extra closing `</VirtualHost>` tag
- This resolves Apache config test failures during HTTPS reprovisioning after certificate issuance

## [1.0.0-BETA-3.17] - 2026-04-11

Public beta target: `1.0.0-BETA-3.17`.

### Changed - Default website experience and account access polish

**Default Website**
- Replaced the generic placeholder page for new accounts with a more eye-catching Strata landing page
- The default page now shows the active Strata release version and links to:
  - GitHub
  - Buy Me a Coffee
  - Simple Server Networks
  - Hosted Technology Service

**Agent Build Metadata**
- Moved agent version metadata into a dedicated build-info package so the default website and API can show the injected release version without creating package import cycles
- Installer and upgrade build flags now inject the agent version into the new shared build-info path

**Admin Account Access**
- Fixed the admin `Access Panel` path so an admin opening their own hosting account goes directly to the account portal instead of tripping the impersonation guard with a 403

## [1.0.0-BETA-3.16] - 2026-04-11

Public beta target: `1.0.0-BETA-3.16`.

### Fixed - Mail delivery, SSL SAN coverage, and DNS management workflows

**Mail**
- Fixed Postfix virtual mailbox domain provisioning so hosted mail domains are written as valid hash-map entries instead of bare domain lines
- Fixed Dovecot LMTP recipient lookup on fresh installs so inbound delivery keeps the full email address for virtual mailbox resolution
- This resolves inbound mail failures where valid hosted mailboxes were rejected with `Relay access denied` or `User doesn't exist`

**SSL / Domains**
- Standard domain SSL issuance now requests certificates for both the apex domain and `www.<domain>`
- This keeps `www` on the same valid certificate path instead of falling into the default TLS vhost with a hostname mismatch

**DNS**
- Admin, reseller, and user DNS management now supports editing existing records instead of add/delete only
- Managed DNS records can now be restored to their computed domain defaults from the UI

**Mail UI**
- Mailbox creation now clearly rejects full email addresses entered into the local-part field
- Email actions now surface failed redirects and validation responses consistently instead of showing a progress bar and clearing the form when nothing completed

## [1.0.0-BETA-3.15] - 2026-04-11

Public beta target: `1.0.0-BETA-3.15`.

### Fixed - Apache domain SSL issuance reload path

**SSL / Apache**
- Domain SSL issuance now passes the node web server to the agent instead of assuming Nginx
- The agent ACME install step now reloads Apache for Apache-backed domains instead of hardcoding `systemctl reload nginx`
- This fixes Apache installs where certificate issuance succeeded but failed during `acme.sh --install-cert` because `nginx.service` was not present

## [1.0.0-BETA-3.14] - 2026-04-11

Public beta target: `1.0.0-BETA-3.14`.

### Fixed - Installer version banner drift

**Installer**
- The primary installer no longer uses a stale hardcoded version string in its startup banner
- The remote node installer no longer uses a stale hardcoded version string in its startup banner
- Both installers now resolve the displayed version from the repo `VERSION` file or an explicit installer version override instead of drifting behind release tags

## [1.0.0-BETA-3.13] - 2026-04-10

Public beta target: `1.0.0-BETA-3.13`.

### Changed - Security readiness and repo hygiene

**Security / Validation**
- Added staged production-readiness guidance, validation runbooks, evidence templates, and concrete command references for the staging security pass
- Hardened browser shell policy so production requires an explicit override even when the feature is enabled
- Clarified the current licensing model so `install_secret` is treated as a legacy-named installation identifier, not a security credential

**Operations / Repository Hygiene**
- Curated reusable operational scripts under `tools/ops`
- Tightened repo ignore rules so generated live logs and local deployment bundles stop polluting the worktree

## [1.0.0-BETA-3.12] - 2026-04-10

Public beta target: `1.0.0-BETA-3.12`.

### Fixed - Node storage visibility

**Nodes / Storage**
- The admin node status view now prioritizes hosting and backup mounts instead of hiding the large data volume behind the first two detected filesystems
- Node status now surfaces `/var/www`, `/var/backups/strata`, and `/srv` clearly so live storage capacity matches the actual post-migration layout
- A full storage table now shows mount role, used/free/total space, and utilization for all reported disks

## [1.0.0-BETA-3.11] - 2026-04-10

Public beta target: `1.0.0-BETA-3.11`.

### Changed - Installer version display

**Installer**
- The primary installer now shows the actual Strata release version in its startup banner instead of the old generic `v1.0-Beta` label
- The remote node installer now also announces the exact Strata release version at startup

## [1.0.0-BETA-3.10] - 2026-04-10

Public beta target: `1.0.0-BETA-3.10`.

### Fixed - Database login reliability

**Databases / phpMyAdmin**
- MariaDB database-user provisioning now forces the requested password onto existing users instead of silently preserving stale credentials from earlier partial setup attempts
- MariaDB password changes now update both `localhost` and `127.0.0.1` user entries so local database tools and applications stay in sync
- Fresh installs and upgrades now write a Strata phpMyAdmin override that disables the broken control-user path and forces normal cookie authentication

## [1.0.0-BETA-3.09] - 2026-04-10

Public beta target: `1.0.0-BETA-3.09`.

### Added - Installer storage selection

**Installer / Storage**
- Fresh primary installs now detect mounted filesystems, recommend the largest suitable volume, and prompt for hosting-data and backup-data roots
- Fresh remote node installs now support the same storage-root selection, including non-interactive overrides through `STRATA_HOSTING_STORAGE_ROOT` and `STRATA_BACKUP_STORAGE_ROOT`
- Selected storage roots are bind-mounted onto `/var/www` and `/var/backups/strata` so the product can use larger data volumes without breaking the existing runtime path assumptions

### Changed - Documentation

**Docs**
- Installation, upgrading, and handoff docs now describe the new storage-selection flow and the bind-mount compatibility model

## [1.0.0-BETA-3.08] - 2026-04-10

Public beta target: `1.0.0-BETA-3.08`.

### Changed - Mail client guidance

**Mail**
- The Mail Client Configuration Guide now recommends the hosting server's shared mail hostname instead of implying per-domain mail hostnames should be used by default
- This keeps the documented client settings aligned with the currently certificate-valid mail transport hostname

## [1.0.0-BETA-3.07] - 2026-04-10

Public beta target: `1.0.0-BETA-3.07`.

### Fixed - Outlook submission authentication

**Mail**
- Upgrades now normalize Dovecot auth mechanisms to `plain login`, preventing older installs from rejecting Outlook submission with `Invalid authentication mechanism: 'LOGIN'`
- Live primary verification confirmed the active server config now exposes `auth_mechanisms = plain login` after the repair

### Fixed - Mail signing and webmail runtime

**Mail / Webmail**
- Fresh installs and upgrades now write the active SnappyMail runtime override to `include.php`, preventing webmail from falling back to the upstream `localhost:143` defaults
- OpenDKIM now runs with an effective `postfix` group for the milter socket instead of relying on insecure supplementary-group hacks or brittle socket-permission watchers
- Upgrades now repair existing OpenDKIM installs by normalizing the socket directory, removing the stale systemd socket-permission helper units, and rewriting `UserID` to `opendkim:postfix`
- Live primary verification confirmed DKIM signing with the active `ryder-kingsley.com` key after the socket ownership fix

## [1.0.0-BETA-3.05] - 2026-04-10

Public beta target: `1.0.0-BETA-3.05`.

### Changed - Upgrade housekeeping

**Upgrade Utility**
- Rollback backup retention now defaults to the newest 5 backups
- Old rollback backups are pruned only after a successful upgrade or successful rollback

## [1.0.0-BETA-3.04] - 2026-04-10

Public beta target: `1.0.0-BETA-3.04`.

### Added — Agent hardening

**Agent**
- Firewall APIs now require `ufw` to already be installed on the node instead of auto-installing and enabling it during status reads
- Fail2ban APIs now require `fail2ban-client` to already be installed on the node instead of auto-installing and enabling fail2ban during status reads
- New fail-safe upgrade utility supports GitHub tag/branch archives and local drop-in archives with automatic rollback on critical failure
- Fresh installs and upgrades now install the upgrade utility to `/root/strata-upgrade.sh` and `/usr/sbin/strata-upgrade` so future upgrades include utility fixes
- GitHub tag/branch upgrades now cascade matching agent upgrade requests to online remote nodes, and agents are built with explicit version labels
- Fail2Ban administration now has a dedicated admin menu/page under Firewall with jail ban/remove controls and service start/stop/restart actions
- Fail2Ban jail discovery now handles Debian `fail2ban-client status` tree-formatted output so active jails render in the admin page
- Installer Fail2Ban defaults now include SSH, Nginx auth, Postfix, Postfix SASL, and Dovecot jails with 10 failed attempts before a ban
- Installer mail setup now enables authenticated submission services on ports 587 and 465 and keeps unauthenticated relay restricted
- SnappyMail managed domain profiles now use full mailbox logins over IMAPS 993 and authenticated SMTP submission instead of the upstream `localhost:143` default
- The webmail repair command now rewrites stale local SnappyMail domain profiles that still point at `localhost:143`
- Mailbox storage permissions now keep `/var/mail/vhosts` traversable by the `vmail` user, preventing SnappyMail/Dovecot folder listing `SERVERBUG` errors after successful authentication
- Mailbox Sieve scripts now write into the same `/var/mail/vhosts` tree used by Dovecot mailbox storage
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
- Email deliverability checks now resolve the hosted domain or primary node public mail IP before PTR/rDNS, SPF, and blacklist checks, avoiding false checks against loopback/private node addresses
- Remote node installation is now standardized: `installer/agent.sh` bootstraps the hosting service stack, agent, Web Disk, DNS, mail, database, malware, firewall, and Fail2Ban services instead of installing only the agent binary
- Node detail pages now show agent TLS certificate health, expiry, issuer, fingerprint, and an admin-only Renew/Repair action that can recover node certificates without SSH or CLI access
- Fresh mail installs now use agent-managed Postfix hash maps and the same OpenDKIM table paths the agent writes, which keeps primary and remote node mail provisioning consistent
- Malware scans now run through the queue with persisted scan history and polling status instead of holding the browser request open
- Malware Scanner now supports account-level daily or weekly scheduled scans with optional quarantine
- Malware Scanner now lists quarantined files and lets users permanently delete quarantined items from their account jail
- Web Disk now uses a dedicated WebDAV-over-HTTPS service on port 2078 with per-account credentials instead of only FTPS connection guidance
- Admin Mail Queue diagnostics can inspect Postfix queues, flush deferred delivery, and delete stuck queued messages from online nodes
- Admin Mail Queue diagnostics can search recent Postfix/Dovecot delivery logs by mailbox, domain, queue ID, or error text
- Metrics now exports historical traffic reports as CSV by date and domain

**Accounts**
- Bulk package reassignment from the admin account list
- Admins and resellers can access an active sub-client hosting panel through audited support impersonation, with a persistent return banner
- Fixed reseller client detail loading by using the existing account database relationship
- Admin/reseller account creation now queues server provisioning so PHP-FPM reloads cannot reset the browser request and cause a false 502 after the account row is created

**API**
- Account migration workflow endpoints for list/detail/prepare/transfer/restore/cutover/source cleanup

**Migrations**
- Migration prepare, transfer, restore, cutover, and source cleanup actions now run through the queue worker with migration-row progress tracking
- Migration cutover now reassigns and re-provisions credentialless email forwarders on the target node instead of blocking solely because forwarders exist
- Migration rows now show a remediation checklist for blocked services that require fresh credentials or manual verification
- Migration cutover can now preserve mailbox, FTP, Web Disk, database, and database grant metadata as reset-required records on the target node, requiring fresh credentials before source cleanup instead of transferring plaintext passwords
- App installs now cut over as verification-required metadata and block source cleanup until the account owner marks the restored app verified

**Backups**
- Admin backup list supports bulk deletion while preserving panel records when node cleanup fails
- Admin competitor-backup import queue converts supported cPanel/CWP `.tar.gz` and `.tgz` archives into normal Strata full-backup jobs for restore
- Competitor backup imports now detect and preview domains, DNS zone files, mailbox names, and forwarders when present in cPanel/CWP archives
- Completed competitor backup imports can queue a restore directly from the import queue
- Manual backup creation, full restore, and path restore now run through the queue worker with restore status/error tracking instead of holding browser requests open

**Domains**
- New hosted domains now automatically provision a managed PowerDNS zone with full default web/mail records during vhost creation
- Default DNS record seeding now refuses loopback/private node addresses such as `127.0.0.1` and falls back to the node hostname's public address when available
- Agent PowerDNS writes now quote TXT content automatically so SPF, DKIM, and DMARC records are accepted by PowerDNS while remaining readable in the panel
- Managed DNS zones now include nameserver records derived from the primary panel hostname, for example `panel.example.com` -> `ns1.example.com`
- Fresh installs and upgrades now repair PowerDNS SOA defaults using supported `default-soa-content`, preventing authoritative zones from inheriting `a.misconfigured.dns.server.invalid.`
- DNS record writes and deletes are mirrored to other online nodes so successive nodes can act as backup DNS servers for managed zones
- Scheduled `dns:sync-backup-zones` backfills managed zones to online backup DNS nodes after a node comes online
- Admin DNS Zones now shows nameserver cluster health and can trigger backup DNS synchronization on demand
- Individual hosted or standalone DNS zones can trigger a targeted backup-DNS repair sync from the zone detail page
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
- Managed-DNS wildcard Let's Encrypt issuance and renewal for hosted domains

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


