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

update_panel_storage_config() {
    local config_path="/etc/strata-panel/storage.conf"
    [[ -d /etc/strata-panel ]] || return 0

    cat > "$config_path" <<EOF
HOSTING_STORAGE_ROOT='${HOSTING_TARGET}'
HOSTING_BIND_TARGET='${HOSTING_SOURCE}'
BACKUP_STORAGE_ROOT='${BACKUP_TARGET}'
BACKUP_BIND_TARGET='${BACKUP_SOURCE}'
MAIL_STORAGE_ROOT='${MAIL_TARGET}'
MAIL_BIND_TARGET='${MAIL_SOURCE}'
MYSQL_STORAGE_ROOT='${MYSQL_TARGET}'
MYSQL_BIND_TARGET='${MYSQL_SOURCE}'
POSTGRES_STORAGE_ROOT='${POSTGRES_TARGET}'
POSTGRES_BIND_TARGET='${POSTGRES_SOURCE}'
EOF
    chmod 600 "$config_path"
    info "Updated ${config_path}"
}

upsert_install_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$INSTALL_ENV_PATH" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}='${value}'|" "$INSTALL_ENV_PATH"
    else
        printf "%s='%s'\n" "$key" "$value" >> "$INSTALL_ENV_PATH"
    fi
}

update_agent_install_env() {
    INSTALL_ENV_PATH="/etc/strata-agent/install.env"
    mkdir -p /etc/strata-agent
    touch "$INSTALL_ENV_PATH"
    chmod 600 "$INSTALL_ENV_PATH"

    upsert_install_env_value STRATA_HOSTING_STORAGE_ROOT "$HOSTING_TARGET"
    upsert_install_env_value STRATA_BACKUP_STORAGE_ROOT "$BACKUP_TARGET"
    upsert_install_env_value STRATA_MAIL_STORAGE_ROOT "$MAIL_TARGET"
    upsert_install_env_value STRATA_MYSQL_STORAGE_ROOT "$MYSQL_TARGET"
    upsert_install_env_value STRATA_POSTGRES_STORAGE_ROOT "$POSTGRES_TARGET"
    info "Updated ${INSTALL_ENV_PATH}"
}

is_selected_item() {
    local item="$1"
    [[ "${MIGRATION_ITEM}" == "all" || "${MIGRATION_ITEM}" == "$item" ]]
}

sync_path() {
    local source_path="$1"
    local target_path="$2"
    rsync -aHAX --numeric-ids "${source_path}/" "${target_path}/"
}

final_sync_path() {
    local source_path="$1"
    local target_path="$2"
    rsync -aHAX --delete --numeric-ids "${source_path}/" "${target_path}/"
}

cut_over_path() {
    local source_path="$1"
    local target_path="$2"
    local label="$3"
    local backup_var_name="$4"
    local fstab_label="$5"
    local backup_path="${source_path}.pre-strata-storage-migration.${TIMESTAMP}"

    printf -v "$backup_var_name" '%s' "$backup_path"

    if mountpoint -q "$source_path"; then
        umount "$source_path"
    fi

    mv "$source_path" "$backup_path"
    mkdir -p "$source_path"

    info "Applying bind mount for ${label}..."
    mount --bind "$target_path" "$source_path"
    ensure_fstab_bind "$target_path" "$source_path" "$fstab_label"
}

require_root
require_cmd rsync
require_cmd mount
require_cmd umount
require_cmd findmnt

HOSTING_SOURCE="${HOSTING_SOURCE:-/var/www}"
HOSTING_TARGET="${HOSTING_TARGET:-/srv/strata/www}"
BACKUP_SOURCE="${BACKUP_SOURCE:-/var/backups/strata}"
BACKUP_TARGET="${BACKUP_TARGET:-/srv/strata/backups}"
MAIL_SOURCE="${MAIL_SOURCE:-/var/mail}"
MAIL_TARGET="${MAIL_TARGET:-/srv/strata/mail}"
MYSQL_SOURCE="${MYSQL_SOURCE:-/var/lib/mysql}"
MYSQL_TARGET="${MYSQL_TARGET:-/srv/strata/mysql}"
POSTGRES_SOURCE="${POSTGRES_SOURCE:-/var/lib/postgresql}"
POSTGRES_TARGET="${POSTGRES_TARGET:-/srv/strata/postgresql}"
MIGRATION_ITEM="${MIGRATION_ITEM:-all}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ROLLBACK_LOG="${ROLLBACK_LOG:-/root/strata-storage-migration-${TIMESTAMP}.env}"

case "$MIGRATION_ITEM" in
    all|hosting|backups|mail|mysql|postgresql) ;;
    *) die "Unsupported MIGRATION_ITEM: ${MIGRATION_ITEM}" ;;
esac

[[ -d "$HOSTING_SOURCE" ]] || die "Hosting source path not found: $HOSTING_SOURCE"
mkdir -p "$BACKUP_SOURCE" "$MAIL_SOURCE" "$MYSQL_SOURCE" "$POSTGRES_SOURCE"
mkdir -p "$HOSTING_TARGET" "$BACKUP_TARGET" "$MAIL_TARGET" "$MYSQL_TARGET" "$POSTGRES_TARGET"

