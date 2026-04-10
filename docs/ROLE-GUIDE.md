# Strata Hosting Panel Role Guide

Admin, Reseller, and User functions with quick-start guidance.

This document is written to be easy to export to PDF for onboarding, testing, and customer reference.

---

## How To Use This Guide

Use this guide to answer three questions quickly:

1. What can this role access?
2. What can this role manage directly?
3. How do I complete the most common tasks without hunting through the panel?

---

## Admin

Admins control the full platform.

### Access Areas

- `Resellers`
  Manage reseller accounts and reseller quotas.
- `Security`
  Manage firewall rules, IP blocking, and Fail2Ban.
- `System`
  Access troubleshooting, audit logs, deliverability checks, backups, backup schedules, remote backups, security center, spam filter, API tokens, and webhooks.
- `Infrastructure`
  Manage nodes and platform updates.
- `Hosting`
  Manage websites, accounts, migrations, packages, feature lists, domains, DNS zones, server DNS, email accounts, and the mail queue.

### Admin Capabilities

- create and manage hosting accounts
- create and manage reseller accounts
- assign packages and feature limits
- add and manage nodes
- manage DNS zones and server DNS
- enable mail for domains
- create and manage mailboxes and forwarders
- inspect and flush the mail queue
- run updates and rollbacks
- inspect backups and remote backup destinations
- use troubleshooting and deliverability tools

### Quick Start Tasks

#### Create a Hosting Account

1. Go to `Hosting -> Accounts`.
2. Select `Create Account`.
3. Enter owner details, username, package, node, and limits.
4. Submit and wait for provisioning to complete.

#### Add a Domain

1. Go to `Hosting -> Domains`.
2. Select `Create Domain`.
3. Choose the owning account.
4. Enter the domain and review provisioning settings.

#### Enable Mail for a Domain

1. Open the target domain or the domain email page.
2. Select `Enable Mail`.
3. Review the DKIM, SPF, DMARC, and MX guidance after provisioning finishes.

#### Run a Panel Upgrade

1. Go to `Infrastructure -> Updates`.
2. Review `Current Version` and `Latest Published Release`.
3. Start the panel upgrade.
4. Watch the progress view and log scroller until completion.

#### Roll Back to a Backup

1. Go to `Infrastructure -> Updates`.
2. Review the available rollback backups.
3. Choose `Rollback To Backup` only when a completed upgrade needs to be reversed.

### Admin Boundaries

- admins have no role-level platform restrictions
- operational success still depends on node health, DNS, firewall rules, certificates, and service availability

---

## Reseller

Resellers manage their own customer accounts inside the limits assigned by the admin.

### Access Areas

- `Reseller`
  Dashboard, clients, packages, and settings.
- `Mail`
  Shared email account management for accounts in reseller scope.
- `Diagnostics`
  Troubleshooting for domains and customer issues in reseller scope.

### Reseller Capabilities

- create and manage their own client accounts
- assign packages that are available to them
- inspect domain and email configuration for their clients
- use troubleshooting tools within reseller scope
- support customer issues through the panel

### Reseller Limits

- cannot manage nodes
- cannot run platform upgrades
- cannot manage global DNS infrastructure
- cannot manage other resellers
- cannot access platform-wide security and audit controls reserved for admins

### Quick Start Tasks

#### Create a Client Account

1. Go to `Reseller -> Clients`.
2. Select `Create Client`.
3. Enter account details and choose a package.
4. Submit and wait for provisioning.

#### Review a Client Email Setup

1. Open `Mail -> Email Accounts`.
2. Locate the client domain.
3. Review mailbox status and DKIM, SPF, and DMARC information.

#### Troubleshoot a Client Domain

1. Go to `Diagnostics -> Troubleshooting`.
2. Select the affected domain.
3. Review DNS, mail, and certificate findings.
4. Follow the suggested actions or escalate to an admin if the issue is infrastructure-level.

---

## User

Users manage their own hosting account and domains within the package assigned to them.

### Access Areas

- `Websites`
  Domains and DNS.
- `Mail`
  Email accounts and deliverability.
- `Files`
  Database tools, web disk, and file-related utilities.
- `Developer`
  Git, app installer, and development-oriented features when enabled.
- `Security`
  SSH keys, malware scanning, and related controls when enabled.
- `Diagnostics`
  Troubleshooting tools for the user's own domains.

Visible items depend on the package and enabled feature list.

### User Capabilities

- add and manage domains within package limits
- manage DNS records when DNS is available to them
- create and manage mailboxes
- open webmail
- create FTP and Web Disk credentials
- manage databases
- use troubleshooting and deliverability tools
- manage files and deploy site content

### User Limits

- cannot manage nodes or updates
- cannot manage reseller or other customer accounts
- cannot access platform-wide logs, queue controls, or firewall administration

### Quick Start Tasks

#### Add a Domain

1. Go to `Websites -> Domains`.
2. Select `Add Domain`.
3. Enter the domain and complete provisioning.

#### Create a Mailbox

1. Go to `Mail -> Email Accounts`.
2. Select the target domain.
3. Create the mailbox and set a password.
4. Use the full mailbox address as the login username for mail clients.

#### Connect a Mail Client

Use these defaults:

- username: full mailbox address
- password: mailbox password
- incoming server: your hosting server's mail hostname
- outgoing server: your hosting server's mail hostname
- IMAP: `993` with `SSL/TLS`
- SMTP submission: `587` with `STARTTLS`

The current platform guidance is to use the hosting server's shared mail hostname so certificate validation stays correct.

#### Create FTP Access

1. Go to the FTP page for your account.
2. Create an FTP user and password.
3. Use FTPS with passive mode in a proper client such as FileZilla or WinSCP.

#### Use Troubleshooting

1. Go to `Diagnostics -> Troubleshooting`.
2. Select the domain you want to inspect.
3. Review DNS, mail, and certificate findings.
4. Follow any repair suggestions exposed by the panel.

---

## Shared Best Practices

- use secure mail ports by default
- do not use plain FTP when FTPS or Web Disk is available
- install published releases through the update system instead of patching the server manually
- when a domain issue appears to be "just DNS," also verify certificates and service hostnames

---

## Suggested PDF Title

`Strata Hosting Panel Role Guide`

## Suggested PDF Subtitle

`Admin, Reseller, and User Functions With Quick Start Guidance`

