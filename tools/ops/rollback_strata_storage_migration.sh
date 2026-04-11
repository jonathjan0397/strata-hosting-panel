#!/usr/bin/env bash
set -euo pipefail

info() { printf '[info] %s\n' "$*"; }
die() { printf '[fail] %s\n' "$*" >&2; exit 1; }

require_root() {
    [[ ${EUID:-0} -eq 0 ]] || die "Run as root."
}

stop_if_present() {
    local service="$1"
    if systemctl list-unit-files "$service" >/dev/null 2>&1; then
        info "Stopping ${service}..."
        systemctl stop "$service" >/dev/null 2>&1 || true
    fi
}

start_if_present() {
    local service="$1"
    if systemctl list-unit-files "$service" >/dev/null 2>&1; then
        info "Starting ${service}..."
        systemctl start "$service" >/dev/null 2>&1 || true
    fi
}

require_root

ROLLBACK_ENV="${1:-}"
[[ -n "$ROLLBACK_ENV" && -f "$ROLLBACK_ENV" ]] || die "Usage: $0 /root/strata-storage-migration-<timestamp>.env"

# shellcheck disable=SC1090
source "$ROLLBACK_ENV"

[[ -d "$HOSTING_BACKUP" ]] || die "Missing hosting backup directory: $HOSTING_BACKUP"
[[ -d "$BACKUP_BACKUP" ]] || die "Missing backup backup directory: $BACKUP_BACKUP"

SERVICES=(
    strata-queue.service
    strata-agent.service
    strata-webdav.service
    nginx.service
    apache2.service
    php8.1-fpm.service
    php8.2-fpm.service
    php8.3-fpm.service
    php8.4-fpm.service
    php8.5-fpm.service
    pure-ftpd.service
)

for service in "${SERVICES[@]}"; do
    stop_if_present "$service"
done

if mountpoint -q "$HOSTING_SOURCE"; then
    umount "$HOSTING_SOURCE"
fi
if mountpoint -q "$BACKUP_SOURCE"; then
    umount "$BACKUP_SOURCE"
fi

grep -vF '# strata-hosting-storage' /etc/fstab | grep -vF '# strata-backup-storage' > /etc/fstab.strata.rollback.tmp 2>/dev/null || true
if [[ -f /etc/fstab.strata.rollback.tmp ]]; then
    mv /etc/fstab.strata.rollback.tmp /etc/fstab
fi

rm -rf "$HOSTING_SOURCE" "$BACKUP_SOURCE"
mv "$HOSTING_BACKUP" "$HOSTING_SOURCE"
mv "$BACKUP_BACKUP" "$BACKUP_SOURCE"

for service in "${SERVICES[@]}"; do
    start_if_present "$service"
done

info "Rollback complete."

