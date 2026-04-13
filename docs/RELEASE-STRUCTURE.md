# Release Structure Reference

This document is the canonical reference for how Strata versions, branches, tags, and published releases are supposed to work.

## Purpose

Use this when deciding:

- what branch a change belongs on
- what version number to assign
- when to cut a tag
- what the updater should see
- what can be rolled back safely

## Branch Roles

Strata uses three named update branches plus normal short-lived feature branches.

### `main`

`main` is the public testing branch.

Rules:

- keep it stable enough for testers to upgrade to
- do not use it as a scratch branch
- do not patch live servers from untagged `main`
- every change merged here should be a real candidate for the next tagged release

### `latest-untested`

`latest-untested` is the staging branch for newer work that is not yet ready to be treated like the normal public testing line.

Rules:

- branch from a known-good `main` baseline
- use it for riskier validation before promoting changes back into the normal release path
- expose it in the updater only as an advanced source

### `experimental`

`experimental` is the high-risk branch.

Rules:

- unfinished features are allowed here
- breakage is acceptable
- do not treat it as a release branch
- keep it available only for deliberate opt-in testing

### Feature / Fix Branches

Normal development should happen on short-lived branches and merge into `main` through review.

Examples:

- `feature/upgrade-progress-viewer`
- `fix/root-zone-reuse`
- `docs/release-reference`

## Version Format

Current public test releases use this format:

```text
1.0.0-BETA-3.26
```

Meaning:

- `1.0.0` = base product version
- `BETA` = release stage
- `3` = beta train
- `.03` = incremental release within that train

## Release Stages

### Alpha

Used when the product is still highly volatile and broad workflows are still being proven.

Examples:

- `v1.0.0-alpha.3`

### Beta

Used for broader public testing once install, upgrade, and major product paths are working well enough for external testers.

Examples:

- `1.0.0-BETA-3`
- `1.0.0-BETA-3.01`
- `1.0.0-BETA-3.26`

## Tag Rules

Release tags are the deployable unit.

Rules:

- every deployable release gets a git tag
- tags must point to one specific tested commit
- do not retag an already published release to different code
- if you need to add fixes after publication, create a new tag

Examples:

- correct: `1.0.0-BETA-3.26`
- incorrect: force-moving `1.0.0-BETA-3.26` after users already upgraded to it

## GitHub Release Rules

Each deployable tag should also have a published GitHub release.

Rules:

- the newest intended public release should be marked as `Latest`
- older superseded releases should be relabeled as archived in their title if you want the release page cleaner
- unpublished tags should not be treated as normal upgrade targets for public testers

## What The Panel Updater Should Show

The panel updater has two different concepts:

### Normal Release Upgrade

This should use a published release tag.

Examples:

- `1.0.0-BETA-3.26`

This is the default and recommended path.

### Advanced Source

This should show the available GitHub branches:

- `main`
- `latest-untested`
- `experimental`

This is only for deliberate branch-based testing.

## Release Contents

A proper Strata release includes all of these as one versioned unit:

- panel source
- agent source
- installer scripts
- upgrade scripts
- migrations
- frontend assets
- documentation updates
- changelog entry

If one of those changes without the release metadata changing too, the release is incomplete.

## Release Flow

The intended sequence is:

1. develop on a feature or fix branch
2. merge to `main`
3. validate locally
4. validate browser behavior
5. bump version references
6. commit release prep
7. create tag
8. push tag
9. publish GitHub release
10. upgrade live systems through `/usr/sbin/strata-upgrade --version <tag>`

## Upgrade Semantics

Normal live upgrades should use:

```bash
/usr/sbin/strata-upgrade --version 1.0.0-BETA-3.26
```

Branch-based testing upgrades should use:

```bash
/usr/sbin/strata-upgrade --branch main
```

or:

```bash
/usr/sbin/strata-upgrade --channel main
```

Release tags are the preferred path. Branch upgrades are for testing.

## Rollback Semantics

There are two rollback styles.

### Release Rollback

Roll back to an older known tag by upgrading to that version explicitly.

Example:

```bash
/usr/sbin/strata-upgrade --version 1.0.0-BETA-3.01
```

### Backup Rollback

Restore the exact pre-upgrade backup created by the upgrade utility.

This is the safer emergency path when the previous runtime state matters, not just the code version.

The panel UI exposes this as:

- `Rollback To Backup`

## When To Cut A New Release Instead Of Reusing One

Cut a new release when:

- code changed after a tag was published
- docs changed in a way that affects operators
- installer or upgrade behavior changed
- updater behavior changed
- live hotfixes had to be pulled back into source control

Do not reuse or rewrite an existing published release tag for these cases.

## Current Practical Policy

Until development moves fully off `main`, treat `main` as:

- stable enough for public testing
- not safe for direct live patch deployment
- the source for the next tagged release

The moment a fix matters to live systems, it should become:

1. a commit
2. a tagged release
3. an upgrade path

## Related Documents

- [README.md](../README.md)
- [docs/DEPLOYMENT-POLICY.md](DEPLOYMENT-POLICY.md)
- [docs/RELEASE-UPGRADE-WORKFLOW.md](RELEASE-UPGRADE-WORKFLOW.md)
- [docs/UPGRADING.md](UPGRADING.md)


