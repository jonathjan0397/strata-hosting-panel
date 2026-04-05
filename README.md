# Strata Panel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/badge/Release-v1.0--Beta-indigo?style=flat-square)](https://github.com/jonathjan0397/strata-panel/releases/tag/v1.0-beta)
[![Issues](https://img.shields.io/github/issues/jonathjan0397/strata-panel?style=flat-square)](https://github.com/jonathjan0397/strata-panel/issues)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-☕-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

Open-source hosting control panel — Nginx/Apache, PHP multi-version, email, DNS, FTP, SSL, backups, and more.

**License:** MIT &nbsp;·&nbsp; **Target OS:** Debian 11 / 12 / 13 &nbsp;·&nbsp; **Status:** v1.0-Beta — MVP complete

---

> **⚠ Pre-release — Beta Software**
>
> v1.0-Beta is functional and feature-complete but has not yet had a full security audit.
> **Do not use in production without reviewing the code and hardening the server yourself.**
>
> Found a bug or something broken? **[Open an issue](https://github.com/jonathjan0397/strata-panel/issues)** — all feedback is welcome and helps make the v1.0 release solid.

---

## Installation

**Requirements:** Fresh Debian 11, 12, or 13 server — run as root.

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh)
```

The installer will ask you for:

- **Server hostname** (FQDN, e.g. `server1.example.com`) — sets `hostnamectl` and configures mail
- **Panel domain** (e.g. `panel.example.com`) — the URL you'll access the panel on
- **Web server** — Nginx (recommended) or Apache
- **Admin name, email, and password** — your master admin account
- **Service passwords** — auto-generate (recommended) or set your own for MariaDB, PowerDNS, Redis, and Webmail

The installer sets everything up end-to-end: all services, SSL via Let's Encrypt, firewall rules, and your admin account. Credentials are saved to `/root/strata-credentials.txt` when done.

**DNS:** Point an A record for your panel domain at the server IP before running the installer if you want Let's Encrypt SSL issued automatically. If DNS isn't ready yet, a self-signed cert is used and the installer tells you the exact command to re-issue once DNS propagates.

### Adding a child node

After the panel is running, go to **Admin → Nodes → Add Node** to get the HMAC secret and Node ID, then on the child server:

```bash
STRATA_HMAC_SECRET=<secret> STRATA_NODE_ID=<id> bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/agent.sh)
```

---

## Live Demo

**[http://panel.stratadevplatform.com](http://panel.stratadevplatform.com)**

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

## Contributing & Feedback

This is a beta — issues are expected.

- **Bugs / broken features** → [Open an issue](https://github.com/jonathjan0397/strata-panel/issues)
- **Feature requests** → [Open an issue](https://github.com/jonathjan0397/strata-panel/issues) with the `enhancement` label
- **Pull requests** → Welcome! Please open an issue first for anything large so we can discuss the approach.
