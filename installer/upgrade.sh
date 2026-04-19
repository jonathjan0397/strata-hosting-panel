#!/usr/bin/env bash
set -Eeuo pipefail

REPO="jonathjan0397/strata-hosting-panel"
INSTALL_DIR="/opt/strata-panel"
BACKUP_ROOT="/opt/strata-panel-backups"
PANEL_USER="${PANEL_USER:-strata}"
PANEL_GROUP="${PANEL_GROUP:-www-data}"
PHP_BIN="${PHP_BIN:-}"
export PATH="/usr/local/go/bin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
SOURCE_VERSION=""
SOURCE_FILE=""
SOURCE_BRANCH=""
SOURCE_CHANNEL=""
SOURCE_ROLLBACK_BACKUP=""
LIST_BACKUPS=0
ROLLBACK_ON_FAIL=1
KEEP_WORKDIR=0
SKIP_REMOTE_AGENTS=0
ROLLBACK_RETENTION="${ROLLBACK_RETENTION:-5}"
BACKUP_DIR=""
OLD_AGENT=""
OLD_WEBDAV=""
WORKDIR=""
FAILED=0

NC=$'\033[0m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
RED=$'\033[0;31m'
BOLD=$'\033[1m'

info() { echo "${BOLD}[*]${NC} $*"; }
success() { echo "${GREEN}[OK]${NC} $*"; }
warn() { echo "${YELLOW}[WARN]${NC} $*"; }
die() { echo "${RED}[ERR]${NC} $*" >&2; exit 1; }

PANEL_FILE_UPLOAD_LIMIT_MB=512
PANEL_REQUEST_BODY_LIMIT_MB=1024
PANEL_REQUEST_BODY_LIMIT_BYTES=1073741824

ensure_sudo_installed() {
    if command -v sudo >/dev/null 2>&1; then
        return
    fi

    info "Installing sudo..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y sudo
}

usage() {
    cat <<EOF
Strata Hosting Panel upgrade utility

Usage:
  $0 --version v1.0.0-beta.2
  $0 --channel main
  $0 --branch main
  $0 --file /root/strata-hosting-panel.tar.gz
  $0 --rollback-backup 20260409-120000
  $0 --list-backups

Options:
  --version <tag>      Download and install a GitHub release/tag archive.
  --channel <name>     Install from a supported update channel:
                       main, latest-untested, experimental.
  --branch <branch>    Download and install a GitHub branch archive.
  --file <path>        Install from a local .tar.gz/.tgz/.tar archive.
  --rollback-backup <name>
                       Restore a previously created backup from /opt/strata-panel-backups.
  --list-backups       Print available rollback backups as JSON.
  --install-dir <dir>  Override install path. Default: /opt/strata-panel
  --php-bin <path>     Override PHP binary. Auto-detected by default.
  --no-rollback        Do not restore the previous install on failure.
  --keep-workdir       Keep temporary extracted source for debugging.
  --skip-remote-agents Do not auto-queue remote node agent upgrades after primary upgrade.
  -h, --help           Show this help.
EOF
}

resolve_channel_branch() {
    case "$1" in
        main) echo "main" ;;
        latest-untested) echo "latest-untested" ;;
        experimental) echo "experimental" ;;
        *) die "Unknown update channel: $1. Supported channels: main, latest-untested, experimental." ;;
    esac
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version) SOURCE_VERSION="${2:-}"; shift 2 ;;
        --channel) SOURCE_CHANNEL="${2:-}"; shift 2 ;;
        --branch) SOURCE_BRANCH="${2:-}"; shift 2 ;;
        --file) SOURCE_FILE="${2:-}"; shift 2 ;;
        --rollback-backup) SOURCE_ROLLBACK_BACKUP="${2:-}"; shift 2 ;;
        --list-backups) LIST_BACKUPS=1; shift ;;
        --install-dir) INSTALL_DIR="${2:-}"; shift 2 ;;
        --php-bin) PHP_BIN="${2:-}"; shift 2 ;;
        --no-rollback) ROLLBACK_ON_FAIL=0; shift ;;
        --keep-workdir) KEEP_WORKDIR=1; shift ;;
        --skip-remote-agents) SKIP_REMOTE_AGENTS=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done

[[ $EUID -eq 0 ]] || die "Run as root."

source_count=0
[[ -n "$SOURCE_VERSION" ]] && ((source_count+=1))
[[ -n "$SOURCE_CHANNEL" ]] && ((source_count+=1))
[[ -n "$SOURCE_BRANCH" ]] && ((source_count+=1))
[[ -n "$SOURCE_FILE" ]] && ((source_count+=1))
[[ -n "$SOURCE_ROLLBACK_BACKUP" ]] && ((source_count+=1))
if [[ $LIST_BACKUPS -eq 1 ]]; then
    [[ $source_count -eq 0 ]] || die "--list-backups cannot be combined with another source option."
else
    [[ $source_count -eq 1 ]] || die "Choose exactly one source: --version, --channel, --branch, --file, or --rollback-backup."
fi

[[ -d "$INSTALL_DIR/panel" ]] || die "Panel install not found at $INSTALL_DIR/panel."
[[ -f "$INSTALL_DIR/panel/.env" ]] || die "Panel .env not found at $INSTALL_DIR/panel/.env."
ensure_sudo_installed

detect_php() {
    if [[ -n "$PHP_BIN" ]]; then
        [[ -x "$PHP_BIN" ]] || die "PHP binary is not executable: $PHP_BIN"
        return
    fi

    if [[ -x /usr/bin/php8.4 ]]; then
        PHP_BIN=/usr/bin/php8.4
    elif command -v php >/dev/null 2>&1; then
        PHP_BIN="$(command -v php)"
    else
        die "Could not find PHP. Pass --php-bin /usr/bin/php8.4."
    fi
}

need_command() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

