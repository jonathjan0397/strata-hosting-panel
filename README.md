# Strata Hosting Panel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/badge/Release-1.0.0--BETA--3.25-indigo?style=flat-square)](https://github.com/jonathjan0397/strata-hosting-panel/releases/tag/1.0.0-BETA-3.25)
[![Issues](https://img.shields.io/github/issues/jonathjan0397/strata-hosting-panel?style=flat-square)](https://github.com/jonathjan0397/strata-hosting-panel/issues)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-support-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

Open-source hosting control panel for Debian servers: Nginx/Apache, PHP multi-version, email, DNS, FTP, SSL, backups, and more.

**License:** MIT | **Target OS:** Debian 11 / 12 / 13 | **Status:** 1.0.0-BETA-3.25 public testing

---

> **Pre-release Beta Software**
>
> 1.0.0-BETA-3.25 is available for public testing and should not be treated as production-ready.
> **Do not use in production without reviewing the code and hardening the server yourself.**
>
> Public testers: please report bugs, broken workflows, installer issues, and UI problems in **[GitHub Issues](https://github.com/jonathjan0397/strata-hosting-panel/issues)**.

---

## Public Demo / Smoke Test

There is no public demo environment available right now.

When a shared smoke-test instance is brought back, its URL and access policy will be documented in [docs/PUBLIC-DEMO.md](docs/PUBLIC-DEMO.md).

Please report public testing issues in **[GitHub Issues](https://github.com/jonathjan0397/strata-hosting-panel/issues)**.

---

## Role Guide

For a role-based feature overview and short how-to guidance for admins, resellers, and end users, see [docs/ROLE-GUIDE.md](docs/ROLE-GUIDE.md).

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
- If you plan to delegate DNS to Strata, the installer derives authoritative nameservers from the panel/server base domain, for example `panel.example.com` -> `ns1.example.com` and `ns2.example.com`, and writes a supported PowerDNS `default-soa-content` automatically so new zones are ready for nameserver cutover.

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
| Hosting data root | Choose where hosted account data should live. The installer scans mounted filesystems, recommends the largest suitable volume, and bind-mounts the selected path onto `/var/www` so runtime paths stay consistent. |
| Backup data root | Choose where panel and hosted backup data should live. The installer bind-mounts the selected path onto `/var/backups/strata`. |
| Admin name | Your full name or display name |
| Admin email | The email you will log in with |
| Admin password | Minimum 12 characters, entered twice |
| Auto-generate service passwords? | Press **Enter** or `Y` to let the installer generate secure random passwords for MariaDB, Redis, and other services. Type `n` to set your own. |

The process usually takes 5-10 minutes depending on server speed and network.

On servers with a large secondary data volume such as `/srv`, accept or choose that larger mount for hosting and backup data. This avoids placing websites on the small root filesystem when the server has a larger dedicated data disk available.

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

## Local Development on Windows

For local panel work on Windows, use the repo bootstrap script documented in [docs/LOCAL-DEVELOPMENT-WINDOWS.md](docs/LOCAL-DEVELOPMENT-WINDOWS.md):

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1
```

This prepares the local Laravel app, SQLite database, npm dependencies, frontend build, and the repo-local PHP runtime used for development validation.

---

## Release Verification

Do not treat a successful asset build or `artisan` boot as a complete release verification.

Every tagged release and every live upgrade should also pass a browser-level verification gate:

- admin login renders `Resellers`, `Security`, `System`, `Infrastructure`, and `Hosting`
- reseller login renders the expected reseller sections
- user login renders the expected user sections
- no browser console errors occur after login
- the client-side Ziggy route payload contains the routes used by the sidebar for the current role

This catches a real failure mode where frontend assets are current but live backend route drift or stale cache removes routes from Ziggy and causes entire sidebar sections to disappear at runtime.

Related docs:

- [docs/DEPLOYMENT-POLICY.md](docs/DEPLOYMENT-POLICY.md)
- [docs/RELEASE-STRUCTURE.md](docs/RELEASE-STRUCTURE.md)
- [docs/RELEASE-UPGRADE-WORKFLOW.md](docs/RELEASE-UPGRADE-WORKFLOW.md)
- [docs/UPGRADING.md](docs/UPGRADING.md)

---

## Live Deployment Certificate Handling

Fresh installs can come up with temporary self-signed certificates if public DNS is not ready when the installer reaches the Let's Encrypt step. That is recoverable without reinstalling.

### Panel and apex HTTPS

If the panel is hosted on a subdomain such as `panel.example.com`, the installer now creates:

- the panel certificate target for `panel.example.com`
- an apex placeholder site and certificate target for `example.com`

After install, go to **Admin -> Nodes -> Primary Node** and use **Repair Public HTTPS**.

That action:

- retries Let's Encrypt for the panel hostname
- retries Let's Encrypt for the apex placeholder when the panel is on a subdomain
- does not require SSH access for the normal repair path

Use it when:

- the browser shows an invalid certificate on the panel after first install
- the apex placeholder site is serving a self-signed certificate
- DNS was delegated or corrected after the installer already finished

### Remote node agent certificates

Remote nodes use TLS for panel-to-agent traffic. If a child node is reachable but still shows as unavailable in the panel, check its agent certificate first.

Expected behavior:

- the node hostname must match the certificate hostname
- the panel should trust that certificate for agent API calls

Current live-safe handling:

- the panel supports pinned per-node certificate bundles for agent trust
- if a remote node is still on a bootstrap self-signed cert, the primary can store that exact cert and use it only for that node
- this is safer than disabling TLS verification globally

Recommended recovery order:

1. Confirm the node hostname in the panel matches the node certificate hostname.
2. Confirm the node IP address in the panel is the real public server IP, not `127.0.0.1`.
3. Try the normal certificate repair flow from the panel.
4. If the node still uses a self-signed certificate, install or pin that node certificate on the primary until a public certificate can be issued.

### What to verify before opening support issues

- `panel.example.com` resolves to the primary server IP
- `node1.example.com` resolves to the remote node IP
- ports `80`, `443`, and `8743` are reachable where expected
- the node hostname shown in **Admin -> Nodes** matches the actual node certificate subject
- the primary node record does not use `127.0.0.1` as its public/control-plane IP

### Operational guidance

- Prefer hosting the panel on a subdomain, not the apex/root domain.
- Treat browser certificate issues and panel-to-agent certificate issues as separate problems.
- Do not leave a global TLS verification bypass in place for normal agent traffic.
- If a temporary bypass is ever used during incident recovery, replace it with a trusted public certificate or a pinned per-node certificate as soon as possible.

---

## DNS Troubleshooting

If the server is intended to act as an authoritative nameserver, there are two separate things to verify:

1. the live PowerDNS zone data is actually present on the server
2. the panel UI is showing the full expected base-install record set

### What a base install should publish for the primary server domain

For a host domain such as `stratadevplatform.net` with the panel on `panel.stratadevplatform.net`, a base install should normally include:

- apex `A`
- panel hostname `A`
- node hostname `A`
- `ns1` / `ns2` glue `A`
- apex `NS`
- apex `SOA`
- apex `CAA`
- `mail A`
- apex `MX`
- apex `SPF`
- `_dmarc TXT`
- `smtp` / `imap` / `pop` / `webmail` aliases

If those records are missing, the server may resolve the panel but still fail a basic DNS health check for mail and nameserver readiness.

### If the Server DNS card does not show the expected records

Check the **Admin -> DNS -> Server DNS** card first.

Expected behavior:

- it should show the complete primary-server baseline for the base domain
- when a managed host zone exists, it should still show missing recommended records instead of hiding them

If the card only shows a reduced subset such as `A`, `NS`, `SOA`, and `CAA`, then either:

- the underlying host zone is incomplete
- or the panel is still running older code that only displays already-existing managed records

### If the live zone itself is incomplete

Verify directly on the primary server:

```bash
pdnsutil list-zone example.com
```

or query the PowerDNS database:

```bash
mysql -uroot pdns -e "select name,type,content,ttl,prio from records where domain_id=(select id from domains where name='example.com') order by name,type,content"
```

Use this when:

- the panel UI looks wrong and you need to confirm whether the problem is data or presentation
- the domain does not pass a basic MXToolbox-style DNS check
- the server answers authoritatively but does not have the mail/bootstrap records expected for a base install

### Common causes

- The base zone was never bootstrapped during install.
- DNS was created manually once, but only the minimum web records were added.
- The primary node was registered with the wrong IP, such as `127.0.0.1`.
- The panel UI is serving older code and the Server DNS card is not merging recommended records with the managed zone.
- `ns2` was published before the backup DNS node was actually ready to answer authoritatively.

### Recommended repair order

1. Confirm the base domain exists as a managed or standalone zone.
2. Confirm the live zone contains the full base-install record set, not just apex/panel/ns records.
3. Confirm `ns1` and `ns2` glue records point to the correct public IPs.
4. Confirm the apex `NS` records match the published nameservers.
5. Confirm the panel UI is showing the full baseline and not just the subset already present in the zone.

### Practical checks

- `pdnsutil list-zone example.com`
- `dig @PRIMARY_IP example.com NS`
- `dig @PRIMARY_IP example.com MX`
- `dig @PRIMARY_IP _dmarc.example.com TXT`
- `dig @PRIMARY_IP mail.example.com A`

### Operational guidance

- Do not treat “the panel resolves” as proof that the base install DNS is complete.
- For a nameserver-ready base install, mail and policy records matter too.
- Prefer fixing the live zone data first, then fixing the UI if the Server DNS card still does not reflect the expected baseline.

---

## Upgrading

Production-style installs should use the fail-safe upgrade utility instead of `git pull`.
Normal deployments should not be made by pushing directly to `main` and patching live servers manually. Use tagged releases and the upgrade utility.

Upgrade to a tagged release:

```bash
curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/upgrade.sh -o /usr/sbin/strata-upgrade
chmod +x /usr/sbin/strata-upgrade
/usr/sbin/strata-upgrade --version 1.0.0-BETA-3.25
```

Public testers can upgrade from a supported update channel:

```bash
/usr/sbin/strata-upgrade --channel main
```

Supported channels:

- `main` - latest supported integration branch
- `latest-untested` - newer pre-release branch with less validation
- `experimental` - unstable branch for active experiments

Manual archive upgrades are also supported:

```bash
/usr/sbin/strata-upgrade --file /root/strata-hosting-panel-1.0.0-BETA-3.25.tar.gz
```

The upgrade utility preserves `.env`, `storage`, service secrets, certificates, hosted files, databases, and mail data. It creates a rollback backup under `/opt/strata-panel-backups/` and automatically restores it if a critical upgrade step fails.

Upgrades also repair older PowerDNS installs by removing unsupported SOA settings and writing the supported `default-soa-content` automatically from the panel/node base domain. This prevents new authoritative zones from inheriting `a.misconfigured.dns.server.invalid.` in SOA responses after upgrade.

When upgrading from `--version` or `--branch`, the primary server also queues matching agent upgrades for online remote nodes. Local archive upgrades are safe for the primary server but cannot be cascaded automatically unless the same build is available from a trusted GitHub URL.

See [docs/UPGRADING.md](docs/UPGRADING.md) for the full workflow and manual rollback notes.
Deployment policy is documented in [docs/DEPLOYMENT-POLICY.md](docs/DEPLOYMENT-POLICY.md), and the recommended release pipeline is in [docs/RELEASE-UPGRADE-WORKFLOW.md](docs/RELEASE-UPGRADE-WORKFLOW.md).
The release/version reference is in [docs/RELEASE-STRUCTURE.md](docs/RELEASE-STRUCTURE.md).

---

## Adding a Child Node

After the panel is running, go to **Admin -> Nodes -> Add Node** to get the HMAC secret and Node ID, then on the child server run the remote node installer. It installs the hosting service stack plus the Strata agent/Web Disk services:

```bash
STRATA_HMAC_SECRET=<secret> \
STRATA_NODE_ID=<id> \
STRATA_NODE_HOSTNAME=node1.example.com \
STRATA_WEB_SERVER=nginx \
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/agent.sh)
```

Remote node installs and upgrades apply the same PowerDNS SOA defaults automatically, so backup DNS nodes answer with the same authoritative `ns1` / `ns2` SOA data as the primary.

Remote node installs now also prompt for hosting and backup storage roots unless `STRATA_HOSTING_STORAGE_ROOT` and `STRATA_BACKUP_STORAGE_ROOT` are provided up front. The selected paths are bind-mounted onto `/var/www` and `/var/backups/strata`.

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

## Features (1.0.0-BETA-3.25)

| Category | Features |
|---|---|
| **Accounts** | Create/suspend/terminate, packages, feature lists, resource limits, system user provisioning |
| **Reseller Portal** | Dashboard with quota meters, create/manage client accounts, package selection, default packages, white-label branding |
| **Domains** | Nginx/Apache vhosts, user-owned domain deletion with managed DNS/settings cleanup, default starter index page, SSL, Force HTTPS, PHP version per domain, redirects, custom directives, hotlink protection, directory privacy |
| **Email** | Shared Email Accounts workspace for admins/resellers/users, scoped mail-domain enablement, mailboxes, password changes, forwarders, secure client port guide, autoresponders, shared OpenDKIM key regeneration, DKIM/SPF/DMARC setup, Domain Key Manager, SPF Manager, filters, spam policies, archive controls, delivery tracking, bulk import, admin Postfix queue and delivery-log diagnostics |
| **DNS** | Automatic DNS zone provisioning for hosted domains, primary-hostname-derived nameserver records, backup-node DNS mirroring, admin DNS cluster health, manual full/targeted sync, public-IP default web/mail record sets, PowerDNS zone management, full record type support, BIND import/export, server DNS zones |
| **Databases** | MariaDB/PostgreSQL create/delete/password, user grants, remote MariaDB host grants, phpMyAdmin/phpPgAdmin launch guide with availability checks |
| **FTP / Web Disk** | Pure-FTPd jailed accounts with FTPS enforced, plus dedicated WebDAV-over-HTTPS Web Disk accounts on port 2078 |
| **File Manager** | Browser-based upload/download/edit/chmod/compress/extract |
| **Backups** | Files + databases, schedules, manual trigger, download, path restore, remote SFTP/S3 destinations, cPanel/CWP archive import conversion |
| **Metrics** | Resource usage, log viewer/downloads, recent traffic summaries, 30-day stored traffic history, CSV traffic exports |
| **Security** | 2FA, audit log, dedicated Admin Security sidebar for Firewall and Fail2Ban administration, SSH keys, UFW firewall rules, ClamAV malware scans with daily/weekly scheduling and quarantine cleanup, per-domain ModSecurity and leech protection controls |
| **SSL** | Webroot Let's Encrypt issuance plus managed-DNS wildcard certificates and renewal for hosted zones |
| **UI / Accessibility** | Glassmorphism app shell with persisted Smoky Gray, Aurora Teal, Ember Gold, and Violet Bloom theme preferences |
| **Admin Tools** | Browser SSH terminal, email deliverability troubleshooter, OS update management, backup schedules, audited client-panel access, bulk operations |
| **Multi-node** | Remote nodes via Go agent with HMAC auth, health monitoring, per-node service management, conservative account migration workflow with reset-required service cutover, competitor-backup import staging |
| **Billing API** | REST provisioning API, Bearer token auth, package/feature catalog API, migration API, outbound audit webhooks |

## Architecture

A single install gives you a functional hosting server. Remote nodes can be added to scale horizontally.

- Project plan and phases: [docs/PLAN.md](docs/PLAN.md)
- Billing/provisioning API: [docs/API.md](docs/API.md)
- DNS troubleshooting guide: [docs/DNS-TROUBLESHOOTING.md](docs/DNS-TROUBLESHOOTING.md)
- Beta validation criteria and known limitations: [BETA-RELEASE-CHECKLIST.md](BETA-RELEASE-CHECKLIST.md)
- Public demo credentials and reset instructions: [docs/PUBLIC-DEMO.md](docs/PUBLIC-DEMO.md)

## Contributing & Feedback

This is an alpha release for public testing. Issues are expected.

- **Bugs / broken features:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues)
- **Installer or demo-server problems:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues) and include the page, action, expected result, and actual result.
- **Feature requests:** [Open an issue](https://github.com/jonathjan0397/strata-hosting-panel/issues) with the `enhancement` label.
- **Pull requests:** Welcome. Please open an issue first for anything large so we can discuss the approach.


