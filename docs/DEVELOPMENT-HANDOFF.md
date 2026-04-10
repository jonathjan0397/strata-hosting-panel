# Development Handoff

This document is the fast-start reference for a new coding agent or developer picking up Strata work midstream.

It is intended to prevent repeated rediscovery of:

- current branch and release policy
- live environment shape
- recent architectural changes
- known failure modes
- what must not be forgotten between sessions

## Current State

Project:

- Strata Hosting Panel
- Repo: `jonathjan0397/strata-hosting-panel`

Current public release line:

- latest published beta at the time of this handoff: `1.0.0-BETA-3.11`

Current branch policy:

- `main` = public testing branch
- `latest-untested` = riskier pre-release validation branch
- `experimental` = high-risk branch for unfinished work

Do not resume the old pattern of patching live directly from untagged `main`.

## Critical Workflow Rules

1. Normal development should happen on feature/fix branches and merge into `main`.
2. Live deployments should go through the upgrade system.
3. Published tags must not be force-moved or reused for different code.
4. If a fix lands after a release is published, cut a new release tag.
5. If a live hotfix is unavoidable, commit it back into source immediately and include it in the next formal release.

Related docs:

- [docs/RELEASE-STRUCTURE.md](RELEASE-STRUCTURE.md)
- [docs/DEPLOYMENT-POLICY.md](DEPLOYMENT-POLICY.md)
- [docs/RELEASE-UPGRADE-WORKFLOW.md](RELEASE-UPGRADE-WORKFLOW.md)
- [docs/UPGRADING.md](UPGRADING.md)

## Live Environment

Primary panel server:

- hostname: `panel.stratadevplatform.net`
- primary IP: `192.151.156.125`

Remote node:

- hostname: `node1.stratadevplatform.net`
- remote IP: `69.197.151.84`

Base domain:

- `stratadevplatform.net`

Important:

- do not store or commit live passwords, secrets, HMAC values, API tokens, or generated credentials in this repo
- if a new agent needs credentials, retrieve them from the operator or from the server-side credential files, not from source control

## Local Development Environment

Windows local development is supported.

Important local helpers:

- bootstrap script: [tools/bootstrap-local-windows.ps1](../tools/bootstrap-local-windows.ps1)
- local Windows dev doc: [docs/LOCAL-DEVELOPMENT-WINDOWS.md](LOCAL-DEVELOPMENT-WINDOWS.md)

Repo-local PHP runtime:

- `.tools/php83/php.exe`

Known local issue:

- the WinGet PHP install can be blocked by Windows permissions
- use the repo-local PHP runtime instead of assuming `php.exe` on `PATH` will work

## High-Value Product Changes Already Landed

These areas were recently changed and should be assumed to exist unless proven otherwise.

### Installer / Upgrade / Release

- release-based upgrade flow
- update UI simplified around release tags
- advanced source branch display
- rollback to stored upgrade backups
- upgrade utility exposed through `/usr/sbin/strata-upgrade`
- panel update metadata cache reduced from 10 minutes to 2 minutes
- update page now has a live progress indicator and log scroller
- fresh primary and remote-node installs now prompt for hosting-data and backup-data storage roots
- installer keeps runtime compatibility by bind-mounting the selected storage roots onto `/var/www` and `/var/backups/strata`
- installers now display the exact Strata release version being installed instead of a generic beta label

### DNS

- base/host DNS zone bootstrap during install
- root-domain zone reuse instead of duplicate zone creation
- DNS self-heal and backup sync hardening
- `hosts_dns` node flag for explicit DNS-capable nodes
- primary authoritative drift repair added
- PowerDNS rectify handling matters; DNS can exist in backend and still not be served correctly if rectify/reload is skipped

### Certificates

- admin repair flow for public HTTPS
- pinned per-node certificate handling for agent trust
- mail TLS handling separated from panel TLS handling
- current safe mail-client guidance is to use the hosting server's shared mail hostname, not per-domain `mail.<domain>` branding, unless product support for branded mail TLS is explicitly completed and validated

### Troubleshooting / Mail

