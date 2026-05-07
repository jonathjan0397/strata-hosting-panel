#!/usr/bin/env bash
set -euo pipefail

BACKUP_ROOT="${1:-/opt/strata-panel-backups}"
KEEP_COUNT="${2:-5}"

if [[ ! -d "$BACKUP_ROOT" ]]; then
    exit 0
fi

mapfile -t backups < <(ls -1dt "$BACKUP_ROOT"/* 2>/dev/null || true)

if (( ${#backups[@]} > KEEP_COUNT )); then
    for backup in "${backups[@]:KEEP_COUNT}"; do
        rm -rf -- "$backup"
    done
fi

ls -1dt "$BACKUP_ROOT"/* 2>/dev/null || true

