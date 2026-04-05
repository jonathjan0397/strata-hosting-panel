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

### Before you begin

- A fresh **Debian 11, 12, or 13** VPS or dedicated server (minimum 1 GB RAM, 20 GB disk)
- **Root access** via SSH
- A **domain name** you control, with the ability to add DNS A records (e.g. `panel.example.com`)
- The domain's A record pointing at your server's IP address **before** running the installer speeds things up — Let's Encrypt needs it to issue a real SSL certificate. If DNS isn't ready yet that's fine too; the installer uses a self-signed cert and tells you the exact command to re-issue once DNS propagates.

### Step 1 — Log in to your server as root

Open a terminal (on Windows use [PuTTY](https://www.putty.org/) or Windows Terminal) and connect via SSH:

```bash
ssh root@YOUR_SERVER_IP
```

If your hosting provider gave you a non-root user with sudo, switch to root first:

```bash
sudo -i
```

### Step 2 — Run the installer

Paste this single command and press Enter:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh)
```

This downloads and runs the installer in one step — no need to set permissions manually.

> **If you prefer to download the script first and inspect it before running:**
> ```bash
> curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh -o install.sh
> # Open install.sh in a text editor to review it, then:
> chmod +x install.sh
> ./install.sh
> ```
> `chmod +x` makes the file executable. Without it the shell will refuse to run it.

### Step 3 — Answer the prompts

The installer will ask you a series of questions. You can press **Enter** to accept the suggested default shown in `[brackets]`:

| Prompt | What to enter |
|--------|---------------|
| Server hostname | The FQDN for this server, e.g. `server1.example.com` |
| Panel domain | The domain for the control panel, e.g. `panel.example.com` |
| Web server | `1` for Nginx (recommended) or `2` for Apache |
| Admin name | Your full name or a display name |
| Admin email | The email you'll log in with |
| Admin password | Min. 12 characters — you'll type it twice |
| Auto-generate service passwords? | Press **Enter** (or `Y`) to let the installer generate secure random passwords for MariaDB, Redis, etc. Type `n` to set your own. |

The whole process takes about 5–10 minutes depending on server speed and network.

### Step 4 — Save your credentials

When the installer finishes it prints a summary and saves all generated passwords to:

```
/root/strata-credentials.txt
```

**Read this file and store the passwords somewhere safe** (a password manager is ideal) before closing your SSH session:

```bash
cat /root/strata-credentials.txt
```

### Step 5 — Open the panel

Navigate to `https://panel.example.com` (your panel domain) in a browser and log in with the admin email and password you set during installation.

---

### Adding a child node

After the panel is running, go to **Admin → Nodes → Add Node** to get the HMAC secret and Node ID, then on the child server run:

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
