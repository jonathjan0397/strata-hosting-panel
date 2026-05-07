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
HOSTING_STORAGE_ROOT='$(storage_root_after_rollback "${HOSTING_BACKUP:-}" "$HOSTING_SOURCE" "$HOSTING_TARGET")'
HOSTING_BIND_TARGET='${HOSTING_SOURCE}'
BACKUP_STORAGE_ROOT='$(storage_root_after_rollback "${BACKUP_BACKUP:-}" "$BACKUP_SOURCE" "$BACKUP_TARGET")'
BACKUP_BIND_TARGET='${BACKUP_SOURCE}'
MAIL_STORAGE_ROOT='$(storage_root_after_rollback "${MAIL_BACKUP:-}" "$MAIL_SOURCE" "$MAIL_TARGET")'
MAIL_BIND_TARGET='${MAIL_SOURCE}'
MYSQL_STORAGE_ROOT='$(storage_root_after_rollback "${MYSQL_BACKUP:-}" "$MYSQL_SOURCE" "$MYSQL_TARGET")'
MYSQL_BIND_TARGET='${MYSQL_SOURCE}'
POSTGRES_STORAGE_ROOT='$(storage_root_after_rollback "${POSTGRES_BACKUP:-}" "$POSTGRES_SOURCE" "$POSTGRES_TARGET")'
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

    upsert_install_env_value STRATA_HOSTING_STORAGE_ROOT "$(storage_root_after_rollback "${HOSTING_BACKUP:-}" "$HOSTING_SOURCE" "$HOSTING_TARGET")"
    upsert_install_env_value STRATA_BACKUP_STORAGE_ROOT "$(storage_root_after_rollback "${BACKUP_BACKUP:-}" "$BACKUP_SOURCE" "$BACKUP_TARGET")"
    upsert_install_env_value STRATA_MAIL_STORAGE_ROOT "$(storage_root_after_rollback "${MAIL_BACKUP:-}" "$MAIL_SOURCE" "$MAIL_TARGET")"
    upsert_install_env_value STRATA_MYSQL_STORAGE_ROOT "$(storage_root_after_rollback "${MYSQL_BACKUP:-}" "$MYSQL_SOURCE" "$MYSQL_TARGET")"
    upsert_install_env_value STRATA_POSTGRES_STORAGE_ROOT "$(storage_root_after_rollback "${POSTGRES_BACKUP:-}" "$POSTGRES_SOURCE" "$POSTGRES_TARGET")"
    chmod 600 "$INSTALL_ENV_PATH"
    info "Updated ${INSTALL_ENV_PATH}"
}

storage_root_after_rollback() {
    local backup_path="$1"
    local source_path="$2"
    local target_path="$3"

    if [[ -n "$backup_path" ]]; then
        printf '%s\n' "$source_path"
    else
        printf '%s\n' "$target_path"
    fi
}

restore_if_selected() {
    local source_path="$1"
    local backup_path="$2"

    [[ -n "$backup_path" ]] || return 0
    restore_path "$source_path" "$backup_path"
}

remove_fstab_label_if_selected() {
    local label="$1"
    local backup_path="$2"

    [[ -n "$backup_path" ]] || return 0
    grep -vF "# ${label}" /etc/fstab > /etc/fstab.strata.rollback.tmp 2>/dev/null || true
    if [[ -f /etc/fstab.strata.rollback.tmp ]]; then
        mv /etc/fstab.strata.rollback.tmp /etc/fstab
    fi
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

remove_fstab_label_if_selected 'strata-hosting-storage' "${HOSTING_BACKUP:-}"
remove_fstab_label_if_selected 'strata-backup-storage' "${BACKUP_BACKUP:-}"
remove_fstab_label_if_selected 'strata-mail-storage' "${MAIL_BACKUP:-}"
remove_fstab_label_if_selected 'strata-mysql-storage' "${MYSQL_BACKUP:-}"
remove_fstab_label_if_selected 'strata-postgresql-storage' "${POSTGRES_BACKUP:-}"

restore_if_selected "$HOSTING_SOURCE" "${HOSTING_BACKUP:-}"
restore_if_selected "$BACKUP_SOURCE" "${BACKUP_BACKUP:-}"
restore_if_selected "$MAIL_SOURCE" "${MAIL_BACKUP:-}"
restore_if_selected "$MYSQL_SOURCE" "${MYSQL_BACKUP:-}"
restore_if_selected "$POSTGRES_SOURCE" "${POSTGRES_BACKUP:-}"

restore_panel_storage_config
restore_agent_install_env

for service in "${SERVICES[@]}"; do
    start_if_present "$service"
done

info "Rollback complete."
