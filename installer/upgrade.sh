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
ROLLBACK_ON_FAIL=1
KEEP_WORKDIR=0
SKIP_REMOTE_AGENTS=0
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

usage() {
    cat <<EOF
Strata Hosting Panel upgrade utility

Usage:
  $0 --version v1.0.0-beta.2
  $0 --branch main
  $0 --file /root/strata-hosting-panel.tar.gz

Options:
  --version <tag>      Download and install a GitHub release/tag archive.
  --branch <branch>    Download and install a GitHub branch archive.
  --file <path>        Install from a local .tar.gz/.tgz/.tar archive.
  --install-dir <dir>  Override install path. Default: /opt/strata-panel
  --php-bin <path>     Override PHP binary. Auto-detected by default.
  --no-rollback        Do not restore the previous install on failure.
  --keep-workdir       Keep temporary extracted source for debugging.
  --skip-remote-agents Do not auto-queue remote node agent upgrades after primary upgrade.
  -h, --help           Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version) SOURCE_VERSION="${2:-}"; shift 2 ;;
        --branch) SOURCE_BRANCH="${2:-}"; shift 2 ;;
        --file) SOURCE_FILE="${2:-}"; shift 2 ;;
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
[[ -n "$SOURCE_BRANCH" ]] && ((source_count+=1))
[[ -n "$SOURCE_FILE" ]] && ((source_count+=1))
[[ $source_count -eq 1 ]] || die "Choose exactly one source: --version, --branch, or --file."

[[ -d "$INSTALL_DIR/panel" ]] || die "Panel install not found at $INSTALL_DIR/panel."
[[ -f "$INSTALL_DIR/panel/.env" ]] || die "Panel .env not found at $INSTALL_DIR/panel/.env."

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

validate_binary() {
    local path="$1"

    [[ -s "$path" ]] || die "Binary validation failed: $path is empty."
    file "$path" | grep -Eq 'ELF .* executable' || die "Binary validation failed: $path is not a native executable."
}

set_permissions() {
    if id "$PANEL_USER" >/dev/null 2>&1; then
        chown -R "$PANEL_USER:$PANEL_GROUP" "$INSTALL_DIR/panel" 2>/dev/null || chown -R "$PANEL_USER:$PANEL_USER" "$INSTALL_DIR/panel"
    else
        warn "Panel user $PANEL_USER does not exist; leaving ownership unchanged."
    fi

    find "$INSTALL_DIR/panel" -type f -exec chmod 644 {} \;
    find "$INSTALL_DIR/panel" -type d -exec chmod 755 {} \;
    chmod 600 "$INSTALL_DIR/panel/.env"
    chmod -R ug+rwX "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel/bootstrap/cache"
}

repair_mail_permissions() {
    if id vmail >/dev/null 2>&1; then
        mkdir -p /var/mail/vhosts
        chown vmail:vmail /var/mail/vhosts
        chmod 0750 /var/mail/vhosts
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

    if [[ -d /etc/dovecot/conf.d ]]; then
        cat > "$dovecot_ssl" <<EOF
ssl = yes
ssl_server_cert_file = ${mail_tls_dir}/fullchain.pem
ssl_server_key_file = ${mail_tls_dir}/privkey.pem
ssl_min_protocol = TLSv1.2
EOF
    fi

    systemctl restart dovecot >/dev/null 2>&1 || true
    systemctl restart postfix >/dev/null 2>&1 || true
}

