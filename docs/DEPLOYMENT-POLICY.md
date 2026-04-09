# Deployment Policy

Strata should no longer be deployed by pushing directly to `main` and patching live servers manually.

## Policy

1. `main` is the integration branch, not the deployment target.
2. Production and test server changes must ship through the Strata upgrade system.
3. Release tags are the deployment unit.
4. Live hotfixes are only for incident recovery, and every hotfix must be pulled back into source control immediately.
5. Panel, agent, installer, migrations, and built assets must be versioned together as one release.

## Required Git Workflow

1. Develop on a feature or fix branch.
2. Open a pull request into `main`.
3. Run validation before merge:
   - PHP lint
   - Laravel boot / artisan checks
   - frontend build
   - installer / upgrade validation where applicable
4. Merge into `main`.
5. Cut a release tag such as `v1.0.0-alpha.4`.
6. Deploy that tag through `/root/strata-upgrade.sh --version <tag>`.

## Branch Protection Recommendations

Enable these GitHub protections on `main`:

- require pull requests before merge
- require status checks before merge
- block force pushes
- block branch deletion
- require linear history if desired
- restrict who can push directly

## What Must Be In A Release

Each release should include:

- panel source changes
- agent changes
- installer / upgrade changes
- database migrations
- prebuilt frontend assets
- release notes / changelog

If any of those pieces are missing, the release is incomplete.

## What Must Not Be The Normal Path

These should not be the standard deployment path:

- SSH patching files directly on live nodes
- rebuilding panel assets manually on customer servers unless unavoidable
- rebuilding agent binaries ad hoc on customer servers unless unavoidable
- deploying untagged `main` changes to live systems

## Acceptable Emergency Use

A live patch is acceptable only when:

- there is an active outage or severe regression
- the upgrade system cannot yet carry the fix quickly enough
- the exact fix is committed back into the repo immediately after the incident
- the next formal release includes the fix

Emergency work should still be treated as temporary incident response, not a deployment model.

## Operational Standard Going Forward

For normal releases:

1. merge to `main`
2. tag release
3. publish release artifacts
4. upgrade the primary through the upgrade utility
5. let the upgrade path handle remote nodes
6. run post-upgrade health checks

That is the baseline process Strata should follow from here forward.
