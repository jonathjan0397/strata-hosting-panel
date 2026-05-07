# Ops Tools

This directory is for reusable operational scripts that are safe to keep in source control.

Rules:

- keep only generic, repeatable tooling here
- do not commit host-specific fixes, incident hotfix scripts, or credential-bearing helpers
- do not commit generated logs, tarballs, zip files, or JSON captures
- if a script targets a specific live server or incident, keep it out of source control

Current curated tools:

- `migrate_strata_storage.sh`
  Migrates `/var/www`, `/var/backups/strata`, `/var/mail`, `/var/lib/mysql`, and `/var/lib/postgresql` onto new bind-mounted storage roots and updates persisted Strata storage config where present.
- `rollback_strata_storage_migration.sh`
  Restores the original directories from the rollback env file written by the migration script.
- `prune_rollback_backups.sh`