detect_goarch() {
    case "$(uname -m)" in
        x86_64|amd64) echo "amd64" ;;
        aarch64|arm64) echo "arm64" ;;
        armv7l) echo "arm" ;;
        *) die "Unsupported architecture: $(uname -m)" ;;
    esac
}

json_escape() {
    local value="${1:-}"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//$'\n'/\\n}"
    value="${value//$'\r'/\\r}"
    value="${value//$'\t'/\\t}"
    printf '%s' "$value"
}

current_installed_version() {
    if [[ -f "$INSTALL_DIR/VERSION" ]]; then
        cat "$INSTALL_DIR/VERSION"
    else
        echo "unknown"
    fi
}

validate_binary() {
    local path="$1"

    [[ -s "$path" ]] || die "Binary validation failed: $path is empty."
    file "$path" | grep -Eq 'ELF .* executable' || die "Binary validation failed: $path is not a native executable."
}

install_storage_migration_tools() {
    local repo_root="$1"

    if [[ -f "$repo_root/tools/ops/migrate_strata_storage.sh" ]]; then
        install -m 755 "$repo_root/tools/ops/migrate_strata_storage.sh" /usr/sbin/strata-storage-migrate
    fi
    if [[ -f "$repo_root/tools/ops/rollback_strata_storage_migration.sh" ]]; then
        install -m 755 "$repo_root/tools/ops/rollback_strata_storage_migration.sh" /usr/sbin/strata-storage-migrate-rollback
    fi
}

ensure_bind_mount() {
    local source_path="$1"
    local target_path="$2"
    local fstab_label="$3"

    [[ -n "$source_path" && -n "$target_path" ]] || return
    mkdir -p "$source_path" "$target_path"

    if [[ "$source_path" == "$target_path" ]]; then
        return
    fi

    if ! mountpoint -q "$target_path"; then
        mount --bind "$source_path" "$target_path"
    fi

    local escaped_source escaped_target
    escaped_source=$(printf '%s' "$source_path" | sed 's/[.[\*^$(){}?+|/]/\\&/g')
    escaped_target=$(printf '%s' "$target_path" | sed 's/[.[\*^$(){}?+|/]/\\&/g')
    grep -qE "^[[:space:]]*${escaped_source}[[:space:]]+${escaped_target}[[:space:]]+none[[:space:]]+bind" /etc/fstab 2>/dev/null \
        || printf '%s %s none bind 0 0 # %s\n' "$source_path" "$target_path" "$fstab_label" >> /etc/fstab
}

storage_root_from_fstab_label() {
    local fstab_label="$1"
    awk -v label="$fstab_label" '$0 ~ ("# " label "$") { print $1; exit }' /etc/fstab 2>/dev/null || true
}

load_storage_config() {
    HOSTING_BIND_TARGET="/var/www"
    BACKUP_BIND_TARGET="/var/backups/strata"
    MAIL_BIND_TARGET="/var/mail"
    MYSQL_BIND_TARGET="/var/lib/mysql"
    POSTGRES_BIND_TARGET="/var/lib/postgresql"

    if [[ -f /etc/strata-panel/storage.conf ]]; then
        # shellcheck disable=SC1091
        source /etc/strata-panel/storage.conf
    fi

    HOSTING_STORAGE_ROOT="${HOSTING_STORAGE_ROOT:-$(storage_root_from_fstab_label strata-hosting-storage)}"
    BACKUP_STORAGE_ROOT="${BACKUP_STORAGE_ROOT:-$(storage_root_from_fstab_label strata-backup-storage)}"
    MAIL_STORAGE_ROOT="${MAIL_STORAGE_ROOT:-$(storage_root_from_fstab_label strata-mail-storage)}"
    MYSQL_STORAGE_ROOT="${MYSQL_STORAGE_ROOT:-$(storage_root_from_fstab_label strata-mysql-storage)}"
    POSTGRES_STORAGE_ROOT="${POSTGRES_STORAGE_ROOT:-$(storage_root_from_fstab_label strata-postgresql-storage)}"
}

write_storage_config() {
    mkdir -p /etc/strata-panel
    cat > /etc/strata-panel/storage.conf <<EOF
HOSTING_STORAGE_ROOT='${HOSTING_STORAGE_ROOT:-}'
HOSTING_BIND_TARGET='${HOSTING_BIND_TARGET:-/var/www}'
BACKUP_STORAGE_ROOT='${BACKUP_STORAGE_ROOT:-}'
BACKUP_BIND_TARGET='${BACKUP_BIND_TARGET:-/var/backups/strata}'
MAIL_STORAGE_ROOT='${MAIL_STORAGE_ROOT:-}'
MAIL_BIND_TARGET='${MAIL_BIND_TARGET:-/var/mail}'
MYSQL_STORAGE_ROOT='${MYSQL_STORAGE_ROOT:-}'
MYSQL_BIND_TARGET='${MYSQL_BIND_TARGET:-/var/lib/mysql}'
POSTGRES_STORAGE_ROOT='${POSTGRES_STORAGE_ROOT:-}'
POSTGRES_BIND_TARGET='${POSTGRES_BIND_TARGET:-/var/lib/postgresql}'
EOF
    chmod 600 /etc/strata-panel/storage.conf
}

repair_panel_upload_limits() {
    local panel_php_ver panel_ini
    panel_php_ver="$("$PHP_BIN" -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")"
    panel_ini="/etc/php/${panel_php_ver}/fpm/conf.d/99-strata-panel-upload.ini"

    if [[ -d "/etc/php/${panel_php_ver}/fpm/conf.d" ]]; then
        mkdir -p "/etc/php/${panel_php_ver}/fpm/conf.d"
        cat > "$panel_ini" <<EOF
