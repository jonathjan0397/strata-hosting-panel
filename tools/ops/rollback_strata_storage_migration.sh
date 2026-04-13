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

restore_path() {
    local source_path="$1"
    local backup_path="$2"

    [[ -d "$backup_path" ]] || die "Missing rollback backup directory: $backup_path"

    if mountpoint -q "$source_path"; then
        umount "$source_path"
    fi

    rm -rf "$source_path"
    mv "$backup_path" "$source_path"
}

restore_panel_storage_config() {
    local config_path="/etc/strata-panel/storage.conf"
    [[ -f "$config_path" ]] || return 0

    cat > "$config_path" <<EOF
HOSTING_STORAGE_ROOT='${HOSTING_SOURCE}'
HOSTING_BIND_TARGET='${HOSTING_SOURCE}'
BACKUP_STORAGE_ROOT='${BACKUP_SOURCE}'
BACKUP_BIND_TARGET='${BACKUP_SOURCE}'
MAIL_STORAGE_ROOT='${MAIL_SOURCE}'
MAIL_BIND_TARGET='${MAIL_SOURCE}'
MYSQL_STORAGE_ROOT='${MYSQL_SOURCE}'
MYSQL_BIND_TARGET='${MYSQL_SOURCE}'
POSTGRES_STORAGE_ROOT='${POSTGRES_SOURCE}'
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

restore_agent_install_env() {
    INSTALL_ENV_PATH="/etc/strata-agent/install.env"
    [[ -f "$INSTALL_ENV_PATH" ]] || return 0

    upsert_install_env_value STRATA_HOSTING_STORAGE_ROOT "$HOSTING_SOURCE"
    upsert_install_env_value STRATA_BACKUP_STORAGE_ROOT "$BACKUP_SOURCE"
    upsert_install_env_value STRATA_MAIL_STORAGE_ROOT "$MAIL_SOURCE"
    upsert_install_env_value STRATA_MYSQL_STORAGE_ROOT "$MYSQL_SOURCE"
    upsert_install_env_value STRATA_POSTGRES_STORAGE_ROOT "$POSTGRES_SOURCE"
    chmod 600 "$INSTALL_ENV_PATH"
    info "Updated ${INSTALL_ENV_PATH}"
}

require_root

ROLLBACK_ENV="${1:-}"
[[ -n "$ROLLBACK_ENV" && -f "$ROLLBACK_ENV" ]] || die "Usage: $0 /root/strata-storage-migration-<timestamp>.env"

# shellcheck disable=SC1090
source "$ROLLBACK_ENV"

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

grep -vF '# strata-hosting-storage' /etc/fstab \
    | grep -vF '# strata-backup-storage' \
    | grep -vF '# strata-mail-storage' \
    | grep -vF '# strata-mysql-storage' \
    | grep -vF '# strata-postgresql-storage' \
    > /etc/fstab.strata.rollback.tmp 2>/dev/null || true
if [[ -f /etc/fstab.strata.rollback.tmp ]]; then
    mv /etc/fstab.strata.rollback.tmp /etc/fstab
fi

restore_path "$HOSTING_SOURCE" "$HOSTING_BACKUP"
restore_path "$BACKUP_SOURCE" "$BACKUP_BACKUP"
restore_path "$MAIL_SOURCE" "$MAIL_BACKUP"
restore_path "$MYSQL_SOURCE" "$MYSQL_BACKUP"
restore_path "$POSTGRES_SOURCE" "$POSTGRES_BACKUP"

restore_panel_storage_config
restore_agent_install_env

for service in "${SERVICES[@]}"; do
    start_if_present "$service"
done

info "Rollback complete."
