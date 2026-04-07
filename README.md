# Strata Hosting Panel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/badge/Release-v1.0.0--alpha.2-indigo?style=flat-square)](https://github.com/jonathjan0397/strata-hosting-panel/releases/tag/v1.0.0-alpha.2)
[![Issues](https://img.shields.io/github/issues/jonathjan0397/strata-hosting-panel?style=flat-square)](https://github.com/jonathjan0397/strata-hosting-panel/issues)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-support-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

Open-source hosting control panel for Debian servers: Nginx/Apache, PHP multi-version, email, DNS, FTP, SSL, backups, and more.

**License:** MIT | **Target OS:** Debian 11 / 12 / 13 | **Status:** v1.0.0-alpha.2 public testing

---

> **Pre-release Alpha Software**
>
> v1.0.0-alpha.2 is available for public testing and should not be treated as production-ready.
> **Do not use in production without reviewing the code and hardening the server yourself.**
>
> Public testers: please report bugs, broken workflows, installer issues, and UI problems in **[GitHub Issues](https://github.com/jonathjan0397/strata-hosting-panel/issues)**.

---

## Public Demo / Smoke Test

**[https://stratadevplatform.net](https://stratadevplatform.net)**

| Role | Email | Password |
|------|-------|----------|
| Admin | `demo-admin@stratadevplatform.net` | `DemoAdmin2026!` |
| Reseller | `demo-reseller@stratadevplatform.net` | `DemoReseller2026!` |
| User | `demo-user@stratadevplatform.net` | `DemoUser2026!` |
| Reseller Client | `demo-client@stratadevplatform.net` | `DemoClient2026!` |

The demo server includes dummy domains, DNS records, mailboxes, forwarders, databases, and reseller/client accounts. It may be reset at any time, so do not store real data there.

Please report public testing issues in **[GitHub Issues](https://github.com/jonathjan0397/strata-hosting-panel/issues)**. Demo reset and seed details are documented in [docs/PUBLIC-DEMO.md](docs/PUBLIC-DEMO.md).

---

## Installation

### Before you begin

- A fresh **Debian 11, 12, or 13** VPS or dedicated server with at least 1 GB RAM and 20 GB disk.
- **Root access** via SSH.
- `curl` and CA certificates available on the server. Minimal Debian images may not include them by default:

```bash
apt-get update && apt-get install -y curl ca-certificates
```

- A **domain name** you control, with the ability to add DNS A records.
- **Highly recommended:** install Strata Hosting Panel on a dedicated subdomain such as `panel.example.com`, not the apex/root domain `example.com`. This keeps the main domain available for the admin website or hosted content.
- The panel subdomain's A record should point at your server's IP address before running the installer. Let's Encrypt needs it to issue a real SSL certificate. If DNS is not ready yet, the installer uses a self-signed certificate and tells you the exact command to re-issue once DNS propagates.

### Step 1: Log in to your server as root

Open a terminal and connect via SSH:

```bash
ssh root@YOUR_SERVER_IP
```

If your hosting provider gave you a non-root user with sudo, switch to root first:

```bash
sudo -i
```

### Step 2: Run the installer

Paste this single command and press Enter:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/install.sh)
```

This downloads and runs the installer in one step.

If you prefer to download the script first and inspect it before running:

```bash
curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/install.sh -o install.sh
chmod +x install.sh
./install.sh
```

### Step 3: Answer the prompts

The installer will ask you a series of questions. You can press **Enter** to accept the suggested default shown in brackets.

| Prompt | What to enter |
|--------|---------------|
| Server hostname | The FQDN for this server, for example `server1.example.com` |
| Panel domain | The dedicated subdomain for the control panel, for example `panel.example.com`. The installer suggests this form by default so the apex/root domain, for example `example.com`, remains available for the admin website. |
| Web server | `1` for Nginx or `2` for Apache |
| Admin name | Your full name or display name |
| Admin email | The email you will log in with |
| Admin password | Minimum 12 characters, entered twice |
| Auto-generate service passwords? | Press **Enter** or `Y` to let the installer generate secure random passwords for MariaDB, Redis, and other services. Type `n` to set your own. |

The process usually takes 5-10 minutes depending on server speed and network.

### Step 4: Save your credentials

When the installer finishes, it prints a summary and saves generated passwords to:

```text
/root/strata-credentials.txt
```

Read this file and store the passwords somewhere safe before closing your SSH session:

```bash
cat /root/strata-credentials.txt
```

### Step 5: Open the panel

Navigate to `https://panel.example.com` in a browser and log in with the admin email and password you set during installation.

---

## Adding a Child Node

After the panel is running, go to **Admin -> Nodes -> Add Node** to get the HMAC secret and Node ID, then on the child server run:

```bash
STRATA_HMAC_SECRET=<secret> STRATA_NODE_ID=<id> bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/agent.sh)
```

---

## Stack

| Layer | Technology |
|---|---|
| Panel | Laravel 13 + Vue 3 + Inertia.js |
| Agent | Go binary (`strata-agent`) |
| Web | Nginx / Apache per node |
| PHP | PHP-FPM 8.1 / 8.2 / 8.3 |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM |
| DNS | PowerDNS |
| SSL | acme.sh with Let's Encrypt / ZeroSSL |
| FTP | Pure-FTPd |
| Database | MariaDB + PostgreSQL |
| Firewall / Malware | UFW + fail2ban + ClamAV |

## Features (v1.0.0-alpha.2)

| Category | Features |
|---|---|
| **Accounts** | Create/suspend/terminate, packages, feature lists, resource limits, system user provisioning |
| **Reseller Portal** | Dashboard with quota meters, create/manage client accounts, package selection, default packages, white-label branding |
| **Domains** | Nginx/Apache vhosts, default starter index page, SSL, Force HTTPS, PHP version per domain, redirects, custom directives, hotlink protection, directory privacy |
| **Email** | Mailboxes, forwarders, autoresponders, DKIM/SPF/DMARC setup, Domain Key Manager, SPF Manager, filters, spam policies, archive controls, delivery tracking, bulk import |
| **DNS** | PowerDNS zone management, full record type support, BIND import/export, server DNS zones |
| **Databases** | MariaDB/PostgreSQL create/delete/password, user grants, remote MariaDB host grants, phpMyAdmin/phpPgAdmin launch guide |
| **FTP / Web Disk** | Pure-FTPd jailed accounts with FTPS enforced, desktop-client connection guide |
| **File Manager** | Browser-based upload/download/edit/chmod/compress/extract |
| **Backups** | Files + databases, schedules, manual trigger, download, path restore, remote SFTP/S3 destinations |
| **Metrics** | Resource usage, log viewer/downloads, recent traffic summaries, 30-day stored traffic history |
| **Security** | 2FA, audit log, dedicated Fail2Ban administration, SSH keys, UFW firewall rules, ClamAV malware scans, per-domain ModSecurity and leech protection controls |
| **UI / Accessibility** | Glassmorphism app shell with persisted Smoky Gray, Aurora Teal, Ember Gold, and Violet Bloom theme preferences |
| **Admin Tools** | Browser SSH terminal, email deliverability troubleshooter, OS update management, backup schedules, bulk operations |
| **Multi-node** | Remote nodes via Go agent with HMAC auth, health monitoring, per-node service management, conservative account migration workflow |
| **Billing API** | REST provisioning API, Bearer token auth, package/feature catalog API, migration API, outbound audit webhooks |

## Architecture

A single install gives you a functional hosting server. Remote nodes can be added to scale horizontally.

- Project plan and phases: [docs/PLAN.md](docs/PLAN.md)
- Billing/provisioning API: [docs/API.md](docs/API.md)
- Alpha validation criteria and known limitations: [ALPHA-RELEASE-CHECKLIST.md](ALPHA-RELEASE-CHECKLIST.md)
- Public demo credentials and reset instructions: [docs/PUBLIC-DEMO.md](docs/PUBLIC-DEMO.md)

## Contributing & Feedback

This is an alpha release for public testing. Issues are expected.

- **Bugs / broken features:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues)
- **Installer or demo-server problems:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues) and include the page, action, expected result, and actual result.
- **Feature requests:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues) with the `enhancement` label.
- **Pull requests:** Welcome. Please open an issue first for anything large so we can discuss the approach.
