# Strata Panel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/badge/Release-v1.0--Beta-indigo?style=flat-square)](https://github.com/jonathjan0397/strata-panel/releases)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-☕-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

Open-source hosting control panel — Nginx/Apache, PHP multi-version, email, DNS, FTP, SSL, backups, and more.

**License:** MIT &nbsp;·&nbsp; **Target OS:** Debian 11 / 12 / 13 &nbsp;·&nbsp; **Status:** v1.0-Beta — MVP complete

---

## Live Demo

**[http://panel.stratadevplatform.com](http://panel.stratadevplatform.com)**

Click any account card on the login page to autofill credentials, or use them directly:

| Role | Email | Password |
|------|-------|----------|
| Admin | `demo-admin@example.com` | `DemoAdmin2026` |
| End User | `demo-user@example.com` | `DemoUser2026!` |

> The demo server resets periodically. Changes you make are not permanent.

---

## Stack

| Layer | Technology |
|---|---|
| Panel | Laravel 13 + Vue 3 + Inertia.js |
| Agent | Go binary (strata-agent) |
| Web | Nginx / Apache (per node) |
| PHP | PHP-FPM 8.1 / 8.2 / 8.3 |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM (2048-bit) |
| DNS | PowerDNS |
| SSL | acme.sh (Let's Encrypt / ZeroSSL) |
| FTP | Pure-FTPd |
| Database | MariaDB |
| Firewall | UFW + fail2ban |

## Features (v1.0-Beta)

| Category | Features |
|---|---|
| **Accounts** | Create/suspend/terminate, resource limits, system user provisioning |
| **Reseller Portal** | Dashboard with quota meters, create/manage client accounts, white-label branding |
| **Domains** | Nginx/Apache vhosts, SSL (Let's Encrypt + custom cert, auto-renew), PHP version per domain, redirects, custom directives |
| **Email** | Mailboxes, forwarders, autoresponders, DKIM/SPF/DMARC auto-setup, spam filter stats |
| **DNS** | PowerDNS zone management, full record type support, BIND import/export, server DNS zones |
| **Databases** | MariaDB create/delete/password, user grants |
| **FTP** | Pure-FTPd jailed accounts (FTPS enforced) |
| **File Manager** | Browser-based, upload/download/edit/chmod/compress/extract |
| **Backups** | Files + databases, per-account schedules, manual trigger, download, remote SFTP/S3 destinations |
| **Security** | 2FA (TOTP), audit log, fail2ban UI (view jails, unban IPs), SSH key management, UFW firewall rules |
| **Admin Tools** | Browser SSH terminal, email deliverability troubleshooter, OS update management, backup schedules |
| **Multi-node** | Remote nodes via Go agent (HMAC auth), health monitoring, per-node service management |
| **Billing API** | REST provisioning API (create/suspend/terminate/usage), Bearer token auth |

## Architecture

A single install gives you a fully functional hosting server. Remote nodes can be added at any time to scale horizontally. See [docs/PLAN.md](docs/PLAN.md) for the full project plan, feature list, and development phases.

## Installation

One-line installer (Debian 11/12/13):

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh)
```

### Manual setup (dev)

```bash
cd panel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Default admin login after seeding: `admin@localhost` / `ChangeMe123!`

## Contributing

Bug reports and pull requests are welcome. Please [open an issue](https://github.com/jonathjan0397/strata-panel/issues) before submitting large changes.
