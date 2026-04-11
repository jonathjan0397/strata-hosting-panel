#!/usr/bin/env bash
set -euo pipefail

info() { printf '[info] %s\n' "$*"; }
warn() { printf '[warn] %s\n' "$*" >&2; }
die() { printf '[fail] %s\n' "$*" >&2; exit 1; }

require_root() {
    [[ ${EUID:-0} -eq 0 ]] || die "Run as root."
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

escape_fstab() {
    printf '%s' "$1" | sed 's/[.[\*^$(){}?+|/]/\\&/g'
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

ensure_fstab_bind() {
    local source_path="$1"
    local target_path="$2"
    local label="$3"
    local escaped_source escaped_target

    escaped_source="$(escape_fstab "$source_path")"
    escaped_target="$(escape_fstab "$target_path")"

    grep -qE "^[[:space:]]*${escaped_source}[[:space:]]+${escaped_target}[[:space:]]+none[[:space:]]+bind" /etc/fstab 2>/dev/null \
        || printf '%s %s none bind 0 0 # %s\n' "$source_path" "$target_path" "$label" >> /etc/fstab
}

require_root
require_cmd rsync
require_cmd mount
require_cmd findmnt

HOSTING_SOURCE="${HOSTING_SOURCE:-/var/www}"
HOSTING_TARGET="${HOSTING_TARGET:-/srv/strata/www}"
BACKUP_SOURCE="${BACKUP_SOURCE:-/var/backups/strata}"
BACKUP_TARGET="${BACKUP_TARGET:-/srv/strata/backups}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ROLLBACK_LOG="${ROLLBACK_LOG:-/root/strata-storage-migration-${TIMESTAMP}.env}"

[[ -d "$HOSTING_SOURCE" ]] || die "Hosting source path not found: $HOSTING_SOURCE"
mkdir -p "$BACKUP_SOURCE"
mkdir -p "$HOSTING_TARGET" "$BACKUP_TARGET"

cat > "$ROLLBACK_LOG" <<EOF
HOSTING_SOURCE='${HOSTING_SOURCE}'
HOSTING_TARGET='${HOSTING_TARGET}'
BACKUP_SOURCE='${BACKUP_SOURCE}'
BACKUP_TARGET='${BACKUP_TARGET}'
HOSTING_BACKUP='${HOSTING_SOURCE}.pre-strata-storage-migration.${TIMESTAMP}'
BACKUP_BACKUP='${BACKUP_SOURCE}.pre-strata-storage-migration.${TIMESTAMP}'
TIMESTAMP='${TIMESTAMP}'
EOF
chmod 600 "$ROLLBACK_LOG"

info "Rollback metadata saved to ${ROLLBACK_LOG}"
info "Initial sync to target storage..."
rsync -aHAX --numeric-ids "${HOSTING_SOURCE}/" "${HOSTING_TARGET}/"
rsync -aHAX --numeric-ids "${BACKUP_SOURCE}/" "${BACKUP_TARGET}/"

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

info "Final sync with services stopped..."
rsync -aHAX --delete --numeric-ids "${HOSTING_SOURCE}/" "${HOSTING_TARGET}/"
rsync -aHAX --delete --numeric-ids "${BACKUP_SOURCE}/" "${BACKUP_TARGET}/"

HOSTING_BACKUP="${HOSTING_SOURCE}.pre-strata-storage-migration.${TIMESTAMP}"
BACKUP_BACKUP="${BACKUP_SOURCE}.pre-strata-storage-migration.${TIMESTAMP}"

if mountpoint -q "$HOSTING_SOURCE"; then
    umount "$HOSTING_SOURCE"
fi
if mountpoint -q "$BACKUP_SOURCE"; then
    umount "$BACKUP_SOURCE"
fi

mv "$HOSTING_SOURCE" "$HOSTING_BACKUP"
mv "$BACKUP_SOURCE" "$BACKUP_BACKUP"
mkdir -p "$HOSTING_SOURCE" "$BACKUP_SOURCE"

info "Applying bind mounts..."
mount --bind "$HOSTING_TARGET" "$HOSTING_SOURCE"
mount --bind "$BACKUP_TARGET" "$BACKUP_SOURCE"
ensure_fstab_bind "$HOSTING_TARGET" "$HOSTING_SOURCE" "strata-hosting-storage"
ensure_fstab_bind "$BACKUP_TARGET" "$BACKUP_SOURCE" "strata-backup-storage"

for service in "${SERVICES[@]}"; do
    start_if_present "$service"
done

info "Migration complete."
printf 'findmnt %s -> %s\n' "$HOSTING_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$HOSTING_SOURCE" 2>/dev/null || true)"
printf 'findmnt %s -> %s\n' "$BACKUP_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$BACKUP_SOURCE" 2>/dev/null || true)"
printf 'Original directories kept at:\n  %s\n  %s\n' "$HOSTING_BACKUP" "$BACKUP_BACKUP"