restore_backup() {
    [[ $ROLLBACK_ON_FAIL -eq 1 ]] || return 0
    [[ -n "$BACKUP_DIR" && -d "$BACKUP_DIR/panel" ]] || return 0

    warn "Upgrade failed. Rolling back from $BACKUP_DIR..."

    set +e
    systemctl stop strata-queue 2>/dev/null
    systemctl stop strata-agent 2>/dev/null
    systemctl stop strata-webdav 2>/dev/null
    rm -rf "$INSTALL_DIR/panel" "$INSTALL_DIR/agent-src" "$INSTALL_DIR/installer"
    cp -a "$BACKUP_DIR/panel" "$INSTALL_DIR/panel"
    [[ -d "$BACKUP_DIR/agent-src" ]] && cp -a "$BACKUP_DIR/agent-src" "$INSTALL_DIR/agent-src"
    [[ -d "$BACKUP_DIR/installer" ]] && cp -a "$BACKUP_DIR/installer" "$INSTALL_DIR/installer"
    [[ -f "$BACKUP_DIR/VERSION" ]] && cp -a "$BACKUP_DIR/VERSION" "$INSTALL_DIR/VERSION"
    if [[ -n "$OLD_AGENT" && -f "$OLD_AGENT" ]]; then
        cp -a "$OLD_AGENT" /usr/sbin/strata-agent
        chmod 755 /usr/sbin/strata-agent
    fi
    if [[ -n "$OLD_WEBDAV" && -f "$OLD_WEBDAV" ]]; then
        cp -a "$OLD_WEBDAV" /usr/sbin/strata-webdav
        chmod 755 /usr/sbin/strata-webdav
    fi
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

    warn "Rollback completed. Review logs before retrying."
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

detect_php
need_command tar
need_command curl
need_command composer
need_command npm
need_command go
need_command file

info "Using PHP: $PHP_BIN"
info "Install path: $INSTALL_DIR"

free_kb=$(df -Pk "$INSTALL_DIR" | awk 'NR==2 {print $4}')
[[ "$free_kb" -gt 1048576 ]] || die "At least 1 GB free disk space is required."

timestamp="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$BACKUP_ROOT/$timestamp"
WORKDIR="$(mktemp -d /tmp/strata-upgrade.XXXXXX)"
archive="$WORKDIR/source.tar"

mkdir -p "$BACKUP_DIR"
info "Creating rollback backup at $BACKUP_DIR..."
cp -a "$INSTALL_DIR/panel" "$BACKUP_DIR/panel"
[[ -d "$INSTALL_DIR/agent-src" ]] && cp -a "$INSTALL_DIR/agent-src" "$BACKUP_DIR/agent-src"
[[ -d "$INSTALL_DIR/installer" ]] && cp -a "$INSTALL_DIR/installer" "$BACKUP_DIR/installer"
[[ -f "$INSTALL_DIR/VERSION" ]] && cp -a "$INSTALL_DIR/VERSION" "$BACKUP_DIR/VERSION"
if [[ -f /usr/sbin/strata-agent ]]; then
    OLD_AGENT="$BACKUP_DIR/strata-agent"
    cp -a /usr/sbin/strata-agent "$OLD_AGENT"
fi
if [[ -f /usr/sbin/strata-webdav ]]; then
    OLD_WEBDAV="$BACKUP_DIR/strata-webdav"
    cp -a /usr/sbin/strata-webdav "$OLD_WEBDAV"
fi
success "Rollback backup created."

if [[ -n "$SOURCE_FILE" ]]; then
    [[ -f "$SOURCE_FILE" ]] || die "Archive not found: $SOURCE_FILE"
    cp "$SOURCE_FILE" "$archive"
elif [[ -n "$SOURCE_VERSION" ]]; then
    info "Downloading $REPO tag $SOURCE_VERSION..."
    curl -fL "https://github.com/${REPO}/archive/refs/tags/${SOURCE_VERSION}.tar.gz" -o "$archive"
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
rm -rf "$INSTALL_DIR/panel.new" "$INSTALL_DIR/agent-src.new" "$INSTALL_DIR/installer.new"
cp -a "$extract_dir/panel" "$INSTALL_DIR/panel.new"
cp -a "$extract_dir/agent" "$INSTALL_DIR/agent-src.new"
cp -a "$extract_dir/installer" "$INSTALL_DIR/installer.new"
cp "$extract_dir/VERSION" "$INSTALL_DIR/VERSION.new" 2>/dev/null || echo "$target_version" > "$INSTALL_DIR/VERSION.new"

rm -rf "$INSTALL_DIR/panel.new/.env" "$INSTALL_DIR/panel.new/storage"
cp -a "$INSTALL_DIR/panel/.env" "$INSTALL_DIR/panel.new/.env"
cp -a "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel.new/storage"

rm -rf "$INSTALL_DIR/panel" "$INSTALL_DIR/agent-src" "$INSTALL_DIR/installer"
mv "$INSTALL_DIR/panel.new" "$INSTALL_DIR/panel"
mv "$INSTALL_DIR/agent-src.new" "$INSTALL_DIR/agent-src"
mv "$INSTALL_DIR/installer.new" "$INSTALL_DIR/installer"
mv "$INSTALL_DIR/VERSION.new" "$INSTALL_DIR/VERSION"
if [[ -f "$extract_dir/installer/agent-upgrade.sh" ]]; then
    install -m 755 "$extract_dir/installer/agent-upgrade.sh" /usr/sbin/strata-agent-upgrade
fi
if [[ -f "$extract_dir/installer/upgrade.sh" ]]; then
    install -m 755 "$extract_dir/installer/upgrade.sh" /root/strata-upgrade.sh
    install -m 755 "$extract_dir/installer/upgrade.sh" /usr/sbin/strata-upgrade
fi
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
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/api.Version=${target_version}" \
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

set_permissions
repair_mail_permissions
repair_fail2ban_defaults
repair_powerdns_soa_defaults
repair_mail_tls_defaults

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
elif [[ -n "$SOURCE_VERSION" || -n "$SOURCE_BRANCH" ]]; then
    info "Queuing remote node agent upgrades..."
    if [[ -n "$SOURCE_VERSION" ]]; then
        "$PHP_BIN" "$INSTALL_DIR/panel/artisan" strata:nodes-upgrade-agents --target-version="$SOURCE_VERSION" || warn "One or more remote agent upgrades failed to queue."
    else
        "$PHP_BIN" "$INSTALL_DIR/panel/artisan" strata:nodes-upgrade-agents --branch="$SOURCE_BRANCH" || warn "One or more remote agent upgrades failed to queue."
    fi
else
    warn "Local archive upgrade cannot be cascaded to remote nodes unless it is available from a trusted GitHub URL."
fi

trap - ERR
FAILED=0
success "Upgrade completed successfully."
success "Rollback backup kept at: $BACKUP_DIR"