- troubleshooting section for admin, reseller, and user
- DNS/mail/certificate guidance surfaced in panel
- DKIM/SPF/DMARC repair actions added
- mail pages improved to expose DKIM/SPF/DMARC status directly
- webmail runtime now depends on `/var/www/webmail/include.php`, not only `_include.php`
- OpenDKIM socket access should use `UserID opendkim:postfix`; avoid reintroducing the old `postfix` supplementary-group workaround
- older upgraded installs may miss `auth_mechanisms = plain login` in Dovecot; release `1.0.0-BETA-3.07` added upgrade repairs for that so Outlook submission on port `587` stops failing with `Invalid authentication mechanism: 'LOGIN'`
- phpMyAdmin should use normal cookie authentication, not a Strata-managed control user
- MariaDB app/database user provisioning must force the requested password onto existing users; `CREATE USER IF NOT EXISTS` by itself is not safe for reused usernames

### Navigation / UI

- navbar was stabilized after a regression
- admin `System` disappearance was caused by live backend route drift, not only frontend code
- sidebar changes should be treated carefully and verified with browser checks

## Recent Failure Modes You Must Remember

### 1. Live route drift can break the UI even when assets are current

Observed failure:

- admin `System` section disappeared

Actual cause:

- the live backend route set was stale
- Ziggy was missing `admin.troubleshooting.index`
- frontend bundle was current, but runtime route resolution failed and hid part of the nav

Implication:

- after deployments, do not only verify assets
- verify backend routes and browser behavior together

### 2. Upgrade failures can be migration-state dependent

Observed failure:

- upgrade to `1.0.0-BETA-3.01` failed on live

Actual cause:

- migration `2026_04_09_160000_add_hosts_dns_to_nodes_table.php` tried to add an already-existing column

Fix:

- migration now guards `up()` and `down()` with `Schema::hasColumn()`

Implication:

- upgrades must be safe against partially patched or previously hotfixed live systems

### 3. DNS can fail from bootstrap assumptions

Observed failure:

- base domain did not resolve correctly
- PowerDNS had missing or stale zone state

Important lessons:

- root/base zone must exist on install
- root-domain provisioning must reuse shared host zones
- secondary DNS must be verified against primary, not assumed correct
- rectify/reload matters after record changes

### 3b. Large disks may exist even when the panel appears to show only ~40 GB

Observed failure:

- servers were provisioned with roughly 500 GB disks
- panel node status appeared to show only ~30-40 GB available

Actual cause:

- the servers had separate mounts for `/`, `/var`, and a large data volume such as `/srv`
- the node status UI was only surfacing the first small mounts prominently
- hosting data would still land on the small system path unless storage placement was handled explicitly during install

Decision:

- new installs should ask where hosting data and backup data will live
- the installer should recommend the largest suitable mounted volume
- product runtime paths remain `/var/www` and `/var/backups/strata` via bind mounts for compatibility

Implication:

- do not change the app to arbitrary new paths casually; too much code still assumes `/var/www`
- prefer storage-root selection plus bind mounts over direct path rewrites

### 4. SMTP problems may not be provider blocking

Observed failure:

- mail tests failed even after provider said they were not blocking ports

Actual causes encountered:

- Postfix listener/auth config issues
- wrong TLS certificate identity on mail services
- missing SASL socket assumptions

Implication:

- do not stop at provider responses
- verify listeners, TLS identity, local service health, and packet flow

### 5. Do not imply per-domain mail hostnames are ready by default

Observed failure:

- Outlook and other strict clients failed when using `mail.<hosted-domain>` because the product did not yet guarantee matching branded mail TLS for every hosted domain

Decision:

- the mail client guide should recommend the hosting server's shared mail hostname
- mailbox identity remains `user@their-domain`
- transport hostname should stay on the certificate-valid shared mail host until branded mail TLS is implemented end-to-end and validated

Implication:

- do not casually switch docs or UI back to `mail.<domain>` defaults
- treat branded mail TLS as a separate product feature, not assumed behavior

### 6. phpMyAdmin failures can be a mix of panel-created user drift and package config drift

Observed failure:

- phpMyAdmin reported both:
  - control-user auth failure for `phpmyadmin`
  - login failure for a panel-created MariaDB user even though the panel had just shown a password

Actual causes:

- phpMyAdmin package config was still trying to use a stale/bad control user
- MariaDB provisioning used `CREATE USER IF NOT EXISTS`, which preserved an old password when a username already existed from an earlier partial attempt

Decision:

- force phpMyAdmin back to normal cookie auth with a Strata override
- always `ALTER USER` after MariaDB user creation and during password changes
- update both `localhost` and `127.0.0.1` MariaDB entries

Implication:

- do not trust `CREATE USER IF NOT EXISTS` as sufficient password management
- do not introduce a custom phpMyAdmin control-user path unless it is fully owned and repaired by Strata

## Release Expectations

A proper release should move through:

1. feature/fix branch
2. merge to `main`
3. local validation
4. browser verification
5. version/doc bump
6. tag
7. GitHub release publish
8. live upgrade through upgrade utility

Do not skip the browser verification gate.

Required browser-level checks:

- admin nav includes `Resellers`, `Security`, `System`, `Infrastructure`, `Hosting`
- reseller nav renders expected reseller sections
- user nav renders expected user sections
- no browser console errors after login
- Ziggy contains routes required by the visible sidebar

## Live Upgrade Notes

Updater behavior now includes:

- normal release upgrade by tag
- advanced source by branch
- rollback to stored upgrade backup
- upgrade activity polling
- tailed log display

When a release is published on GitHub:

- the panel should usually show it within about 2 minutes
- or immediately after cache clear

If the panel does not show the new release:

- verify latest release exists on GitHub
- verify the live code includes the shorter cache window
- clear Laravel cache if needed

## Files A New Agent Should Check First

If continuing product work, start here:

- [README.md](../README.md)
- [docs/PLAN.md](PLAN.md)
- [docs/RELEASE-STRUCTURE.md](RELEASE-STRUCTURE.md)
- [docs/DEPLOYMENT-POLICY.md](DEPLOYMENT-POLICY.md)
- [docs/RELEASE-UPGRADE-WORKFLOW.md](RELEASE-UPGRADE-WORKFLOW.md)
- [docs/UPGRADING.md](UPGRADING.md)

If continuing update-system work:

- [panel/app/Http/Controllers/Admin/UpdateController.php](../panel/app/Http/Controllers/Admin/UpdateController.php)
- [panel/resources/js/Pages/Admin/Updates/Index.vue](../panel/resources/js/Pages/Admin/Updates/Index.vue)
- [installer/upgrade.sh](../installer/upgrade.sh)

If continuing DNS work:

- [panel/app/Services/DnsProvisioner.php](../panel/app/Services/DnsProvisioner.php)
- [panel/app/Console/Commands/SyncBackupDnsZones.php](../panel/app/Console/Commands/SyncBackupDnsZones.php)
- [panel/app/Models/Node.php](../panel/app/Models/Node.php)

If continuing sidebar / browser-verification work:

- [panel/resources/js/Layouts/AppLayout.vue](../panel/resources/js/Layouts/AppLayout.vue)
- [panel/resources/js/Components/NavGroup.vue](../panel/resources/js/Components/NavGroup.vue)
- [panel/resources/js/Components/NavItem.vue](../panel/resources/js/Components/NavItem.vue)

## Tools And Workspace Notes

There are many untracked one-off scripts under `tools/`.

Treat them as incident tooling unless explicitly promoted.

Do not assume an untracked script is part of the supported product path.

Generally:

- reusable documented tooling may be committed
- one-off deploy, repair, and inspection scripts should remain untracked

## Session Carry-Over Checklist

At the start of a new session, confirm:

1. what the current latest release tag is
2. whether `main` has moved past that release
3. whether the live panel is on the latest release or still behind
4. whether there are known live hotfixes not yet formalized in source
5. whether the current task belongs on `main`, `latest-untested`, or a feature branch

Before ending a session, leave behind:

1. any new release/version changes committed
2. any new operational rules documented
3. any unresolved live issue summarized clearly
4. any new failure mode captured in docs if it is likely to recur

## What Not To Forget

- never rely on stale memory for the current release state; verify it
- never assume the live panel matches local source; verify routes, assets, and browser behavior
- never rewrite a published tag
- never commit secrets into the repo
- never treat live patching as the normal deployment model


