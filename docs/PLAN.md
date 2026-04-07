# Strata Hosting Panel — Project Plan

[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-☕-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)
Created: 2026-03-31 | Last Updated: 2026-04-05 | v1.0-Beta Released

## Overview
Strata Hosting Panel is a true open-source hosting control panel built for modern infrastructure. Admin → Reseller → End User hierarchy. API-first design with first-class billing integration (Strata Billing, WHMCS, and others).

A single install gives you a fully functional hosting server — the panel and all services run together on one machine. Remote nodes can be added at any time to scale horizontally. Upgrades are self-managed: the primary node pulls from the GitHub repository and cascades the upgrade to all connected child nodes automatically.

**License:** MIT
**GitHub:** jonathjan0397/strata-hosting-panel
**Target OS:** Debian 11/12/13

---

## Tech Stack

### Main Panel
| Layer | Technology |
|---|---|
| Backend | Laravel 13 |
| Frontend | Vue 3 + Inertia.js |
| Queue | Redis + Laravel Horizon |
| Cache | Redis |
| Database | MariaDB + PostgreSQL |
| Auth | Laravel Sanctum (API tokens) + session |

### Agent (Remote Node Daemon)
| Layer | Technology |
|---|---|
| Language | Go 1.23 |
| Distribution | Single binary via apt package |
| Communication | HTTPS + HMAC-SHA256 |
| Runs as | systemd service |

