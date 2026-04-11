# Ops Tools

This directory is for reusable operational scripts that are safe to keep in source control.

Rules:

- keep only generic, repeatable tooling here
- do not commit host-specific fixes, incident hotfix scripts, or credential-bearing helpers
- do not commit generated logs, tarballs, zip files, or JSON captures
- if a script targets a specific live server or incident, keep it out of source control

Current curated tools:

- `migrate_strata_storage.sh`
- `rollback_strata_storage_migration.sh`
- `prune_rollback_backups.sh`

