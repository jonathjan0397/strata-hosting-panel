# Strata Panel — Project Plan
Created: 2026-03-31

## Overview
Strata Panel is a true open-source hosting control panel built for modern infrastructure. Admin → Reseller → End User hierarchy. API-first design with first-class billing integration (Strata Billing, WHMCS, and others).

A single install gives you a fully functional hosting server — the panel and all services run together on one machine. Remote nodes can be added at any time to scale horizontally. Upgrades are self-managed: the primary node pulls from the GitHub repository and cascades the upgrade to all connected child nodes automatically.

**License:** MIT
**GitHub:** jonathjan0397/strata-panel
**Target OS:** Debian 11/12

---

## Tech Stack

### Main Panel
| Layer | Technology |
|---|---|
| Backend | Laravel 12 |
| Frontend | Vue 3 + Inertia.js |
| Queue | Redis + Laravel Horizon |
| Cache | Redis |
| Database | MariaDB |
| Auth | Laravel Sanctum (API tokens) + session |

### Agent (Remote Node Daemon)
| Layer | Technology |
|---|---|
| Language | Go |
| Distribution | Single binary via apt package |
| Communication | HTTPS + HMAC-SHA256 |
| Runs as | systemd service |

### Managed Server Stack
| Component | Technology |
|---|---|
| Web Server | Nginx (default) / Apache (optional) |
| PHP | PHP-FPM multi-version (8.1, 8.2, 8.3) via Ondrej PPA |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM (2048-bit) |
| DNS | PowerDNS + PowerDNS Admin API |
| SSL | acme.sh (Let's Encrypt + ZeroSSL) |
| FTP | Pure-FTPd (jailed per account) |
| Webmail | Roundcube |
| Database | MariaDB (per-user databases + users) |
| Firewall | UFW + fail2ban |

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
│  │        Strata Panel              │   │
│  │  Laravel + Vue 3 + Inertia       │   │
│  │  REST API (/api/v1/*)            │   │
│  └─────────────┬────────────────────┘   │
│                │ localhost              │
│  ┌─────────────▼────────────────────┐   │
│  │       strata-agent (Go)          │   │
│  │  Nginx · PHP-FPM · Postfix       │   │
│  │  Dovecot · Rspamd · PowerDNS     │   │
│  │  MariaDB · acme.sh · Pure-FTPd   │   │
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
- [ ] Add/remove servers (nodes)
- [ ] Install strata-agent via one-line command
- [ ] Node health dashboard (CPU, RAM, disk, load)
- [ ] Service status (Nginx, PHP-FPM, Postfix, MySQL)
- [ ] Restart/reload services
- [ ] OS update management (safe updates, exclude critical packages)
- [ ] Firewall management (UFW rules)

### Account Management
- [ ] Create/suspend/terminate accounts
- [ ] Resource limits (disk, bandwidth, email accounts, databases, subdomains)
- [ ] Assign accounts to nodes
- [ ] Reseller management with quota allocation

### Domain Management
- [ ] Add/remove domains and subdomains
- [ ] Document root configuration
- [ ] Nginx/Apache vhost generation
- [ ] SSL via acme.sh (auto-renew)
- [ ] Redirect management (301/302)
- [ ] Custom Nginx/Apache directives

### Email
- [ ] Create/delete/edit mailboxes
- [ ] Email forwarders
- [ ] Autoresponders
- [ ] DKIM (2048-bit, auto-generated on domain add)
- [ ] SPF auto-generated on domain add
- [ ] DMARC auto-generated on domain add
- [ ] Spam filter settings (Rspamd)
- [ ] Roundcube webmail SSO

### DNS
- [ ] PowerDNS zone management
- [ ] Full record type support (A, AAAA, CNAME, MX, TXT, SRV, CAA)
- [ ] Auto-populate standard records on domain add
- [ ] Import/export zone files

### Databases
- [ ] Create/delete MariaDB databases
- [ ] Create/delete database users
- [ ] Assign user permissions
- [ ] phpMyAdmin SSO

### FTP
- [ ] Create/delete FTP accounts
- [ ] Jailed to account directory
- [ ] FTPS (TLS) enforced

### PHP
- [ ] Per-account PHP version selection (8.1 / 8.2 / 8.3)
- [ ] PHP-FPM per account (process isolation)
- [ ] php.ini overrides per account (upload_max, memory_limit, etc.)

### File Manager
- [ ] Browser-based file manager
- [ ] Upload/download/rename/delete
- [ ] Archive (zip/tar) and extract
- [ ] Permissions management
- [ ] Code editor (basic)

### SSL
- [ ] Let's Encrypt via acme.sh
- [ ] Auto-renew
- [ ] Wildcard cert support
- [ ] Custom cert upload

### Backups
- [ ] Per-account backup (files + databases)
- [ ] Scheduled automated backups
- [ ] Remote backup destination (S3, FTP, SFTP)
- [ ] One-click restore

### Billing Integration (API)
- [ ] REST webhook endpoints for account provisioning
- [ ] Strata Billing plugin (first-party)
- [ ] WHMCS module
- [ ] Suspend/unsuspend via API
- [ ] Usage reporting via API (disk, bandwidth)

### Security
- [ ] fail2ban integration
- [ ] SSH key management
- [ ] 2FA (TOTP) for all user levels
- [ ] Audit log (every action logged with user + timestamp)
- [ ] ModSecurity (optional WAF)

---

## Development Phases

### Phase 1 — Foundation (Panel + Agent skeleton)
- Laravel project scaffold
- Authentication (Admin/Reseller/User roles)
- Node/agent system (Go agent, HMAC auth, health reporting)
- Basic UI shell (AppLayout, nav, dark mode)
- Database schema

### Phase 2 — Core Server Functions
- Account management
- Domain + vhost management (Nginx)
- SSL (acme.sh integration)
- PHP-FPM multi-version

### Phase 3 — Email Stack
- Postfix + Dovecot provisioning
- DKIM/SPF/DMARC auto-setup
- Rspamd
- Roundcube SSO

### Phase 4 — DNS + Databases + FTP
- PowerDNS integration
- MariaDB account management
- Pure-FTPd
- phpMyAdmin SSO

### Phase 5 — End User Features
- File manager
- Backup system
- End user portal polish

### Phase 6 — Billing API + Integrations
- REST provisioning API
- Strata Billing plugin
- WHMCS module

### Phase 7 — Hardening + Release
- Security audit
- Installer (one-line bash)
- Documentation
- v1.0-Beta release

---

## Repository Structure

```
strata-panel/
├── panel/              # Laravel main panel application
│   ├── app/
│   ├── resources/js/   # Vue 3 + Inertia frontend
│   └── ...
├── agent/              # Go agent (strata-agent binary)
│   ├── cmd/
│   ├── internal/
│   │   ├── api/        # HTTP handlers
│   │   ├── nginx/      # Nginx management
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
4. **Sane secure defaults** — 2048-bit DKIM, DMARC on domain add, TLS enforced, fail2ban
5. **Billing agnostic** — standardized provisioning API; works with Strata, WHMCS, anything
6. **Auditable** — every change logged with actor + timestamp
7. **Modern stack** — not built on 15-year-old PHP patterns
8. **Plugin ready** — Laravel packages + Vue component injection

---

## Notes
- strata-agent must survive panel going offline (local config state)
- Agent communication is one-way: panel → agent (no callbacks)
- All provisioned config files generated from templates (Blade/Go templates)
- DKIM wrapper approach used on mercury.hosted-tech.com proves the pattern for agent