### Managed Server Stack
| Component | Technology |
|---|---|
| Web Server | Nginx (default) / Apache (optional, per-node) |
| PHP | PHP-FPM multi-version (8.1, 8.2, 8.3) via Ondrej PPA |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM (2048-bit) |
| DNS | PowerDNS + PowerDNS Admin API |
| SSL | acme.sh (Let's Encrypt + ZeroSSL) |
| FTP | Pure-FTPd (jailed per account) |
| Webmail | Roundcube |
| Database | MariaDB + PostgreSQL (per-user databases + users) |
| Firewall / Malware | UFW + fail2ban + ClamAV |
| Accelerators | Varnish, Redis, Memcached (per-node, optional) |

---

## Architecture

### Install Modes

**Single Server (default install)**
Everything runs on one machine. The panel manages itself via its own local agent on localhost.

```
┌─────────────────────────────────────────┐
│          Primary Server                 │
│                                         │
│  ┌──────────────────────────────────┐   │
│  │        Strata Hosting Panel              │   │
│  │  Laravel + Vue 3 + Inertia       │   │
│  │  REST API (/api/v1/*)            │   │
│  └─────────────┬────────────────────┘   │
│                │ localhost              │
│  ┌─────────────▼────────────────────┐   │
│  │       strata-agent (Go)          │   │
│  │  Nginx · PHP-FPM · Postfix       │   │
│  │  Dovecot · Rspamd · PowerDNS     │   │
│  │  MariaDB · PostgreSQL · acme.sh  │   │
│  └──────────────────────────────────┘   │
└─────────────────────────────────────────┘
```

**Multi-Server (add child nodes as needed)**
Child nodes run only the agent. The primary panel manages all nodes.

```
┌─────────────────────────────────────────┐
│          Primary Server                 │
│   Panel + Agent + All Services          │
└────────────────┬────────────────────────┘
                 │ HTTPS + HMAC
        ┌────────┴────────┐
        │                 │
┌───────▼──────┐  ┌───────▼──────┐
│  Child Node  │  │  Child Node  │  ...
│ strata-agent │  │ strata-agent │
│ All Services │  │ All Services │
└──────────────┘  └──────────────┘
```

### Node Stack Configuration
Each node stores its own web server and accelerator choices:
- **Web server:** `nginx` or `apache` — determines how vhosts are generated
- **Accelerators:** `varnish`, `redis`, `memcached` — reflects what is installed on the node

### Upgrade Flow

```
GitHub Repository
       │
       │  git pull / release download
       ▼
Primary Node (panel upgrade)
  └── artisan migrate
  └── npm run build
  └── cache:clear
       │
       │  cascade via agent API
       ▼
Child Node 1          Child Node 2  ...
  └── agent downloads   └── agent downloads
      new binary            new binary
  └── restarts self     └── restarts self
  └── reports version   └── reports version
```

- Primary upgrade is triggered by admin via UI or CLI
- Panel pulls latest tag from GitHub, runs migrations, rebuilds assets
- Panel then sends upgrade command to each connected child agent
- Each agent self-updates by downloading the new binary from GitHub releases and restarting via systemd
- Dashboard shows upgrade status per node in real time

---

## User Hierarchy

```
Admin
 └── Reseller (can create end users, set resource limits)
      └── End User (manages own domains, email, databases)
```

- **Admin:** Full control, server management, node management, reseller management
- **Reseller:** Account creation, resource allocation within their quota, white-label ready
- **End User:** Domain management, email accounts, databases, FTP, SSL, DNS, file manager, PHP version selection

---

## MVP Feature Set (v1.0-Beta)

### Server / Node Management (Admin)
- [x] Add/remove servers (nodes)
- [x] Node web server + accelerator configuration per node
- [x] Node health dashboard (CPU, RAM, disk, load)
- [x] Service status (Nginx, PHP-FPM, Postfix, MySQL)
- [x] Restart/reload services
- [x] Log viewer per node (real-time, multi-service)
- [x] Install strata-agent via one-line command
- [x] OS update management (safe updates, exclude critical packages)
- [x] Firewall management (UFW rules)
- [x] Browser-based SSH terminal (admin only, per-node, xterm.js + WebSocket)

### Account Management
- [x] Create/suspend/terminate accounts
- [x] Resource limits (disk, bandwidth, email accounts, databases, subdomains)
- [x] Assign accounts to nodes
- [x] System user + PHP-FPM pool provisioning on agent
- [x] Reseller management with quota allocation

### Domain Management
- [x] Add/remove domains and subdomains
- [x] Document root configuration
- [x] Nginx vhost generation
- [x] Apache vhost generation
- [x] SSL via acme.sh (auto-renew)
- [x] PHP version per domain
- [x] Redirect management (301/302)
- [x] Custom Nginx/Apache directives (UI)

### Email
- [x] Create/delete/edit mailboxes
- [x] Email forwarders
- [x] DKIM (2048-bit, auto-generated on domain add)
- [x] SPF auto-generated on domain add
- [x] DMARC auto-generated on domain add
- [x] Autoresponders (Dovecot Sieve vacation scripts)
- [x] Spam filter stats (Rspamd stats UI, per-node)
- [x] Webmail SSO (alternative to Roundcube — see webmail-skin)

### DNS
- [x] PowerDNS zone management
- [x] Full record type support (A, AAAA, CNAME, MX, TXT, SRV, CAA)
- [x] Auto-populate standard records on domain add
- [x] Import zone files (BIND format paste/upload)
- [x] Export zone files (BIND format download)
- [x] Server DNS zones (standalone zones not tied to hosted accounts)

### Databases
- [x] Create/delete MariaDB and PostgreSQL databases
- [x] Create/delete database users
- [x] Assign user permissions (grant additional users to databases)
- [ ] phpMyAdmin SSO (deferred — post v1.0)

### FTP
- [x] Create/delete FTP accounts
- [x] Jailed to account directory
- [x] FTPS (TLS) enforced (Pure-FTPd TLS configured in installer)

### PHP
- [x] Per-account PHP version selection (8.1 / 8.2 / 8.3)
- [x] PHP-FPM per account (process isolation)
- [x] php.ini overrides per account (upload_max, memory_limit, max_exec_time)

### End User Portal
- [x] User dashboard with resource summary
- [x] Domain management (add, view, SSL, PHP version)
- [x] Email management (mailboxes, forwarders)
- [x] Database management
- [x] FTP management
- [x] DNS zone management

### File Manager
- [x] Browser-based file manager
- [x] Upload/download/rename/delete
- [x] Archive (zip/tar) and extract
- [x] Permissions management
- [x] Code editor (basic)

### SSL
- [x] Let's Encrypt via acme.sh
- [x] Auto-renew
- [ ] Wildcard cert support (requires DNS challenge — post v1.0)
- [x] Custom cert upload

### Backups
- [x] Per-account backup (files + databases)
- [x] Per-account backup schedules (frequency, time, day — admin-configurable)
- [x] Remote backup destination (SFTP + S3, admin-configurable, auto-push after backup)
- [x] One-click restore

### Security
- [x] 2FA (TOTP) for all user levels
- [x] Audit log (every action logged with user + timestamp)
- [x] fail2ban integration (admin UI — view jails, banned IPs, one-click unban)
- [x] SSH key management (add/remove authorized_keys per account)
- [x] ClamAV malware scanner for jailed account paths
- [ ] ModSecurity (optional WAF — post v1.0)

### Admin Tools
- [x] Browser-based SSH terminal (per-node, xterm.js)
- [x] Email deliverability troubleshooter (MX, SPF, DKIM, DMARC, PTR, blacklists)

### Billing Integration (API)
- [x] REST provisioning API (create/suspend/unsuspend/terminate/usage)
- [x] Bearer token auth (Sanctum), admin token management UI
- [ ] Strata Billing plugin (first-party)
- [ ] WHMCS module

### Reseller Portal
- [x] Reseller dashboard with quota meters
- [x] Create/manage end user accounts
- [x] Resource quota management
- [x] White-label support (panel name + accent colour per reseller)

---

## Development Phases

### Phase 1 — Foundation ✅
- Laravel project scaffold
- Authentication (Admin/Reseller/User roles)
- Node/agent system (Go agent, HMAC auth, health reporting)
- Basic UI shell (AppLayout, nav, dark mode)
- Database schema

### Phase 2 — Core Server Functions ✅
- Account management
- Domain + vhost management (Nginx + Apache)
- SSL (acme.sh integration)
- PHP-FPM multi-version
- Per-node web server and accelerator configuration

### Phase 3 — Email Stack ✅
- Postfix + Dovecot provisioning
- DKIM/SPF/DMARC auto-setup
- Rspamd
- Roundcube SSO (pending)

### Phase 4 — DNS + Databases + FTP ✅
- PowerDNS integration
- MariaDB and PostgreSQL account management
- Pure-FTPd
- phpMyAdmin SSO (pending)

### Phase 5 — End User Portal + Agent ✅
- [x] End user portal (domains, email, databases, FTP, DNS)
- [x] strata-agent deployed, systemd, health checks
- [x] Account provisioning end-to-end
- [x] File manager (Go agent + Vue UI, upload/download/edit/chmod/compress/extract)
- [x] Backup system (files + databases, nightly scheduled, manual trigger, download)
- [x] 2FA (TOTP) — admin, reseller, end user
- [x] Audit log viewer (admin, filterable, paginated)
- [x] SSL auto-renew (artisan command + scheduler)
- [x] Browser-based SSH terminal (admin, per-node, xterm.js + PTY)
- [x] Email deliverability troubleshooter (MX, SPF, DKIM, DMARC, PTR, blacklists)

### Phase 6 — Reseller Portal ✅
- [x] Reseller dashboard with quota meters (accounts, disk, bandwidth, domains, email, databases)
- [x] Create client accounts with quota enforcement
- [x] Suspend / unsuspend / delete client accounts
- [x] Client detail page with resource limit editing
- [x] Admin: create/view/update reseller quotas, delete resellers
- [x] White-label branding (panel name + accent colour per reseller, shown to clients)

### Phase 7 — Billing API + Integrations ✅ (partial)
- [x] REST provisioning API (create, suspend, unsuspend, terminate, usage)
- [x] Bearer token auth + admin token management UI
- [ ] Strata Billing plugin
- [ ] WHMCS module

### Phase 8 — Hardening + Release ✅
- [ ] Security audit (post-beta)
- [x] Installer (one-line bash — `installer/install.sh`)
- [x] Documentation (README, CHANGELOG, PLAN updated for v1.0-Beta)
- [x] v1.0-Beta release tag

---

## Repository Structure

```
strata-hosting-panel/
├── panel/              # Laravel main panel application
│   ├── app/
│   ├── resources/js/   # Vue 3 + Inertia frontend
│   └── ...
├── agent/              # Go agent (strata-agent binary)
│   ├── cmd/
│   ├── internal/
│   │   ├── api/        # HTTP handlers
│   │   ├── nginx/      # Nginx vhost management
│   │   ├── apache/     # Apache vhost management
│   │   ├── php/        # PHP-FPM management
│   │   ├── mail/       # Postfix/Dovecot management
│   │   ├── dns/        # PowerDNS management
│   │   └── system/     # OS/service management
│   └── main.go
├── installer/          # Bash installer scripts
│   ├── install.sh      # Main panel installer
│   └── agent.sh        # Agent installer
└── docs/               # Documentation
```

---

## Differentiators

1. **True open source** — MIT, no IonCube, no license keys, forkable
2. **API-first** — every UI action is an API call; full REST surface
3. **Multi-server native** — not bolted on, core architecture
4. **Per-node stack config** — each node declares its web server and accelerators
5. **Sane secure defaults** — 2048-bit DKIM, DMARC on domain add, TLS enforced, fail2ban
6. **Billing agnostic** — standardized provisioning API; works with Strata, WHMCS, anything
7. **Auditable** — every change logged with actor + timestamp
8. **Modern stack** — not built on 15-year-old PHP patterns
9. **Plugin ready** — Laravel packages + Vue component injection

---

## Notes
- strata-agent must survive panel going offline (local config state)
- Agent communication is one-way: panel → agent (no callbacks)
- All provisioned config files generated from templates (Blade/Go templates)
- DKIM wrapper approach used on mercury.hosted-tech.com proves the pattern for agent
- Node web server is declared at node registration time; changing it requires re-provisioning all vhosts on that node