cat > "$ROLLBACK_LOG" <<EOF
HOSTING_SOURCE='${HOSTING_SOURCE}'
HOSTING_TARGET='${HOSTING_TARGET}'
BACKUP_SOURCE='${BACKUP_SOURCE}'
BACKUP_TARGET='${BACKUP_TARGET}'
MAIL_SOURCE='${MAIL_SOURCE}'
MAIL_TARGET='${MAIL_TARGET}'
MYSQL_SOURCE='${MYSQL_SOURCE}'
MYSQL_TARGET='${MYSQL_TARGET}'
POSTGRES_SOURCE='${POSTGRES_SOURCE}'
POSTGRES_TARGET='${POSTGRES_TARGET}'
TIMESTAMP='${TIMESTAMP}'
EOF
chmod 600 "$ROLLBACK_LOG"

info "Rollback metadata saved to ${ROLLBACK_LOG}"
info "Starting storage migration for item: ${MIGRATION_ITEM}"
info "Initial sync to target storage..."
is_selected_item hosting && sync_path "$HOSTING_SOURCE" "$HOSTING_TARGET"
is_selected_item backups && sync_path "$BACKUP_SOURCE" "$BACKUP_TARGET"
is_selected_item mail && sync_path "$MAIL_SOURCE" "$MAIL_TARGET"
is_selected_item mysql && sync_path "$MYSQL_SOURCE" "$MYSQL_TARGET"
is_selected_item postgresql && sync_path "$POSTGRES_SOURCE" "$POSTGRES_TARGET"

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
    mariadb.service
    mysql.service
    postgresql.service
    postfix.service
    dovecot.service
    rspamd.service
    opendkim.service
    pure-ftpd.service
)

for service in "${SERVICES[@]}"; do
    stop_if_present "$service"
done

info "Final sync with services stopped..."
is_selected_item hosting && final_sync_path "$HOSTING_SOURCE" "$HOSTING_TARGET"
is_selected_item backups && final_sync_path "$BACKUP_SOURCE" "$BACKUP_TARGET"
is_selected_item mail && final_sync_path "$MAIL_SOURCE" "$MAIL_TARGET"
is_selected_item mysql && final_sync_path "$MYSQL_SOURCE" "$MYSQL_TARGET"
is_selected_item postgresql && final_sync_path "$POSTGRES_SOURCE" "$POSTGRES_TARGET"

is_selected_item hosting && cut_over_path "$HOSTING_SOURCE" "$HOSTING_TARGET" "hosting data" HOSTING_BACKUP "strata-hosting-storage"
is_selected_item backups && cut_over_path "$BACKUP_SOURCE" "$BACKUP_TARGET" "backup data" BACKUP_BACKUP "strata-backup-storage"
is_selected_item mail && cut_over_path "$MAIL_SOURCE" "$MAIL_TARGET" "mail data" MAIL_BACKUP "strata-mail-storage"
is_selected_item mysql && cut_over_path "$MYSQL_SOURCE" "$MYSQL_TARGET" "MariaDB data" MYSQL_BACKUP "strata-mysql-storage"
is_selected_item postgresql && cut_over_path "$POSTGRES_SOURCE" "$POSTGRES_TARGET" "PostgreSQL data" POSTGRES_BACKUP "strata-postgresql-storage"

cat >> "$ROLLBACK_LOG" <<EOF
HOSTING_BACKUP='${HOSTING_BACKUP}'
BACKUP_BACKUP='${BACKUP_BACKUP}'
MAIL_BACKUP='${MAIL_BACKUP}'
MYSQL_BACKUP='${MYSQL_BACKUP}'
POSTGRES_BACKUP='${POSTGRES_BACKUP}'
EOF

update_panel_storage_config
update_agent_install_env

for service in "${SERVICES[@]}"; do
    start_if_present "$service"
done

info "Migration complete for item: ${MIGRATION_ITEM}"
is_selected_item hosting && printf 'findmnt %s -> %s\n' "$HOSTING_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$HOSTING_SOURCE" 2>/dev/null || true)"
is_selected_item backups && printf 'findmnt %s -> %s\n' "$BACKUP_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$BACKUP_SOURCE" 2>/dev/null || true)"
is_selected_item mail && printf 'findmnt %s -> %s\n' "$MAIL_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$MAIL_SOURCE" 2>/dev/null || true)"
is_selected_item mysql && printf 'findmnt %s -> %s\n' "$MYSQL_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$MYSQL_SOURCE" 2>/dev/null || true)"
is_selected_item postgresql && printf 'findmnt %s -> %s\n' "$POSTGRES_SOURCE" "$(findmnt -n -o SOURCE,TARGET "$POSTGRES_SOURCE" 2>/dev/null || true)"
printf 'Original directories kept at:\n'
is_selected_item hosting && printf '  %s\n' "$HOSTING_BACKUP"
is_selected_item backups && printf '  %s\n' "$BACKUP_BACKUP"
is_selected_item mail && printf '  %s\n' "$MAIL_BACKUP"
is_selected_item mysql && printf '  %s\n' "$MYSQL_BACKUP"
is_selected_item postgresql && printf '  %s\n' "$POSTGRES_BACKUP"
