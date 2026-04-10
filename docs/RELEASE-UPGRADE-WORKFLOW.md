# Release And Upgrade Workflow

This document defines the recommended technical workflow for shipping Strata changes without deploying directly from `main`.

## Goal

Every deployable Strata change should move through:

1. source control
2. a tagged release
3. the upgrade system

## Release Model

The release tag is the canonical deployable version.

Example:

```text
1.0.0-BETA-3.10
```

The release should correspond to a single tested commit and a single upgradeable artifact set.

## Recommended Artifact Set

Each release should publish:

- repository source archive
- prebuilt panel frontend assets
- prebuilt Linux `amd64` agent binary
- release metadata file such as `release.json`

## Recommended `release.json`

The upgrade system should eventually consume a release manifest similar to:

```json
{
  "version": "1.0.0-BETA-3.10",
  "panel": {
    "source": "panel.tar.gz",
    "assets": "panel-public-build.tar.gz"
  },
  "agent": {
    "linux_amd64": "strata-agent-linux-amd64"
  },
  "installer": {
    "upgrade_script": "installer/upgrade.sh",
    "agent_upgrade_script": "installer/agent-upgrade.sh"
  },
  "migrations": true,
  "post_upgrade_checks": [
    "php artisan about",
    "php artisan migrate --force",
    "php artisan dns:sync-backup-zones",
    "agent health",
    "node availability"
  ]
}
```

## Upgrade Order

The upgrade utility should execute in this order:

1. create rollback backup
2. download or unpack the tagged release
3. replace panel source
4. restore preserved runtime state
5. apply database migrations
6. install prebuilt frontend assets
7. update the primary agent binary
8. restart primary services
9. run post-upgrade health checks
10. upgrade remote node agents
11. verify cluster health

## Why Prebuilt Assets And Binaries Matter

Avoid relying on live builds when possible.

Prebuilt assets and binaries reduce:

- npm/environment drift on customer servers
- Go toolchain mismatch on customer servers
- long upgrade windows
- deployment failures caused by build prerequisites instead of product code

## Post-Upgrade Checks

Minimum checks the upgrade system should run automatically:

- `php artisan about`
- `php artisan migrate --force`
- `php artisan dns:sync-backup-zones`
- agent `/v1/health`
- primary and node availability in the panel
- web UI asset presence
- certificate repair endpoint availability on the primary

## Browser Verification Gate

Laravel and asset health are not sufficient by themselves. Every release candidate and every live upgrade should also pass a browser-level verification gate.

Minimum browser checks:

- admin login succeeds and the sidebar renders:
  - `Resellers`
  - `Security`
  - `System`
  - `Infrastructure`
  - `Hosting`
- reseller login succeeds and expected reseller sections render
- end-user login succeeds and expected user sections render
- no browser console errors after login
- the Ziggy route payload contains the routes used by the active sidebar for that role

This check exists because a stale backend route file or cache can leave the frontend bundle current while the browser still throws runtime route errors, which can hide entire nav sections without breaking the whole page.

Recommended implementation:

- run a headless browser smoke test after asset deploy and cache warmup
- capture sidebar group labels for `admin`, `reseller`, and `user`
- capture browser console output and fail on route/runtime errors
- fail the release or upgrade if any required nav group is missing

## Rollback Standard

Rollback should restore:

- previous panel source
- previous built assets
- previous agent binary
- previous migration-safe application state where possible
- service startup state

Rollback must be:

- automatic on critical failure
- documented
- idempotent

## Immediate Implementation Recommendations

Short-term:

1. protect `main`
2. require PRs
3. deploy only tagged releases through `/usr/sbin/strata-upgrade`
4. add browser verification of Ziggy routes and sidebar groups to the release gate
5. stop normal live patching

Next:

1. teach releases to publish prebuilt `panel/public/build`
2. publish prebuilt `strata-agent` binaries
3. add release metadata
4. make upgrade scripts consume release artifacts explicitly

Later:

1. add CI release packaging
2. add signed artifacts if desired
3. add staged rollout support for primary and remote nodes

## Strata-Specific Rule

If a fix is important enough to touch a live server, it is important enough to:

1. land in git
2. be tagged
3. be upgradeable

That should be the standard for all future Strata deployments.

## Mail Client Guidance Rule

Until branded per-domain mail TLS is fully implemented and validated, release-ready UI and docs should recommend the hosting server's shared mail hostname for IMAP/POP/SMTP client setup.

That means:

- mailbox identity: `user@their-domain`
- client transport hostname: shared server mail host
- do not present `mail.<hosted-domain>` as the default recommended client endpoint unless certificate validity and client compatibility are proven for that deployment model


