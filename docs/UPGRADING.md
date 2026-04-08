# Upgrading Strata Hosting Panel

Strata installs are not expected to be Git working copies. Use the upgrade utility so runtime state is preserved and rollback is available.

## Supported Upgrade Sources

Upgrade from a tagged release:

```bash
curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/upgrade.sh -o /root/strata-upgrade.sh
chmod +x /root/strata-upgrade.sh
/root/strata-upgrade.sh --version v1.0.0-beta.2
```

Upgrade from the latest `main` branch for public testing:

```bash
/root/strata-upgrade.sh --branch main
```

Upgrade from a manually uploaded archive:

```bash
/root/strata-upgrade.sh --file /root/strata-hosting-panel-v1.0.0-beta.2.tar.gz
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
- resets panel permissions
- restarts `strata-agent`, `strata-webdav`, `strata-queue`, PHP-FPM, and the web server
- runs health checks
- queues matching agent upgrades for online remote nodes when upgrading from `--version` or `--branch`

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
- Public testers can use `--branch main`; stable users should prefer tagged releases.
- Remote node cascade upgrades require the node to have `/usr/sbin/strata-agent-upgrade`, which is installed by the current remote node installer (`installer/agent.sh`) and by the full installer. Older nodes without that helper should be upgraded once manually by rerunning the remote node installer.
- Local `--file` upgrades cannot be cascaded automatically unless the same build is also available from a trusted GitHub tag or branch URL.
- The panel and agent version are derived from the release tag, branch name, or the repository `VERSION` file. Node health checks update each node's `agent_version`.
- The internal remote cascade command is `php artisan strata:nodes-upgrade-agents --target-version <tag>` or `--branch <branch>`. The top-level `/root/strata-upgrade.sh --version <tag>` wrapper handles this for normal upgrades.