; Strata Hosting Panel upload limits
upload_max_filesize=${PANEL_FILE_UPLOAD_LIMIT_MB}M
post_max_size=${PANEL_REQUEST_BODY_LIMIT_MB}M
max_file_uploads=20
EOF
    fi

    if [[ -f /etc/apache2/sites-available/strata-panel.conf ]] && grep -q 'LimitRequestBody' /etc/apache2/sites-available/strata-panel.conf; then
        sed -i "s/^[[:space:]]*LimitRequestBody .*/    LimitRequestBody ${PANEL_REQUEST_BODY_LIMIT_BYTES}/" /etc/apache2/sites-available/strata-panel.conf
    fi

    if [[ -f /etc/nginx/sites-available/strata-panel ]] && grep -q 'client_max_body_size' /etc/nginx/sites-available/strata-panel; then
        sed -i "s/^[[:space:]]*client_max_body_size .*/    client_max_body_size ${PANEL_REQUEST_BODY_LIMIT_MB}M;/" /etc/nginx/sites-available/strata-panel
    fi
}

reassert_storage_mounts() {
    if [[ -n "${HOSTING_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$HOSTING_STORAGE_ROOT" "${HOSTING_BIND_TARGET:-/var/www}" "strata-hosting-storage"
    fi
    if [[ -n "${BACKUP_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$BACKUP_STORAGE_ROOT" "${BACKUP_BIND_TARGET:-/var/backups/strata}" "strata-backup-storage"
    fi
    if [[ -n "${MAIL_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$MAIL_STORAGE_ROOT" "${MAIL_BIND_TARGET:-/var/mail}" "strata-mail-storage"
    fi
    if [[ -n "${MYSQL_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$MYSQL_STORAGE_ROOT" "${MYSQL_BIND_TARGET:-/var/lib/mysql}" "strata-mysql-storage"
    fi
    if [[ -n "${POSTGRES_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$POSTGRES_STORAGE_ROOT" "${POSTGRES_BIND_TARGET:-/var/lib/postgresql}" "strata-postgresql-storage"
    fi
}

set_permissions() {
    if id "$PANEL_USER" >/dev/null 2>&1; then
        chown -R "$PANEL_USER:$PANEL_GROUP" "$INSTALL_DIR/panel" 2>/dev/null || chown -R "$PANEL_USER:$PANEL_USER" "$INSTALL_DIR/panel"
    else
        warn "Panel user $PANEL_USER does not exist; leaving ownership unchanged."
    fi

    find "$INSTALL_DIR/panel" -type f -exec chmod 644 {} \;
    find "$INSTALL_DIR/panel" -type d -exec chmod 755 {} \;
    chmod 640 "$INSTALL_DIR/panel/.env"
    chmod -R ug+rwX "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel/bootstrap/cache"
}

repair_mail_permissions() {
    if id vmail >/dev/null 2>&1; then
        mkdir -p /var/mail /var/mail/vmail /var/mail/vhosts
        chown vmail:vmail /var/mail/vmail /var/mail/vhosts
        chmod 0750 /var/mail/vmail /var/mail/vhosts
        find /var/mail/vhosts -mindepth 1 -maxdepth 1 -type d -exec chown -R vmail:vmail {} \; -exec chmod 0750 {} \; 2>/dev/null || true
    fi
}

repair_fail2ban_defaults() {
    mkdir -p /etc/fail2ban/jail.d
    cat > /etc/fail2ban/jail.d/strata-defaults.local <<'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 10
backend = systemd

[sshd]
enabled = true

[postfix]
enabled = true

[postfix-sasl]
enabled = true

[dovecot]
enabled = true

[pure-ftpd]
enabled = true

[nginx-http-auth]
enabled = true

[apache-auth]
enabled = true

[recidive]
enabled = true
EOF
}

derive_parent_domain() {
    local panel_domain=""
    local app_url=""
    local host_candidate=""

    if [[ -f "$INSTALL_DIR/panel/.env" ]]; then
        panel_domain="$(grep -E '^PANEL_DOMAIN=' "$INSTALL_DIR/panel/.env" | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
        app_url="$(grep -E '^APP_URL=' "$INSTALL_DIR/panel/.env" | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    fi

    if [[ -n "$panel_domain" ]]; then
        host_candidate="$panel_domain"
    elif [[ -n "$app_url" ]]; then
        host_candidate="${app_url#*://}"
        host_candidate="${host_candidate%%/*}"
        host_candidate="${host_candidate%%:*}"
    else
        host_candidate="$(hostname -f 2>/dev/null || hostname)"
    fi

    if [[ "$host_candidate" == *.* ]]; then
        echo "${host_candidate#*.}"
    else
        echo "$host_candidate"
    fi
}

repair_powerdns_soa_defaults() {
    local parent_domain=""
    local soa_content=""
    local pdns_conf="/etc/powerdns/pdns.conf"

    [[ -f "$pdns_conf" ]] || return 0

    parent_domain="$(derive_parent_domain)"
    [[ -n "$parent_domain" ]] || return 0

    soa_content="ns1.${parent_domain} hostmaster.${parent_domain} 0 10800 3600 1209600 3600"

    sed -i '/^default-soa-name=/d;/^default-soa-mail=/d;/^default-soa-content=/d' "$pdns_conf"
    printf '\ndefault-soa-content=%s\n' "$soa_content" >> "$pdns_conf"

    systemctl enable pdns >/dev/null 2>&1 || true
    systemctl restart pdns >/dev/null 2>&1 || true
}

repair_mail_tls_defaults() {
    local parent_domain=""
    local mail_domain=""
    local mail_tls_dir="/etc/strata-panel/mail-tls"
    local dovecot_ssl="/etc/dovecot/conf.d/10-ssl.conf"

    [[ -f /etc/postfix/main.cf ]] || return 0

    parent_domain="$(derive_parent_domain)"
    [[ -n "$parent_domain" ]] || return 0
    mail_domain="mail.${parent_domain}"

    mkdir -p "$mail_tls_dir"
    if [[ ! -s "$mail_tls_dir/fullchain.pem" || ! -s "$mail_tls_dir/privkey.pem" ]]; then
        openssl req -x509 -newkey rsa:4096 \
            -keyout "${mail_tls_dir}/privkey.pem" \
            -out "${mail_tls_dir}/fullchain.pem" \
            -days 3650 -nodes \
            -subj "/CN=${mail_domain}" \
            -addext "subjectAltName=DNS:${mail_domain}" >/dev/null 2>&1 || true
        chmod 600 "${mail_tls_dir}/privkey.pem" 2>/dev/null || true
    fi

    postconf -e "smtpd_sasl_auth_enable = no" >/dev/null 2>&1 || true
    postconf -e "smtpd_tls_cert_file = ${mail_tls_dir}/fullchain.pem" >/dev/null 2>&1 || true
    postconf -e "smtpd_tls_key_file = ${mail_tls_dir}/privkey.pem" >/dev/null 2>&1 || true
    postconf -P "submission/inet/smtpd_sasl_auth_enable=yes" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/smtpd_sasl_auth_enable=yes" >/dev/null 2>&1 || true
    python3 - <<'PY' >/dev/null 2>&1 || true
from pathlib import Path

master_cf = Path('/etc/postfix/master.cf')
if not master_cf.exists():
    raise SystemExit(0)

text = master_cf.read_text()
begin = '# BEGIN STRATA DKIM REINJECT\n'
end = '# END STRATA DKIM REINJECT\n'
block = (
    begin +
    'dkim-reinject unix -       -       n       -       10      smtp\n'
    '  -o smtp_send_xforward_command=yes\n'
    '  -o disable_dns_lookups=yes\n'
    '127.0.0.1:10030 inet n    -       n       -       -       smtpd\n'
    '  -o content_filter=\n'
    '  -o receive_override_options=no_header_body_checks\n'
    '  -o smtpd_helo_restrictions=\n'
    '  -o smtpd_client_restrictions=permit_mynetworks,reject\n'
    '  -o smtpd_sender_restrictions=\n'
    '  -o smtpd_recipient_restrictions=permit_mynetworks,reject\n'
    '  -o smtpd_relay_restrictions=permit_mynetworks,reject\n'
    '  -o smtpd_authorized_xforward_hosts=127.0.0.0/8\n'
    '  -o mynetworks=127.0.0.0/8\n'
    '  -o local_recipient_maps=\n'
    '  -o relay_recipient_maps=\n'
    '  -o smtpd_milters=local:opendkim/opendkim.sock\n'
    '  -o non_smtpd_milters=\n'
    '  -o milter_macro_daemon_name=ORIGINATING\n'
    + end
)

if begin in text and end in text:
    start = text.index(begin)
    finish = text.index(end, start) + len(end)
    updated = text[:start] + block + text[finish:]
else:
    suffix = '' if text.endswith('\n') else '\n'
    updated = text + suffix + '\n' + block

if updated != text:
    master_cf.write_text(updated)
PY
    postconf -e "smtpd_milters =" >/dev/null 2>&1 || true
    postconf -e "non_smtpd_milters =" >/dev/null 2>&1 || true
    postconf -e "milter_default_action = accept" >/dev/null 2>&1 || true
    postconf -e "milter_protocol = 6" >/dev/null 2>&1 || true
    postconf -P "submission/inet/content_filter=dkim-reinject:[127.0.0.1]:10030" >/dev/null 2>&1 || true
    postconf -P "submission/inet/smtpd_milters=" >/dev/null 2>&1 || true
    postconf -P "submission/inet/non_smtpd_milters=" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/content_filter=dkim-reinject:[127.0.0.1]:10030" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/smtpd_milters=" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/non_smtpd_milters=" >/dev/null 2>&1 || true

    if [[ -d /etc/dovecot/conf.d ]]; then
        python3 - <<'PY' >/dev/null 2>&1 || true
from pathlib import Path
conf = Path('/etc/dovecot/conf.d/10-auth.conf')
if conf.exists():
    lines = conf.read_text().splitlines()
    updated = []
    found = False
    for line in lines:
        if line.strip().startswith('auth_mechanisms ='):
            updated.append('auth_mechanisms = plain login')
            found = True
        else:
            updated.append(line)
    if not found:
        updated.append('auth_mechanisms = plain login')
    conf.write_text('\n'.join(updated) + '\n')
PY
        cat > "$dovecot_ssl" <<EOF
ssl = yes
ssl_server_cert_file = ${mail_tls_dir}/fullchain.pem
ssl_server_key_file = ${mail_tls_dir}/privkey.pem
ssl_min_protocol = TLSv1.2
EOF
    fi

    mkdir -p /var/spool/postfix/opendkim
    chown opendkim:postfix /var/spool/postfix/opendkim >/dev/null 2>&1 || true
    chmod 750 /var/spool/postfix/opendkim >/dev/null 2>&1 || true
    python3 - <<'PY' >/dev/null 2>&1 || true
from pathlib import Path
conf = Path('/etc/opendkim.conf')
if conf.exists():
    text = conf.read_text()
    updated = text.replace('UserID          opendkim:opendkim', 'UserID          opendkim:postfix')
    if 'Canonicalization' not in updated:
        updated = updated.replace('SignatureAlgorithm rsa-sha256', 'SignatureAlgorithm rsa-sha256\\nCanonicalization relaxed/relaxed')
    else:
        updated = updated.replace('Canonicalization simple/simple', 'Canonicalization relaxed/relaxed')
    if updated != text:
        conf.write_text(updated)
PY
    rm -f /etc/systemd/system/opendkim-socket-perms.service /etc/systemd/system/opendkim-socket-perms.path
    systemctl disable --now opendkim-socket-perms.path >/dev/null 2>&1 || true
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl restart opendkim >/dev/null 2>&1 || true

    systemctl restart dovecot >/dev/null 2>&1 || true
    systemctl restart postfix >/dev/null 2>&1 || true
}

restore_from_backup_dir() {
    local source_dir="$1"
    local reason="${2:-Restoring backup}"
    [[ -d "$source_dir/panel" ]] || die "Backup is missing panel directory: $source_dir"

    warn "$reason from $source_dir..."
    set +e
    systemctl stop strata-queue 2>/dev/null
    systemctl stop strata-agent 2>/dev/null
    systemctl stop strata-webdav 2>/dev/null
    rm -rf "$INSTALL_DIR/panel" "$INSTALL_DIR/agent-src" "$INSTALL_DIR/installer"
    cp -a "$source_dir/panel" "$INSTALL_DIR/panel"
    [[ -d "$source_dir/agent-src" ]] && cp -a "$source_dir/agent-src" "$INSTALL_DIR/agent-src"
    [[ -d "$source_dir/installer" ]] && cp -a "$source_dir/installer" "$INSTALL_DIR/installer"
    [[ -d "$source_dir/tools" ]] && cp -a "$source_dir/tools" "$INSTALL_DIR/tools"
    [[ -f "$source_dir/VERSION" ]] && cp -a "$source_dir/VERSION" "$INSTALL_DIR/VERSION"
    if [[ -f "$source_dir/strata-agent" ]]; then
        cp -a "$source_dir/strata-agent" /usr/sbin/strata-agent
        chmod 755 /usr/sbin/strata-agent
    fi
    if [[ -f "$source_dir/strata-webdav" ]]; then
        cp -a "$source_dir/strata-webdav" /usr/sbin/strata-webdav
        chmod 755 /usr/sbin/strata-webdav
    fi
    if [[ -f "$source_dir/installer/agent-upgrade.sh" ]]; then
        install -m 755 "$source_dir/installer/agent-upgrade.sh" /usr/sbin/strata-agent-upgrade
    fi
    if [[ -f "$source_dir/installer/upgrade.sh" ]]; then
        install -m 755 "$source_dir/installer/upgrade.sh" /root/strata-upgrade.sh
        install -m 755 "$source_dir/installer/upgrade.sh" /usr/sbin/strata-upgrade
    fi
    install_storage_migration_tools "$source_dir"
    cat > /etc/sudoers.d/strata-upgrade <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-upgrade
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-storage-migrate
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-storage-migrate-rollback
EOF
    chmod 440 /etc/sudoers.d/strata-upgrade
    reassert_storage_mounts
    write_storage_config
    set_permissions
    "$PHP_BIN" "$INSTALL_DIR/panel/artisan" optimize:clear >/dev/null 2>&1
    "$PHP_BIN" "$INSTALL_DIR/panel/artisan" config:cache >/dev/null 2>&1
    "$PHP_BIN" "$INSTALL_DIR/panel/artisan" route:cache >/dev/null 2>&1
    systemctl daemon-reload 2>/dev/null
    systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null
    systemctl restart nginx 2>/dev/null || true
    systemctl restart apache2 2>/dev/null || true
    systemctl restart strata-agent 2>/dev/null
    systemctl restart strata-webdav 2>/dev/null
    systemctl restart strata-queue 2>/dev/null
    set -e
}

restore_backup() {
    [[ $ROLLBACK_ON_FAIL -eq 1 ]] || return 0
    [[ -n "$BACKUP_DIR" && -d "$BACKUP_DIR/panel" ]] || return 0

    restore_from_backup_dir "$BACKUP_DIR" "Upgrade failed. Rolling back"
    warn "Rollback completed. Review logs before retrying."
}

write_backup_metadata() {
    local source_type="$1"
    local source_value="$2"
    local current_version="$3"

    cat > "$BACKUP_DIR/metadata.json" <<EOF
{
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "backup_name": "$(basename "$BACKUP_DIR")",
  "backup_path": "$BACKUP_DIR",
  "installed_version": "$(json_escape "$current_version")",
  "source_type": "$(json_escape "$source_type")",
  "source_value": "$(json_escape "$source_value")"
}
EOF
    chmod 600 "$BACKUP_DIR/metadata.json"
}

list_backups() {
    local first=1
    printf '['
    if [[ -d "$BACKUP_ROOT" ]]; then
        while IFS= read -r backup_dir; do
            [[ -d "$backup_dir/panel" ]] || continue

            local backup_name installed_version created_at source_type source_value
            backup_name="$(basename "$backup_dir")"
            installed_version=""
            created_at=""
            source_type=""
            source_value=""

            if [[ -f "$backup_dir/metadata.json" ]]; then
                installed_version="$(grep -o '"installed_version":[[:space:]]*"[^"]*"' "$backup_dir/metadata.json" | head -n1 | cut -d'"' -f4)"
                created_at="$(grep -o '"created_at":[[:space:]]*"[^"]*"' "$backup_dir/metadata.json" | head -n1 | cut -d'"' -f4)"
                source_type="$(grep -o '"source_type":[[:space:]]*"[^"]*"' "$backup_dir/metadata.json" | head -n1 | cut -d'"' -f4)"
                source_value="$(grep -o '"source_value":[[:space:]]*"[^"]*"' "$backup_dir/metadata.json" | head -n1 | cut -d'"' -f4)"
            fi

            if [[ -z "$installed_version" && -f "$backup_dir/VERSION" ]]; then
                installed_version="$(cat "$backup_dir/VERSION")"
            fi
            if [[ -z "$created_at" ]]; then
                created_at="$(date -u -r "$backup_dir" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)"
            fi

            if [[ $first -eq 0 ]]; then
                printf ','
            fi
            first=0
            printf '\n  {"name":"%s","path":"%s","installed_version":"%s","created_at":"%s","source_type":"%s","source_value":"%s"}' \
                "$(json_escape "$backup_name")" \
                "$(json_escape "$backup_dir")" \
                "$(json_escape "$installed_version")" \
                "$(json_escape "$created_at")" \
                "$(json_escape "$source_type")" \
                "$(json_escape "$source_value")"
        done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d | sort -r)
    fi
    if [[ $first -eq 0 ]]; then
        printf '\n'
    fi
    printf ']\n'
}

prune_old_backups() {
    local retention="${ROLLBACK_RETENTION:-5}"

    if ! [[ "$retention" =~ ^[0-9]+$ ]]; then
        warn "Invalid rollback retention value: $retention. Skipping backup cleanup."
        return 0
    fi

    if [[ "$retention" -le 0 || ! -d "$BACKUP_ROOT" ]]; then
        return 0
    fi

    local backup_dirs=()
    while IFS= read -r backup_dir; do
        [[ -d "$backup_dir/panel" ]] || continue
        backup_dirs+=("$backup_dir")
    done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d | sort -r)

    if [[ "${#backup_dirs[@]}" -le "$retention" ]]; then
        return 0
    fi

    local removed=0
    for backup_dir in "${backup_dirs[@]:$retention}"; do
        rm -rf "$backup_dir"
        ((removed+=1))
    done

    if [[ "$removed" -gt 0 ]]; then
        info "Pruned $removed rollback backup(s); keeping newest $retention."
    fi
}

on_error() {
    local exit_code=$?
    FAILED=1
    restore_backup
    exit "$exit_code"
}

cleanup() {
    if [[ $KEEP_WORKDIR -eq 0 && -n "$WORKDIR" && -d "$WORKDIR" ]]; then
        rm -rf "$WORKDIR"
    fi
}

trap on_error ERR
trap cleanup EXIT

if [[ $LIST_BACKUPS -eq 1 ]]; then
    list_backups
    exit 0
fi

detect_php
if [[ -z "$SOURCE_ROLLBACK_BACKUP" ]]; then
    need_command tar
    need_command curl
    need_command composer
    need_command npm
    need_command go
    need_command file
fi

info "Using PHP: $PHP_BIN"
info "Install path: $INSTALL_DIR"
load_storage_config
write_storage_config

free_kb=$(df -Pk "$INSTALL_DIR" | awk 'NR==2 {print $4}')
[[ "$free_kb" -gt 1048576 ]] || die "At least 1 GB free disk space is required."

timestamp="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$BACKUP_ROOT/$timestamp"
WORKDIR="$(mktemp -d /tmp/strata-upgrade.XXXXXX)"
archive="$WORKDIR/source.tar"

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
info "Creating rollback backup at $BACKUP_DIR..."
cp -a "$INSTALL_DIR/panel" "$BACKUP_DIR/panel"
[[ -d "$INSTALL_DIR/agent-src" ]] && cp -a "$INSTALL_DIR/agent-src" "$BACKUP_DIR/agent-src"
[[ -d "$INSTALL_DIR/installer" ]] && cp -a "$INSTALL_DIR/installer" "$BACKUP_DIR/installer"
[[ -d "$INSTALL_DIR/tools" ]] && cp -a "$INSTALL_DIR/tools" "$BACKUP_DIR/tools"
[[ -f "$INSTALL_DIR/VERSION" ]] && cp -a "$INSTALL_DIR/VERSION" "$BACKUP_DIR/VERSION"
if [[ -f /usr/sbin/strata-agent ]]; then
    OLD_AGENT="$BACKUP_DIR/strata-agent"
    cp -a /usr/sbin/strata-agent "$OLD_AGENT"
fi
if [[ -f /usr/sbin/strata-webdav ]]; then
    OLD_WEBDAV="$BACKUP_DIR/strata-webdav"
    cp -a /usr/sbin/strata-webdav "$OLD_WEBDAV"
fi
current_version="$(current_installed_version)"
if [[ -n "$SOURCE_ROLLBACK_BACKUP" ]]; then
    write_backup_metadata "rollback-safety" "$SOURCE_ROLLBACK_BACKUP" "$current_version"
else
    source_type="file"
    source_value="$SOURCE_FILE"
    if [[ -n "$SOURCE_VERSION" ]]; then
        source_type="version"
        source_value="$SOURCE_VERSION"
    elif [[ -n "$SOURCE_CHANNEL" ]]; then
        source_type="channel"
        source_value="$SOURCE_CHANNEL"
    elif [[ -n "$SOURCE_BRANCH" ]]; then
        source_type="branch"
        source_value="$SOURCE_BRANCH"
    fi
    write_backup_metadata "$source_type" "$source_value" "$current_version"
fi
success "Rollback backup created."

if [[ -n "$SOURCE_ROLLBACK_BACKUP" ]]; then
    if [[ "$SOURCE_ROLLBACK_BACKUP" == /* ]]; then
        rollback_dir="$SOURCE_ROLLBACK_BACKUP"
    else
        rollback_dir="$BACKUP_ROOT/$SOURCE_ROLLBACK_BACKUP"
    fi
    [[ -d "$rollback_dir" ]] || die "Rollback backup not found: $SOURCE_ROLLBACK_BACKUP"
    [[ "$rollback_dir" != "$BACKUP_DIR" ]] || die "Refusing to restore from the fresh safety backup created for this rollback."
    restore_from_backup_dir "$rollback_dir" "Rolling back"
    prune_old_backups
    success "Rollback completed from backup: $rollback_dir"
    success "Current-state safety backup kept at: $BACKUP_DIR"
    exit 0
fi

if [[ -n "$SOURCE_FILE" ]]; then
    [[ -f "$SOURCE_FILE" ]] || die "Archive not found: $SOURCE_FILE"
    cp "$SOURCE_FILE" "$archive"
elif [[ -n "$SOURCE_VERSION" ]]; then
    info "Downloading $REPO tag $SOURCE_VERSION..."
    curl -fL "https://github.com/${REPO}/archive/refs/tags/${SOURCE_VERSION}.tar.gz" -o "$archive"
elif [[ -n "$SOURCE_CHANNEL" ]]; then
    SOURCE_BRANCH="$(resolve_channel_branch "$SOURCE_CHANNEL")"
    info "Downloading $REPO update channel $SOURCE_CHANNEL (branch $SOURCE_BRANCH)..."
    curl -fL "https://github.com/${REPO}/archive/refs/heads/${SOURCE_BRANCH}.tar.gz" -o "$archive"
else
    info "Downloading $REPO branch $SOURCE_BRANCH..."
    curl -fL "https://github.com/${REPO}/archive/refs/heads/${SOURCE_BRANCH}.tar.gz" -o "$archive"
fi

extract_dir="$WORKDIR/src"
mkdir -p "$extract_dir"
tar -xf "$archive" -C "$extract_dir" --strip-components=1
[[ -d "$extract_dir/panel" && -d "$extract_dir/agent" ]] || die "Archive does not look like a Strata Hosting Panel source archive."
target_version="$(cat "$extract_dir/VERSION" 2>/dev/null || true)"
if [[ -n "$SOURCE_VERSION" ]]; then
    target_version="$SOURCE_VERSION"
elif [[ -n "$SOURCE_CHANNEL" ]]; then
    target_version="channel-${SOURCE_CHANNEL}"
elif [[ -n "$SOURCE_BRANCH" ]]; then
    target_version="$SOURCE_BRANCH"
elif [[ -z "$target_version" ]]; then
    target_version="archive-$timestamp"
fi

info "Stopping Strata services..."
systemctl stop strata-queue 2>/dev/null || true
systemctl stop strata-agent 2>/dev/null || true
systemctl stop strata-webdav 2>/dev/null || true

info "Installing new source while preserving runtime state..."
rm -rf "$INSTALL_DIR/panel.new" "$INSTALL_DIR/agent-src.new" "$INSTALL_DIR/installer.new" "$INSTALL_DIR/tools.new"
cp -a "$extract_dir/panel" "$INSTALL_DIR/panel.new"
cp -a "$extract_dir/agent" "$INSTALL_DIR/agent-src.new"
cp -a "$extract_dir/installer" "$INSTALL_DIR/installer.new"
[[ -d "$extract_dir/tools" ]] && cp -a "$extract_dir/tools" "$INSTALL_DIR/tools.new"
cp "$extract_dir/VERSION" "$INSTALL_DIR/VERSION.new" 2>/dev/null || echo "$target_version" > "$INSTALL_DIR/VERSION.new"

rm -rf "$INSTALL_DIR/panel.new/.env" "$INSTALL_DIR/panel.new/storage"
cp -a "$INSTALL_DIR/panel/.env" "$INSTALL_DIR/panel.new/.env"
cp -a "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel.new/storage"

rm -rf "$INSTALL_DIR/panel" "$INSTALL_DIR/agent-src" "$INSTALL_DIR/installer" "$INSTALL_DIR/tools"
mv "$INSTALL_DIR/panel.new" "$INSTALL_DIR/panel"
mv "$INSTALL_DIR/agent-src.new" "$INSTALL_DIR/agent-src"
mv "$INSTALL_DIR/installer.new" "$INSTALL_DIR/installer"
[[ -d "$INSTALL_DIR/tools.new" ]] && mv "$INSTALL_DIR/tools.new" "$INSTALL_DIR/tools"
mv "$INSTALL_DIR/VERSION.new" "$INSTALL_DIR/VERSION"
install_storage_migration_tools "$INSTALL_DIR"
if [[ -f "$extract_dir/installer/agent-upgrade.sh" ]]; then
    install -m 755 "$extract_dir/installer/agent-upgrade.sh" /usr/sbin/strata-agent-upgrade
fi
if [[ -f "$extract_dir/installer/upgrade.sh" ]]; then
    install -m 755 "$extract_dir/installer/upgrade.sh" /root/strata-upgrade.sh
    install -m 755 "$extract_dir/installer/upgrade.sh" /usr/sbin/strata-upgrade
fi
cat > /etc/sudoers.d/strata-upgrade <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-upgrade
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-storage-migrate
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-storage-migrate-rollback
EOF
chmod 440 /etc/sudoers.d/strata-upgrade
if grep -q '^STRATA_VERSION=' "$INSTALL_DIR/panel/.env"; then
    sed -i "s|^STRATA_VERSION=.*|STRATA_VERSION=${target_version}|" "$INSTALL_DIR/panel/.env"
else
    printf '\nSTRATA_VERSION=%s\n' "$target_version" >> "$INSTALL_DIR/panel/.env"
fi
if grep -q '^STRATA_WEBMAIL_DATA_PATH=' "$INSTALL_DIR/panel/.env"; then
    sed -i "s|^STRATA_WEBMAIL_DATA_PATH=.*|STRATA_WEBMAIL_DATA_PATH=/var/lib/snappymail|" "$INSTALL_DIR/panel/.env"
else
    printf 'STRATA_WEBMAIL_DATA_PATH=/var/lib/snappymail\n' >> "$INSTALL_DIR/panel/.env"
fi
if [[ -d /var/www/webmail ]]; then
    cat > /var/www/webmail/include.php <<'EOF'
<?php
define('APP_DATA_FOLDER_PATH', '/var/lib/snappymail/');
EOF
    chown www-data:www-data /var/www/webmail/include.php 2>/dev/null || true
    chmod 644 /var/www/webmail/include.php 2>/dev/null || true
fi
if [[ -d /etc/phpmyadmin/conf.d ]]; then
    cat > /etc/phpmyadmin/conf.d/90-strata-cookie-auth.php <<'EOF'
<?php
declare(strict_types=1);

if (! isset($i) || ! is_int($i) || $i < 1) {
    $i = 1;
}

$cfg['Servers'][$i]['auth_type'] = 'cookie';
unset($cfg['Servers'][$i]['controluser'], $cfg['Servers'][$i]['controlpass']);
EOF
fi
rm -f /etc/pure-ftpd/conf/VirtualChroot 2>/dev/null || true
if [[ -d /etc/pure-ftpd/conf ]]; then
    echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
    echo "no" > /etc/pure-ftpd/conf/UnixAuthentication
fi
if [[ -d /etc/pure-ftpd ]]; then
    mkdir -p /etc/pure-ftpd/auth
    rm -f /etc/pure-ftpd/auth/*
    ln -sf ../conf/PureDB /etc/pure-ftpd/auth/60puredb
fi

info "Installing panel dependencies and running migrations..."
cd "$INSTALL_DIR/panel"
composer install --no-dev --optimize-autoloader --no-interaction
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan strata:license-sync 2>/dev/null || true
"$PHP_BIN" artisan strata:webmail-configure || warn "SnappyMail managed domain profile repair skipped."

info "Building frontend assets..."
export NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=512}"
if [[ -f package-lock.json ]]; then
    npm ci
else
    npm install
fi
npm run build

info "Caching Laravel config/routes/views..."
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

info "Building strata-agent..."
cd "$INSTALL_DIR/agent-src"
go mod tidy
GOARCH_TARGET="$(detect_goarch)"
NEW_AGENT="$WORKDIR/strata-agent"
NEW_WEBDAV="$WORKDIR/strata-webdav"
GOOS=linux GOARCH="$GOARCH_TARGET" go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/buildinfo.Version=${target_version}" \
    -o "$NEW_AGENT" \
    .
chmod 755 "$NEW_AGENT"
GOOS=linux GOARCH="$GOARCH_TARGET" go build \
    -o "$NEW_WEBDAV" \
    ./cmd/strata-webdav
chmod 755 "$NEW_WEBDAV"
validate_binary "$NEW_AGENT"
validate_binary "$NEW_WEBDAV"
install -m 755 "$NEW_AGENT" /usr/sbin/strata-agent
install -m 755 "$NEW_WEBDAV" /usr/sbin/strata-webdav

mkdir -p /etc/strata-webdav
touch /etc/strata-webdav/accounts.json
chmod 600 /etc/strata-webdav/accounts.json
if [[ ! -f /etc/systemd/system/strata-webdav.service ]]; then
    cert_path="/etc/strata-agent/tls/cert.pem"
    key_path="/etc/strata-agent/tls/key.pem"
    [[ -f /etc/strata-panel/tls/fullchain.pem ]] && cert_path="/etc/strata-panel/tls/fullchain.pem"
    [[ -f /etc/strata-panel/tls/privkey.pem ]] && key_path="/etc/strata-panel/tls/privkey.pem"
    cat > /etc/systemd/system/strata-webdav.service <<EOF
[Unit]
Description=Strata Web Disk WebDAV Service
After=network.target

[Service]
Type=simple
User=root
ExecStart=/usr/sbin/strata-webdav
Restart=always
RestartSec=5
Environment=STRATA_WEBDAV_PORT=2078
Environment=STRATA_WEBDAV_ACCOUNTS=/etc/strata-webdav/accounts.json
Environment=STRATA_TLS_CERT=${cert_path}
Environment=STRATA_TLS_KEY=${key_path}

[Install]
WantedBy=multi-user.target
EOF
fi

reassert_storage_mounts
write_storage_config
set_permissions
repair_mail_permissions
repair_fail2ban_defaults
repair_powerdns_soa_defaults
repair_mail_tls_defaults
repair_panel_upload_limits

info "Restarting services..."
systemctl daemon-reload
systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl enable strata-webdav >/dev/null 2>&1 || true
systemctl restart strata-agent
systemctl restart strata-webdav
systemctl restart strata-queue
systemctl restart fail2ban >/dev/null 2>&1 || true
systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
systemctl reload apache2 2>/dev/null || systemctl restart apache2 2>/dev/null || true

info "Running health checks..."
systemctl is-active --quiet strata-agent
systemctl is-active --quiet strata-webdav
systemctl is-active --quiet strata-queue
"$PHP_BIN" "$INSTALL_DIR/panel/artisan" about --only=environment >/dev/null
"$PHP_BIN" "$INSTALL_DIR/panel/artisan" route:list >/dev/null

if [[ $SKIP_REMOTE_AGENTS -eq 1 ]]; then
    warn "Skipping automatic remote node agent upgrades by request."
elif [[ -n "$SOURCE_VERSION" || -n "$SOURCE_CHANNEL" || -n "$SOURCE_BRANCH" ]]; then
    info "Queuing remote node agent upgrades..."
    if [[ -n "$SOURCE_VERSION" ]]; then
        "$PHP_BIN" "$INSTALL_DIR/panel/artisan" strata:nodes-upgrade-agents --target-version="$SOURCE_VERSION" || warn "One or more remote agent upgrades failed to queue."
    elif [[ -n "$SOURCE_CHANNEL" ]]; then
        "$PHP_BIN" "$INSTALL_DIR/panel/artisan" strata:nodes-upgrade-agents --channel="$SOURCE_CHANNEL" || warn "One or more remote agent upgrades failed to queue."
    else
        "$PHP_BIN" "$INSTALL_DIR/panel/artisan" strata:nodes-upgrade-agents --branch="$SOURCE_BRANCH" || warn "One or more remote agent upgrades failed to queue."
    fi
else
    warn "Local archive upgrade cannot be cascaded to remote nodes unless it is available from a trusted GitHub URL."
fi

trap - ERR
FAILED=0
prune_old_backups
success "Upgrade completed successfully."
success "Rollback backup kept at: $BACKUP_DIR"
