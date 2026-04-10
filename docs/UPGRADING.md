# Upgrading Strata Hosting Panel

Strata installs are not expected to be Git working copies. Use the upgrade utility so runtime state is preserved and rollback is available.
Do not treat direct pushes to `main` plus live SSH patching as the normal deployment path.

Related policy documents:

- [DEPLOYMENT-POLICY.md](DEPLOYMENT-POLICY.md)
- [RELEASE-UPGRADE-WORKFLOW.md](RELEASE-UPGRADE-WORKFLOW.md)

## Supported Upgrade Sources

Upgrade from a tagged release:

```bash
curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/upgrade.sh -o /usr/sbin/strata-upgrade
chmod +x /usr/sbin/strata-upgrade
/usr/sbin/strata-upgrade --version 1.0.0-BETA-3
```

Upgrade from a supported update channel:

```bash
/usr/sbin/strata-upgrade --channel main
```

Supported channels:

- `main` - latest supported integration branch
- `latest-untested` - newer branch for early validation
- `experimental` - unstable branch for active experimental work

Upgrade from a manually uploaded archive:

```bash
/usr/sbin/strata-upgrade --file /root/strata-hosting-panel-1.0.0-BETA-3.tar.gz
```

The file may be a GitHub source archive or an equivalent `.tar`, `.tar.gz`, or `.tgz` archive containing the repository root.

## What The Upgrade Preserves

The utility replaces application source code but preserves runtime state:

- `/opt/strata-panel/panel/.env`
- `/opt/strata-panel/panel/storage`
- `/etc/strata-panel`
- `/etc/strata-agent`
- `/etc/strata-webdav`
- existing TLS certificates and service secrets
- hosted account data under `/var/www`
- databases and mail data

## What The Upgrade Runs

The upgrade process:

- creates a timestamped rollback backup in `/opt/strata-panel-backups/<timestamp>`
- stops `strata-agent`, `strata-webdav`, and `strata-queue`
- installs the new panel and agent source
- restores `.env` and `storage`
- runs Composer install
- runs Laravel migrations
- builds frontend assets
- rebuilds `/usr/sbin/strata-agent` and `/usr/sbin/strata-webdav`
- repairs PowerDNS SOA defaults on the primary and upgraded remote nodes by writing the supported `default-soa-content` derived from the panel/node base domain
- resets panel permissions
- restarts `strata-agent`, `strata-webdav`, `strata-queue`, PHP-FPM, and the web server
- runs health checks
- should be followed immediately by a browser verification pass against the live panel
- queues matching agent upgrades for online remote nodes when upgrading from `--version` or `--branch`

## Required Post-Upgrade UI Verification

After every upgrade, verify the live panel in a real browser session. This is required because route drift or stale backend cache can leave the frontend assets updated while the browser still fails to render parts of the sidebar.

Minimum checks:

- admin login works
- reseller login works
- user login works
- admin sidebar includes:
  - `Resellers`
  - `Security`
  - `System`
  - `Infrastructure`
  - `Hosting`
- no browser console errors after login

Also verify that the client-side Ziggy route payload contains the routes used by the sidebar for the active role. Missing Ziggy routes can hide a whole nav section even when the frontend build itself is current.

## Fail-Safe Rollback

If a critical step fails, the utility automatically restores:

- previous panel source
- previous agent source
- previous `/usr/sbin/strata-agent`
- Laravel cache state
- services

The rollback backup is kept after successful upgrades too, so you can manually inspect or restore from it later.

Manual rollback example:

```bash
systemctl stop strata-agent strata-queue
rm -rf /opt/strata-panel/panel /opt/strata-panel/agent-src
cp -a /opt/strata-panel-backups/20260407-120000/panel /opt/strata-panel/panel
cp -a /opt/strata-panel-backups/20260407-120000/agent-src /opt/strata-panel/agent-src
cp -a /opt/strata-panel-backups/20260407-120000/strata-agent /usr/sbin/strata-agent
chmod 755 /usr/sbin/strata-agent
chown -R strata:www-data /opt/strata-panel/panel
chmod -R ug+rwX /opt/strata-panel/panel/storage /opt/strata-panel/panel/bootstrap/cache
cd /opt/strata-panel/panel
php8.4 artisan optimize:clear
php8.4 artisan config:cache
php8.4 artisan route:cache
systemctl restart php8.4-fpm nginx strata-agent strata-queue
```

Adjust the backup timestamp and PHP service name if your install differs.

## Notes

- Run the upgrade utility as `root`.
- Keep at least 1 GB of free disk space before upgrading.
- Do not manually overwrite `/opt/strata-panel/panel` with an archive unless you also preserve `.env`, `storage`, permissions, and services.
- Public testers can use `--channel main`; stable users should prefer tagged releases.
- Remote node cascade upgrades require the node to have `/usr/sbin/strata-agent-upgrade`, which is installed by the current remote node installer (`installer/agent.sh`) and by the full installer. Older nodes without that helper should be upgraded once manually by rerunning the remote node installer.
- Local `--file` upgrades cannot be cascaded automatically unless the same build is also available from a trusted GitHub tag or branch URL.
- The panel and agent version are derived from the release tag, branch name, or the repository `VERSION` file. Node health checks update each node's `agent_version`.
- The internal remote cascade command is `php artisan strata:nodes-upgrade-agents --target-version <tag>`, `--channel <channel>`, or `--branch <branch>`. The top-level `/usr/sbin/strata-upgrade` wrapper handles this for normal upgrades.
- If you use Strata as authoritative DNS, verify that your host-domain zone answers with an SOA similar to `ns1.example.com. hostmaster.example.com.` before changing registrar nameservers. Current installs should now be repaired automatically during upgrade.
