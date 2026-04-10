#!/usr/bin/env bash
# =============================================================================
#  Strata Hosting Panel — Installer
#  Supported: Debian 11 (Bullseye) · Debian 12 (Bookworm) · Debian 13 (Trixie)
#  Run as root or any sudo-capable user on a fresh server.
#
#  Usage:
#    bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/install.sh)
#  Or:
#    bash install.sh
# =============================================================================
set -euo pipefail

# Ensure /usr/sbin and /sbin are in PATH — not guaranteed on minimal Debian installs
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}[info]${NC} $*"; }
success() { echo -e "${GREEN}[ok]${NC}   $*"; }
warn()    { echo -e "${YELLOW}[warn]${NC} $*"; }
die()     { echo -e "${RED}[fail]${NC} $*" >&2; exit 1; }
prompt()  { echo -e "${CYAN}$*${NC}"; }

human_bytes() {
    local value="${1:-0}"
    if command -v numfmt >/dev/null 2>&1; then
        numfmt --to=iec --suffix=B "$value"
    else
        awk -v bytes="$value" 'BEGIN {
            split("B KiB MiB GiB TiB PiB", units, " ");
            idx = 1;
            while (bytes >= 1024 && idx < 6) {
                bytes /= 1024;
                idx++;
            }
            printf "%.1f %s\n", bytes, units[idx];
        }'
    fi
}

collect_storage_mounts() {
    mapfile -t STORAGE_MOUNTS < <(
        findmnt -rn -b -o TARGET,SIZE,AVAIL,FSTYPE \
        | awk '
            $1 ~ /^\/(boot|efi)(\/|$)/ { next }
            $4 ~ /^(tmpfs|devtmpfs|squashfs|overlay|proc|sysfs|devpts|cgroup2?|pstore|mqueue|bpf|tracefs|ramfs|autofs|nsfs|fusectl|debugfs|securityfs|configfs)$/ { next }
            { print $0 }
        ' \
        | sort -k2,2nr
    )
}

recommended_storage_mount() {
    local fallback="/"
    local line mountpoint

    for line in "${STORAGE_MOUNTS[@]:-}"; do
        read -r mountpoint _ <<< "$line"
        fallback="$mountpoint"
        [[ "$mountpoint" != "/" ]] && { echo "$mountpoint"; return; }
    done

    echo "$fallback"
}

storage_path_for_mount() {
    local mountpoint="${1:-/}"
    local suffix="${2:-strata}"

    if [[ "$mountpoint" == "/" ]]; then
        echo "/${suffix}"
    else
        echo "${mountpoint%/}/${suffix}"
    fi
}

prompt_storage_root() {
    local label="$1"
    local suffix="$2"
    local __resultvar="$3"
    local line mountpoint size avail fstype
    local recommended_mount default_path choice custom_path selected_mount

    collect_storage_mounts
    recommended_mount="$(recommended_storage_mount)"
    default_path="$(storage_path_for_mount "$recommended_mount" "$suffix")"

    echo ""
    echo -e "${BOLD}-- ${label} storage --${NC}"
    echo ""
    echo "  Available mounted filesystems:"

    local index=1
    for line in "${STORAGE_MOUNTS[@]:-}"; do
        read -r mountpoint size avail fstype <<< "$line"
        printf '    %s) %-18s total %-9s free %-9s fs %s\n' \
            "$index" \
            "$mountpoint" \
            "$(human_bytes "$size")" \
            "$(human_bytes "$avail")" \
            "$fstype"
        ((index++))
    done

    echo ""
    echo "  Recommended path: ${default_path}"
    echo "  Press Enter to accept, choose a number from the list above, or type a custom absolute path."
    read -rp "$(prompt "${label} root [${default_path}]: ")" choice
    choice="${choice:-$default_path}"

    if [[ "$choice" =~ ^[0-9]+$ ]] && (( choice >= 1 && choice < index )); then
        line="${STORAGE_MOUNTS[$((choice - 1))]}"
        read -r selected_mount _ <<< "$line"
        custom_path="$(storage_path_for_mount "$selected_mount" "$suffix")"
        printf -v "$__resultvar" '%s' "$custom_path"
        return
    fi

    [[ "$choice" == /* ]] || die "${label} root must be an absolute path."
    printf -v "$__resultvar" '%s' "${choice%/}"
}

ensure_bind_mount() {
    local source_path="$1"
    local target_path="$2"
    local fstab_label="$3"

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

remove_bind_mount() {
    local source_path="${1:-}"
    local target_path="${2:-}"
    local fstab_label="${3:-}"

    [[ -n "$target_path" ]] || return

    if mountpoint -q "$target_path"; then
        umount "$target_path" 2>/dev/null || true
    fi

    if [[ -f /etc/fstab ]]; then
        grep -vF "# ${fstab_label}" /etc/fstab > /etc/fstab.strata.tmp 2>/dev/null || true
        if [[ -f /etc/fstab.strata.tmp ]]; then
            mv /etc/fstab.strata.tmp /etc/fstab
        fi
    fi

}

# ── Privilege escalation ──────────────────────────────────────────────────────
# Re-exec under sudo if not already root so the installer can be run as any
# user with sudo access (e.g. bash install.sh  or  bash <(curl -fsSL ...)).
if [[ $EUID -ne 0 ]]; then
    if command -v sudo &>/dev/null; then
        exec sudo --preserve-env=HOME,USER,LOGNAME bash "$BASH_SOURCE" "$@"
    fi
    die "Must be run as root (sudo not found)."
fi

DEBIAN_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$DEBIAN_VERSION" == "11" || "$DEBIAN_VERSION" == "12" || "$DEBIAN_VERSION" == "13" ]] \
    || die "Unsupported OS. Debian 11, 12, or 13 required (detected: $DEBIAN_VERSION)."

SERVER_IP=$(curl -4 -fsSL https://icanhazip.com 2>/dev/null || hostname -I | awk '{print $1}')
DETECTED_HOSTNAME=$(hostname -f 2>/dev/null || hostname)

echo -e "\n${BOLD}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║           Strata Hosting Panel Installer  v1.0-Beta          ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════╝${NC}\n"
info "Debian $DEBIAN_VERSION · IP: $SERVER_IP"
echo ""

# ── Helper: generate a random password ───────────────────────────────────────
gen_pass()  { openssl rand -base64 40 | tr -dc 'a-zA-Z0-9' | head -c "${1:-32}"; }
gen_hex()   { openssl rand -hex "${1:-32}"; }
gen_uuid()  { cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen; }

# ── Rollback / cleanup ────────────────────────────────────────────────────────
# Registered via `trap cleanup ERR` only AFTER the user confirms — so a pre-
# confirm abort (Ctrl-C, bad OS check, etc.) does NOT trigger a rollback.
INSTALL_STARTED=0

cleanup() {
    local rc=$?
    [[ $INSTALL_STARTED -eq 0 ]] && return
    echo ""
    echo -e "${RED}[!] Installation failed (exit ${rc}) — rolling back changes…${NC}"
    echo ""

    # Stop and remove Strata services
    for svc in strata-agent strata-webdav strata-queue; do
        systemctl stop    "$svc" 2>/dev/null || true
        systemctl disable "$svc" 2>/dev/null || true
        rm -f "/etc/systemd/system/${svc}.service"
    done
    systemctl daemon-reload 2>/dev/null || true

    remove_bind_mount "${HOSTING_STORAGE_ROOT:-}" "${HOSTING_BIND_TARGET:-/var/www}" "strata-hosting-storage"
    remove_bind_mount "${BACKUP_STORAGE_ROOT:-}" "${BACKUP_BIND_TARGET:-/var/backups/strata}" "strata-backup-storage"

    # Drop Strata databases and users (best-effort — MariaDB may not be set up yet)
    if command -v mysql &>/dev/null && [[ -n "${DB_PASSWORD:-}" ]]; then
        mysql -u root -p"${DB_PASSWORD}" -h 127.0.0.1 2>/dev/null <<SQLEOF || true
DROP DATABASE IF EXISTS strata_panel;
DROP USER IF EXISTS 'strata'@'localhost';
DROP DATABASE IF EXISTS pdns;
DROP USER IF EXISTS 'pdns'@'localhost';
FLUSH PRIVILEGES;
SQLEOF
    fi

    # Remove installed directories and binaries
    rm -rf "${INSTALL_DIR:-/opt/strata-panel}"
    rm -rf "${WEBMAIL_DIR:-/var/www/webmail}"
    rm -rf "${WEBMAIL_DATA:-/var/lib/snappymail}"
    rm -rf /etc/strata-agent
    rm -rf /etc/strata-webdav
    rm -rf /etc/strata-panel
    rm -rf /var/www/strata-placeholder
    rm -f  /usr/sbin/strata-agent
    rm -f  /usr/sbin/strata-webdav
    rm -f  /root/.my.cnf

    # Remove the strata system user
    /usr/sbin/userdel -r "${PANEL_USER:-strata}" 2>/dev/null || true

    # Remove scheduler cron
    crontab -r -u "${PANEL_USER:-strata}" 2>/dev/null || true

    # Remove web vhost configs
    rm -f /etc/nginx/sites-enabled/strata-panel
    rm -f /etc/nginx/sites-available/strata-panel
    rm -f /etc/nginx/sites-enabled/zzzz-strata-placeholder
    rm -f /etc/nginx/sites-available/zzzz-strata-placeholder
    rm -f /etc/apache2/sites-enabled/strata-panel.conf
    rm -f /etc/apache2/sites-available/strata-panel.conf
    rm -f /etc/apache2/sites-enabled/zzzz-strata-placeholder.conf
    rm -f /etc/apache2/sites-available/zzzz-strata-placeholder.conf
    systemctl reload nginx  2>/dev/null || true
    systemctl reload apache2 2>/dev/null || true

    # Remove apt source files added by this installer
    rm -f /etc/apt/sources.list.d/php.list
    rm -f /etc/apt/sources.list.d/rspamd.list
    rm -f /usr/share/keyrings/deb.sury.org-php.gpg
    rm -f /usr/share/keyrings/rspamd.gpg

    echo ""
    echo -e "${YELLOW}[warn] Rollback complete.${NC}"
    echo -e "${YELLOW}       Packages installed by apt were NOT removed automatically.${NC}"
    echo -e "${YELLOW}       To purge them manually:${NC}"
    echo    "         apt-get purge mariadb-server pdns-server pdns-backend-mysql \\"
    echo    "           redis-server pure-ftpd postfix dovecot-core rspamd fail2ban"
    echo ""
    exit "$rc"
}

# ── 1. Server hostname ────────────────────────────────────────────────────────
echo -e "${BOLD}── Server configuration ─────────────────────────────────${NC}"
echo ""
echo -e "  The server hostname is used by Postfix (mail), OpenDKIM, and the agent."
echo -e "  It should be a valid FQDN (e.g. ${BOLD}server1.example.com${NC})."
echo -e "  Detected hostname: ${YELLOW}${DETECTED_HOSTNAME}${NC}"
echo ""
read -rp "$(prompt "Server hostname [${DETECTED_HOSTNAME}]: ")" HOSTNAME_INPUT
HOSTNAME_FQDN="${HOSTNAME_INPUT:-$DETECTED_HOSTNAME}"
[[ "$HOSTNAME_FQDN" =~ \. ]] || warn "Hostname '${HOSTNAME_FQDN}' has no dot — a bare hostname may cause mail delivery issues."

# Apply hostname to the system
if [[ "$HOSTNAME_FQDN" != "$DETECTED_HOSTNAME" ]]; then
    info "Setting system hostname to ${HOSTNAME_FQDN}…"
    hostnamectl set-hostname "$HOSTNAME_FQDN"
    SHORT="${HOSTNAME_FQDN%%.*}"
    # Update /etc/hosts — replace any existing 127.0.1.1 line
    sed -i "/^127\.0\.1\.1/d" /etc/hosts
    echo "127.0.1.1  ${HOSTNAME_FQDN} ${SHORT}" >> /etc/hosts
    success "Hostname set to ${HOSTNAME_FQDN}."
else
    info "Keeping existing hostname: ${HOSTNAME_FQDN}"
fi

# ── 2. Panel domain & web server ──────────────────────────────────────────────
echo ""
IFS='.' read -r -a HOSTNAME_LABELS <<< "$HOSTNAME_FQDN"
if (( ${#HOSTNAME_LABELS[@]} >= 3 )); then
    HOSTNAME_PARENT_DOMAIN="${HOSTNAME_FQDN#*.}"
elif (( ${#HOSTNAME_LABELS[@]} == 2 )); then
    HOSTNAME_PARENT_DOMAIN="$HOSTNAME_FQDN"
else
    HOSTNAME_PARENT_DOMAIN="example.com"
fi
PANEL_DOMAIN_DEFAULT="panel.${HOSTNAME_PARENT_DOMAIN}"

echo -e "  ${BOLD}Recommended:${NC} use a dedicated subdomain for the panel, for example ${BOLD}${PANEL_DOMAIN_DEFAULT}${NC}."
echo -e "  Keep the apex/root domain, for example ${BOLD}${HOSTNAME_PARENT_DOMAIN}${NC}, available for the admin website or hosted content."
echo -e "  This avoids the control panel occupying the same vhost as your main site."
echo ""
read -rp "$(prompt "Panel domain [${PANEL_DOMAIN_DEFAULT}]: ")" PANEL_DOMAIN_INPUT
PANEL_DOMAIN="${PANEL_DOMAIN_INPUT:-$PANEL_DOMAIN_DEFAULT}"
[[ -n "$PANEL_DOMAIN" ]] || die "Panel domain is required."
if [[ "$PANEL_DOMAIN" == "$HOSTNAME_PARENT_DOMAIN" ]]; then
    warn "Using the apex/root domain for the panel is not recommended. A subdomain such as panel.${HOSTNAME_PARENT_DOMAIN} keeps the main domain free for the admin website."
fi

echo ""
echo ""
echo -e "  ${BOLD}Web server for hosted accounts${NC}"
echo -e "    ${CYAN}1)${NC} nginx   — Nginx handles all vhosts (recommended)"
echo -e "    ${CYAN}2)${NC} apache  — Apache2 handles all vhosts"
echo ""
read -rp "$(prompt 'Choice [1]: ')" WEB_SERVER_CHOICE
case "${WEB_SERVER_CHOICE:-1}" in
    2|apache|Apache) WEB_SERVER="apache" ;;
    *)               WEB_SERVER="nginx"  ;;
esac
echo -e "  Selected: ${GREEN}$WEB_SERVER${NC}"
prompt_storage_root "Hosting data" "strata/www" HOSTING_STORAGE_ROOT
prompt_storage_root "Backup data" "strata/backups" BACKUP_STORAGE_ROOT

# ── 2. Admin account ──────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Admin account ────────────────────────────────────────${NC}"
echo ""

read -rp "$(prompt 'Admin name: ')" ADMIN_NAME
[[ -n "$ADMIN_NAME" ]] || die "Admin name is required."

read -rp "$(prompt 'Admin email: ')" ADMIN_EMAIL
[[ -n "$ADMIN_EMAIL" ]] || die "Admin email is required."

while true; do
    read -rsp "$(prompt 'Admin password (min 12 chars): ')" ADMIN_PASSWORD; echo ""
    [[ ${#ADMIN_PASSWORD} -ge 12 ]] && break
    echo -e "  ${RED}Password must be at least 12 characters. Try again.${NC}"
done

read -rsp "$(prompt 'Confirm admin password: ')" ADMIN_PASSWORD_CONFIRM; echo ""
[[ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]] || die "Passwords do not match."

# ── 3. Service passwords ──────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Service passwords ────────────────────────────────────${NC}"
echo ""
echo "  Each service (MariaDB, PowerDNS, Redis, Webmail) needs a password."
echo "  You can let the installer generate secure random passwords (recommended),"
echo "  or enter your own."
echo ""
read -rp "$(prompt 'Auto-generate all service passwords? [Y/n]: ')" AUTO_PASS
AUTO_PASS="${AUTO_PASS:-Y}"

if [[ "${AUTO_PASS,,}" =~ ^(n|no)$ ]]; then
    echo ""
    echo -e "  ${YELLOW}Enter a password for each service, or press Enter to generate that one.${NC}"
    echo ""

    read -rsp "$(prompt '  MariaDB root password [generate]: ')" DB_PASSWORD; echo ""
    [[ -z "$DB_PASSWORD" ]] && DB_PASSWORD=$(gen_pass)

    read -rsp "$(prompt '  PowerDNS DB password [generate]: ')" PDNS_DB_PASSWORD; echo ""
    [[ -z "$PDNS_DB_PASSWORD" ]] && PDNS_DB_PASSWORD=$(gen_pass)

    read -rsp "$(prompt '  Redis password [generate]: ')" REDIS_PASSWORD; echo ""
    [[ -z "$REDIS_PASSWORD" ]] && REDIS_PASSWORD=$(gen_pass)

    read -rsp "$(prompt '  SnappyMail admin password [generate]: ')" SNAPPYMAIL_ADMIN_PASS; echo ""
    [[ -z "$SNAPPYMAIL_ADMIN_PASS" ]] && SNAPPYMAIL_ADMIN_PASS=$(gen_pass 20)
else
    DB_PASSWORD=$(gen_pass)
    PDNS_DB_PASSWORD=$(gen_pass)
    REDIS_PASSWORD=$(gen_pass)
    SNAPPYMAIL_ADMIN_PASS=$(gen_pass 20)
    echo -e "  ${GREEN}All service passwords will be generated automatically.${NC}"
fi

# ── Internal / generated values ───────────────────────────────────────────────
AGENT_HMAC_SECRET=$(gen_hex 32)
AGENT_NODE_ID=$(gen_uuid)
PDNS_API_KEY=$(gen_hex 32)
INSTALL_TOKEN=$(gen_uuid)
INSTALL_SECRET=$(gen_hex 32)
APP_KEY="base64:$(openssl rand -base64 32)"
WEBMAIL_SSO_SECRET=$(gen_hex 32)
SNAPPYMAIL_VERSION="2.38.2"
WEBMAIL_DIR="/var/www/webmail"
WEBMAIL_DATA="/var/lib/snappymail"
INSTALL_DIR="/opt/strata-panel"
PANEL_USER="strata"
MAIL_DOMAIN="mail.${HOSTNAME_PARENT_DOMAIN}"
MAIL_TLS_DIR="/etc/strata-panel/mail-tls"
MAIL_HTTP_ROOT="/var/www/strata-mail"
HOSTING_BIND_TARGET="/var/www"
BACKUP_BIND_TARGET="/var/backups/strata"

# ── Pre-flight: repair broken package manager state ───────────────────────────
# Runs before the confirm prompt so any failure exits cleanly without the rollback trap.
# We use dpkg --force-all here because package repos are not yet configured —
# apt-get install -f can't heal packages it can't download. We heal properly
# in Step 2 after the PHP repo is added.
echo ""
info "Checking package manager state…"
# Match anything NOT cleanly installed (ii), not removed-with-config (rc),
# and not unknown/not-installed (un). This catches iF, iH, iU, iW, iiR, iuR, etc.
_pf_broken=$(dpkg -l 2>/dev/null \
    | awk 'NR>5 && $1 !~ /^(ii|rc|un)/ {pkg=$2; sub(/:.*$/,"",pkg); print pkg}' \
    | sort -u | tr '\n' ' ')
if [[ -n "${_pf_broken// }" ]]; then
    warn "Broken packages detected: ${_pf_broken}"
    warn "Force-removing — they will be cleanly reinstalled…"
    for _pkg in $_pf_broken; do
        dpkg --remove --force-all "$_pkg" 2>/dev/null \
            || dpkg --purge  --force-all "$_pkg" 2>/dev/null \
            || true
    done
    dpkg --configure -a 2>/dev/null || true
    success "Broken packages removed."
else
    dpkg --configure -a 2>/dev/null || true
    success "Package manager OK."
fi

# ── Confirm before proceeding ─────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Summary ──────────────────────────────────────────────${NC}"
echo ""
echo -e "  Server hostname:${BOLD}${HOSTNAME_FQDN}${NC}"
echo -e "  Panel domain:   ${BOLD}${PANEL_DOMAIN}${NC}"
echo -e "  Web server:     ${BOLD}${WEB_SERVER}${NC}"
echo -e "  Admin email:    ${BOLD}${ADMIN_EMAIL}${NC}"
echo -e "  Admin name:     ${BOLD}${ADMIN_NAME}${NC}"
echo -e "  Hosting data:   ${BOLD}${HOSTING_STORAGE_ROOT}${NC} -> ${HOSTING_BIND_TARGET}"
echo -e "  Backup data:    ${BOLD}${BACKUP_STORAGE_ROOT}${NC} -> ${BACKUP_BIND_TARGET}"
echo -e "  Service creds:  will be saved to ${BOLD}/root/strata-credentials.txt${NC}"
echo ""
read -rp "$(prompt 'Proceed with installation? [Y/n]: ')" CONFIRM
[[ "${CONFIRM:-Y}" =~ ^(y|Y|yes|YES|)$ ]] || { echo "Aborted."; exit 0; }

# Register rollback trap — fires on any non-zero exit from here on
INSTALL_STARTED=1
trap cleanup ERR

echo ""
info "Starting installation…"
echo ""

info "Preparing storage mounts..."
ensure_bind_mount "$HOSTING_STORAGE_ROOT" "$HOSTING_BIND_TARGET" "strata-hosting-storage"
ensure_bind_mount "$BACKUP_STORAGE_ROOT" "$BACKUP_BIND_TARGET" "strata-backup-storage"
success "Hosting data path: ${HOSTING_STORAGE_ROOT} -> ${HOSTING_BIND_TARGET}"
success "Backup data path: ${BACKUP_STORAGE_ROOT} -> ${BACKUP_BIND_TARGET}"

# ── Step 1. System update ─────────────────────────────────────────────────────
dpkg --configure -a 2>/dev/null || true

info "Updating package lists…"
apt-get update || die "apt-get update failed — check your sources.list and network connectivity."

info "Installing base packages…"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl wget gnupg2 ca-certificates lsb-release \
    git unzip zip openssl ufw fail2ban \
    acl sudo cron

info "Installing ClamAV malware scanner…"
DEBIAN_FRONTEND=noninteractive apt-get install -y clamav clamav-daemon
systemctl enable --now clamav-freshclam || warn "clamav-freshclam did not start — malware signatures may need manual update."
systemctl enable --now clamav-daemon || warn "clamav-daemon did not start — clamscan can still run on demand."

# ── Step 2. PHP (Ondrej PPA) ──────────────────────────────────────────────────
info "Adding PHP repository (ondrej/php)…"
curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg \
    https://packages.sury.org/php/apt.gpg
case "$DEBIAN_VERSION" in
    13) PHP_CODENAME="trixie"   ;;
    12) PHP_CODENAME="bookworm" ;;
    *)  PHP_CODENAME="bullseye" ;;
esac
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${PHP_CODENAME} main" \
    > /etc/apt/sources.list.d/php.list
apt-get update

# Debian 13 (Trixie) native repo has PHP 8.2; Sury Trixie has 8.1, 8.2, 8.4.
# PHP 8.3 from Sury conflicts with Trixie native packages — skip it, use 8.4 instead.
case "$DEBIAN_VERSION" in
    13) PHP_VERSIONS=(8.1 8.2 8.4) ;;
    *)  PHP_VERSIONS=(8.1 8.2 8.3) ;;
esac
PHP_EXTENSIONS="fpm cli common curl mbstring xml zip bcmath intl gd mysql redis"
INSTALLED_PHP_VERSIONS=()

info "Installing PHP ${PHP_VERSIONS[*]} + extensions…"
for VER in "${PHP_VERSIONS[@]}"; do
    PKG_LIST=""
    for EXT in $PHP_EXTENSIONS; do
        PKG_LIST="$PKG_LIST php${VER}-${EXT}"
    done
    if DEBIAN_FRONTEND=noninteractive apt-get install -y $PKG_LIST; then
        INSTALLED_PHP_VERSIONS+=("$VER")
        success "PHP ${VER} installed."
    else
        warn "PHP ${VER} not available for this platform — skipping."
    fi
done
[[ ${#INSTALLED_PHP_VERSIONS[@]} -gt 0 ]] || die "No PHP versions could be installed — check the Sury repo."
# Use the highest installed PHP version for the panel
PANEL_PHP_VER="${INSTALLED_PHP_VERSIONS[-1]}"
success "PHP installed. Panel will use PHP ${PANEL_PHP_VER}."

# ── Step 3. MariaDB ───────────────────────────────────────────────────────────
info "Installing MariaDB…"
DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client
systemctl enable --now mariadb

info "Waiting for MariaDB to be ready…"
_MYSQLADMIN=$(command -v mariadb-admin 2>/dev/null || command -v mysqladmin 2>/dev/null)
_db_ready=0
for _i in $(seq 1 30); do
    if "$_MYSQLADMIN" ping --silent 2>/dev/null; then
        _db_ready=1; break
    fi
    sleep 1
done
[[ $_db_ready -eq 1 ]] || die "MariaDB did not start within 30 seconds — check: systemctl status mariadb"
success "MariaDB is up."

info "Installing PostgreSQL for hosted database accounts…"
DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql postgresql-client
systemctl enable --now postgresql || warn "PostgreSQL service did not start — check: systemctl status postgresql"
success "PostgreSQL installed."

info "Securing MariaDB and creating databases…"
# Use `mariadb` client if available (MariaDB 11.x / Debian 13 — preferred),
# fall back to `mysql` for older installs.
_MC=$(command -v mariadb 2>/dev/null || command -v mysql 2>/dev/null)
[[ -n "$_MC" ]] || die "No MariaDB/MySQL client binary found."

_MARIADB_SQL="
    ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
    DELETE FROM mysql.user WHERE User='';
    DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
    DROP DATABASE IF EXISTS test;
    DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
    CREATE DATABASE IF NOT EXISTS strata_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'strata'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON strata_panel.* TO 'strata'@'localhost';
    FLUSH PRIVILEGES;
"
# Attempts in order:
#   1. mariadb/mysql, no args            — unix_socket auth (Debian 11/12)
#   2. mariadb/mysql --protocol=socket   — force socket explicitly (Debian 13)
#   3. empty password                    — some minimal configs
#   4. our password over TCP             — re-run where password already set
_mariadb_rc1=0; _mariadb_err1=$("$_MC"                           -e "$_MARIADB_SQL" 2>&1) || _mariadb_rc1=$?
_mariadb_rc2=0; _mariadb_err2=$("$_MC" --protocol=socket -u root -e "$_MARIADB_SQL" 2>&1) || _mariadb_rc2=$?
_mariadb_rc3=0; _mariadb_err3=$("$_MC" -u root --password=''     -e "$_MARIADB_SQL" 2>&1) || _mariadb_rc3=$?
_mariadb_rc4=0; _mariadb_err4=$("$_MC" -u root -p"${DB_PASSWORD}" -h 127.0.0.1 -e "$_MARIADB_SQL" 2>&1) || _mariadb_rc4=$?
if [[ $_mariadb_rc1 -ne 0 && $_mariadb_rc2 -ne 0 && $_mariadb_rc3 -ne 0 && $_mariadb_rc4 -ne 0 ]]; then
    # All attempts failed — likely a previous failed install left an unknown root password.
    # Reset via skip-grant-tables: temporarily disable auth, set our password, restart.
    warn "All connection attempts failed — resetting MariaDB root password…"
    cat > /etc/mysql/conf.d/strata-reset.cnf <<'EOF'
[mariadb]
skip-grant-tables
skip-networking
EOF
    systemctl restart mariadb
    sleep 3
    "$_MC" -u root -e "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';" 2>/dev/null \
        || "$_MC" -u root -e "FLUSH PRIVILEGES; SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_PASSWORD}');" 2>/dev/null \
        || true
    rm -f /etc/mysql/conf.d/strata-reset.cnf
    systemctl restart mariadb
    sleep 3
    # Retry with our now-set password
    _mariadb_rc5=0
    _mariadb_err5=$("$_MC" -u root -p"${DB_PASSWORD}" -h 127.0.0.1 -e "$_MARIADB_SQL" 2>&1) || _mariadb_rc5=$?
    [[ $_mariadb_rc5 -eq 0 ]] || die "Failed to secure MariaDB even after password reset.
  reset attempt: ${_mariadb_err5}
  earlier errors:
    attempt 1 (no args):           ${_mariadb_err1}
    attempt 2 (--protocol=socket): ${_mariadb_err2}
    attempt 3 (empty password):    ${_mariadb_err3}
    attempt 4 (tcp + password):    ${_mariadb_err4}"
fi

MYSQL_CMD() { "$_MC" -u root -p"${DB_PASSWORD}" -h 127.0.0.1 "$@" 2>/dev/null; }

cat > /root/.my.cnf <<EOF
[client]
user=root
password=${DB_PASSWORD}
host=127.0.0.1
EOF
chmod 600 /root/.my.cnf
success "MariaDB ready."

# ── Step 3b. PowerDNS ─────────────────────────────────────────────────────────
info "Installing PowerDNS with MySQL backend…"
# Prevent conflicts with systemd-resolved on port 53
if ss -tlnp 2>/dev/null | grep -q ':53 '; then
    if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
        sed -i '/^DNSStubListener/d' /etc/systemd/resolved.conf
        echo "DNSStubListener=no" >> /etc/systemd/resolved.conf
        systemctl restart systemd-resolved
        ln -sf /run/systemd/resolve/resolv.conf /etc/resolv.conf
        sleep 1
    fi
fi

DEBIAN_FRONTEND=noninteractive apt-get install -y pdns-server pdns-backend-mysql

MYSQL_CMD -e "
    CREATE DATABASE IF NOT EXISTS pdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON pdns.* TO 'pdns'@'localhost';
    FLUSH PRIVILEGES;
"

# Import PowerDNS schema
SCHEMA_FILE=$(find /usr/share/doc -name "schema.mysql.sql" 2>/dev/null | head -1)
[[ -z "$SCHEMA_FILE" ]] && SCHEMA_FILE=$(find /usr/share -name "schema.mysql.sql" 2>/dev/null | head -1)
if [[ -n "$SCHEMA_FILE" ]]; then
    mysql -u pdns -p"${PDNS_DB_PASSWORD}" -h 127.0.0.1 pdns < "$SCHEMA_FILE" 2>/dev/null || true
else
    warn "PowerDNS MySQL schema not found — import manually: pdns-backend-mysql docs"
fi

mkdir -p /etc/powerdns
# Remove any package-default drop-in configs (e.g. bind.conf) from pdns.d/.
# PowerDNS has a compiled-in include-dir=/etc/powerdns/pdns.d and any
# launch=bind override there silently wins over our launch=gmysql.
rm -f /etc/powerdns/pdns.d/*.conf 2>/dev/null || true
cat > /etc/powerdns/pdns.conf <<EOF
# PowerDNS — managed by Strata Hosting Panel
local-port=53
launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-dbname=pdns
gmysql-user=pdns
gmysql-password=${PDNS_DB_PASSWORD}
gmysql-dnssec=yes

# API
api=yes
api-key=${PDNS_API_KEY}
webserver=yes
webserver-address=127.0.0.1
webserver-port=8053
webserver-allow-from=127.0.0.1
default-soa-content=ns1.${HOSTNAME_PARENT_DOMAIN} hostmaster.${HOSTNAME_PARENT_DOMAIN} 0 10800 3600 1209600 3600

# Logging
log-dns-queries=no
loglevel=4
EOF

systemctl enable pdns
systemctl restart pdns
success "PowerDNS ready (API on 127.0.0.1:8053)."

# ── Step 3c. Pure-FTPd ────────────────────────────────────────────────────────
info "Installing Pure-FTPd…"
DEBIAN_FRONTEND=noninteractive apt-get install -y pure-ftpd pure-ftpd-common

mkdir -p /etc/pureftpd
mkdir -p /etc/pure-ftpd/auth

# Virtual users
rm -f /etc/pure-ftpd/conf/VirtualChroot
echo "/etc/pureftpd/pureftpd.pdb" > /etc/pure-ftpd/conf/PureDB
echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
echo "no" > /etc/pure-ftpd/conf/UnixAuthentication
rm -f /etc/pure-ftpd/auth/*
ln -sf ../conf/PureDB /etc/pure-ftpd/auth/60puredb
echo "yes" > /etc/pure-ftpd/conf/NoAnonymous
echo "yes" > /etc/pure-ftpd/conf/ChrootEveryone
echo "30000 50000" > /etc/pure-ftpd/conf/PassivePortRange
echo "1" > /etc/pure-ftpd/conf/TLS

# FTPS certificate
mkdir -p /etc/ssl/private
openssl req -x509 -newkey rsa:2048 \
    -keyout /etc/ssl/private/pure-ftpd.pem \
    -out    /etc/ssl/private/pure-ftpd.pem \
    -days   3650 -nodes \
    -subj   "/CN=${HOSTNAME_FQDN}" >/dev/null 2>&1
chmod 600 /etc/ssl/private/pure-ftpd.pem

touch /etc/pureftpd/passwd
pure-pw mkdb /etc/pureftpd/pureftpd.pdb -f /etc/pureftpd/passwd 2>/dev/null || true
systemctl enable --now pure-ftpd
success "Pure-FTPd ready (virtual users, FTPS, passive 30000-50000)."

# ── Step 4. Redis ─────────────────────────────────────────────────────────────
info "Installing Redis…"
DEBIAN_FRONTEND=noninteractive apt-get install -y redis-server

# Bind to localhost only and set password
sed -i 's/^supervised no/supervised systemd/' /etc/redis/redis.conf
sed -i 's/^# bind 127.0.0.1/bind 127.0.0.1/' /etc/redis/redis.conf
sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf
# Set requirepass
grep -q '^requirepass' /etc/redis/redis.conf \
    && sed -i "s/^requirepass.*/requirepass ${REDIS_PASSWORD}/" /etc/redis/redis.conf \
    || echo "requirepass ${REDIS_PASSWORD}" >> /etc/redis/redis.conf

systemctl enable redis-server
systemctl restart redis-server
success "Redis ready (localhost only, password set)."

# ── Step 4b. Mail stack ───────────────────────────────────────────────────────
info "Creating vmail system user…"
if ! getent group vmail > /dev/null 2>&1; then
    /usr/sbin/groupadd -g 5000 vmail 2>/dev/null || /usr/sbin/groupadd vmail || die "Failed to create vmail group"
fi
if ! getent passwd vmail > /dev/null 2>&1; then
    /usr/sbin/useradd -u 5000 -g vmail -d /var/mail/vmail -s /usr/sbin/nologin vmail 2>/dev/null \
        || /usr/sbin/useradd -g vmail -d /var/mail/vmail -s /usr/sbin/nologin vmail \
        || die "Failed to create vmail user"
fi
mkdir -p /var/mail/vmail /var/mail/vhosts
chown -R vmail:vmail /var/mail/vmail /var/mail/vhosts
chmod 0750 /var/mail/vhosts
mkdir -p "$MAIL_TLS_DIR"

openssl req -x509 -newkey rsa:4096 \
    -keyout "${MAIL_TLS_DIR}/privkey.pem" \
    -out    "${MAIL_TLS_DIR}/fullchain.pem" \
    -days   3650 -nodes \
    -subj "/CN=${MAIL_DOMAIN}" \
    -addext "subjectAltName=DNS:${MAIL_DOMAIN}" >/dev/null 2>&1
chmod 600 "${MAIL_TLS_DIR}/privkey.pem"

info "Installing Postfix + Dovecot + OpenDKIM…"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    postfix postfix-mysql \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql \
    opendkim opendkim-tools \
    libsasl2-modules

info "Adding Rspamd repository…"
curl -fsSL https://rspamd.com/apt-stable/gpg.key | gpg --dearmor > /usr/share/keyrings/rspamd.gpg 2>/dev/null
echo "deb [signed-by=/usr/share/keyrings/rspamd.gpg] https://rspamd.com/apt-stable/ ${PHP_CODENAME} main" \
    > /etc/apt/sources.list.d/rspamd.list
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y rspamd

# Postfix
postconf -e "myhostname = ${HOSTNAME_FQDN}"
postconf -e "myorigin = \$myhostname"
postconf -e "inet_interfaces = all"
postconf -e "inet_protocols = ipv4"
postconf -e "mydestination = localhost"
postconf -e "mynetworks = 127.0.0.0/8"
postconf -e "home_mailbox = Maildir/"
postconf -e "smtpd_banner = \$myhostname ESMTP"
postconf -e "biff = no"
postconf -e "append_dot_mydomain = no"
postconf -e "virtual_transport = lmtp:unix:private/dovecot-lmtp"
postconf -e "virtual_mailbox_base = /var/mail/vhosts"
postconf -e "virtual_minimum_uid = 5000"
postconf -e "virtual_uid_maps = static:5000"
postconf -e "virtual_gid_maps = static:5000"
postconf -e "virtual_mailbox_domains = hash:/etc/postfix/virtual_domains"
postconf -e "virtual_mailbox_maps = hash:/etc/postfix/virtual_mailboxes"
postconf -e "virtual_alias_maps = hash:/etc/postfix/virtual_aliases"
postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = no"
postconf -e "smtpd_tls_cert_file = ${MAIL_TLS_DIR}/fullchain.pem"
postconf -e "smtpd_tls_key_file = ${MAIL_TLS_DIR}/privkey.pem"
postconf -e "smtpd_tls_security_level = may"
postconf -e "smtpd_relay_restrictions = permit_mynetworks permit_sasl_authenticated defer_unauth_destination"
postconf -e "milter_default_action = accept"
postconf -e "milter_protocol = 6"
postconf -e "smtpd_milters = local:opendkim/opendkim.sock"
postconf -e "non_smtpd_milters = local:opendkim/opendkim.sock"

touch /etc/postfix/virtual_domains /etc/postfix/virtual_mailboxes /etc/postfix/virtual_aliases
postmap /etc/postfix/virtual_domains
postmap /etc/postfix/virtual_mailboxes
postmap /etc/postfix/virtual_aliases
chmod 640 /etc/postfix/virtual_domains /etc/postfix/virtual_mailboxes /etc/postfix/virtual_aliases
chown root:postfix /etc/postfix/virtual_domains /etc/postfix/virtual_mailboxes /etc/postfix/virtual_aliases

postconf -M submission/inet="submission inet n - y - - smtpd" 2>/dev/null || true
postconf -P "submission/inet/syslog_name=postfix/submission" 2>/dev/null || true
postconf -P "submission/inet/smtpd_tls_security_level=encrypt" 2>/dev/null || true
postconf -P "submission/inet/smtpd_sasl_auth_enable=yes" 2>/dev/null || true
postconf -P "submission/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" 2>/dev/null || true
postconf -M smtps/inet="smtps inet n - y - - smtpd" 2>/dev/null || true
postconf -P "smtps/inet/syslog_name=postfix/smtps" 2>/dev/null || true
postconf -P "smtps/inet/smtpd_tls_wrappermode=yes" 2>/dev/null || true
postconf -P "smtps/inet/smtpd_sasl_auth_enable=yes" 2>/dev/null || true
postconf -P "smtps/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" 2>/dev/null || true

# Dovecot
sed -i 's/^!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/^!include auth-sql.conf.ext/#!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/^!include auth-passwdfile.conf.ext/#!include auth-passwdfile.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
grep -q '^!include auth-strata-passwdfile.conf.ext' /etc/dovecot/conf.d/10-auth.conf || echo '!include auth-strata-passwdfile.conf.ext' >> /etc/dovecot/conf.d/10-auth.conf
sed -i 's/^auth_mechanisms =.*/auth_mechanisms = plain login/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's|^mail_location =.*|mail_location = maildir:/var/mail/vhosts/%d/%n|' /etc/dovecot/conf.d/10-mail.conf 2>/dev/null || true
grep -q '^mail_uid' /etc/dovecot/conf.d/10-mail.conf || echo "mail_uid = vmail" >> /etc/dovecot/conf.d/10-mail.conf
grep -q '^mail_gid' /etc/dovecot/conf.d/10-mail.conf || echo "mail_gid = vmail" >> /etc/dovecot/conf.d/10-mail.conf

touch /etc/dovecot/virtual_users
chown root:dovecot /etc/dovecot/virtual_users
chmod 640 /etc/dovecot/virtual_users

cat > /etc/dovecot/conf.d/auth-strata-passwdfile.conf.ext <<'EOF'
passdb passwd-file {
  driver = passwd-file
  auth_username_format = %{user}
  passwd_file_path = /etc/dovecot/virtual_users
}

userdb passwd-file {
  driver = passwd-file
  auth_username_format = %{user}
  passwd_file_path = /etc/dovecot/virtual_users
}
EOF

cat > /etc/dovecot/conf.d/10-master.conf <<'DOVEOF'
service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}
service pop3-login {
  inet_listener pop3 {
    port = 0
  }
  inet_listener pop3s {
    port = 995
    ssl = yes
  }
}
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode  = 0600
    user  = postfix
    group = postfix
  }
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode  = 0660
    user  = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0600
    user = vmail
  }
  user = dovecot
}
service auth-worker {
  user = vmail
}
DOVEOF

cat > /etc/dovecot/conf.d/10-ssl.conf <<EOF
ssl = yes
ssl_server_cert_file = ${MAIL_TLS_DIR}/fullchain.pem
ssl_server_key_file = ${MAIL_TLS_DIR}/privkey.pem
ssl_min_protocol = TLSv1.2
EOF

systemctl enable --now postfix dovecot rspamd
success "Mail stack ready (Postfix + Dovecot + Rspamd)."

# OpenDKIM
info "Configuring OpenDKIM…"
mkdir -p /etc/opendkim/keys

cat > /etc/opendkim.conf <<EOF
# OpenDKIM — managed by Strata Hosting Panel
Syslog          yes
UMask           002
Mode            sv
SignatureAlgorithm rsa-sha256
UserID          opendkim:postfix
Socket          local:/var/spool/postfix/opendkim/opendkim.sock
PidFile         /var/run/opendkim/opendkim.pid
TrustAnchorFile /usr/share/dns/root.key
OversignHeaders From
InternalHosts   /etc/opendkim/trusted.hosts
ExternalIgnoreList /etc/opendkim/trusted.hosts
KeyTable        /etc/opendkim/KeyTable
SigningTable    refile:/etc/opendkim/SigningTable
EOF

cat > /etc/opendkim/trusted.hosts <<EOF
127.0.0.1
localhost
${HOSTNAME_FQDN}
EOF

mkdir -p /etc/opendkim/userkeys
touch /etc/opendkim/KeyTable
touch /etc/opendkim/SigningTable
chown -R opendkim:opendkim /etc/opendkim/userkeys

mkdir -p /var/spool/postfix/opendkim
chown opendkim:postfix /var/spool/postfix/opendkim
chmod 750 /var/spool/postfix/opendkim
rm -f /etc/systemd/system/opendkim-socket-perms.service /etc/systemd/system/opendkim-socket-perms.path
systemctl disable --now opendkim-socket-perms.path >/dev/null 2>&1 || true
systemctl daemon-reload >/dev/null 2>&1 || true

systemctl enable --now opendkim
success "OpenDKIM ready."

# ── Step 5. Web server ────────────────────────────────────────────────────────
if [[ "$WEB_SERVER" == "apache" ]]; then
    info "Installing Apache2…"
    DEBIAN_FRONTEND=noninteractive apt-get install -y apache2
    a2enmod proxy_fcgi setenvif headers rewrite ssl >/dev/null 2>&1
    systemctl enable apache2
    success "Apache2 installed."
else
    info "Installing Nginx…"
    DEBIAN_FRONTEND=noninteractive apt-get install -y nginx
    systemctl enable nginx
    success "Nginx installed."
fi

# ── Step 6. Node.js 20 ────────────────────────────────────────────────────────
info "Installing Node.js 20…"
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs
success "Node.js $(node -v) installed."

# ── Step 7. Composer ──────────────────────────────────────────────────────────
info "Installing Composer…"
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
success "Composer installed."

info "Installing database web tools..."
DEBIAN_FRONTEND=noninteractive apt-get install -y phpmyadmin phppgadmin || warn "phpMyAdmin/phpPgAdmin packages were not available; Database Tools page will show them as not installed."
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
update-alternatives --set php "/usr/bin/php${PANEL_PHP_VER}" >/dev/null 2>&1 || true

# ── Step 8. Go ────────────────────────────────────────────────────────────────
info "Installing Go 1.23…"
GO_VERSION="1.23.8"
wget -q "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -O /tmp/go.tar.gz
rm -rf /usr/local/go
tar -C /usr/local -xzf /tmp/go.tar.gz
rm /tmp/go.tar.gz
export PATH="/usr/local/go/bin:$PATH"
echo 'export PATH="/usr/local/go/bin:$PATH"' > /etc/profile.d/go.sh
success "Go $(go version) installed."

# ── Step 9. acme.sh ───────────────────────────────────────────────────────────
info "Installing acme.sh…"
curl -fsSL https://get.acme.sh | sh -s email="$ADMIN_EMAIL" >/dev/null 2>&1 || warn "acme.sh install failed — install manually later."
/root/.acme.sh/acme.sh --set-default-ca --server letsencrypt 2>/dev/null || true
success "acme.sh ready."

# ── Step 10. System user ──────────────────────────────────────────────────────
info "Creating system user '${PANEL_USER}'…"
id "$PANEL_USER" &>/dev/null || /usr/sbin/useradd -r -m -d "$INSTALL_DIR" -s /bin/bash "$PANEL_USER"
chmod 755 "$INSTALL_DIR"
success "User '${PANEL_USER}' ready."

# ── Step 11. Clone panel ──────────────────────────────────────────────────────
info "Cloning Strata Hosting Panel…"
if [[ -d "$INSTALL_DIR/panel" ]]; then
    warn "Panel directory exists — pulling latest…"
    git -C "$INSTALL_DIR/panel" pull --ff-only
else
    git clone --depth=1 https://github.com/jonathjan0397/strata-hosting-panel.git /tmp/strata-hosting-panel-src
    PANEL_VERSION="$(cat /tmp/strata-hosting-panel-src/VERSION 2>/dev/null || echo 'dev')"
    mkdir -p "$INSTALL_DIR"
    cp -r /tmp/strata-hosting-panel-src/panel "$INSTALL_DIR/panel"
    cp -r /tmp/strata-hosting-panel-src/agent "$INSTALL_DIR/agent-src"
    cp /tmp/strata-hosting-panel-src/VERSION "$INSTALL_DIR/VERSION" 2>/dev/null || echo "$PANEL_VERSION" > "$INSTALL_DIR/VERSION"
    install -m 755 /tmp/strata-hosting-panel-src/installer/agent-upgrade.sh /usr/sbin/strata-agent-upgrade
    install -m 755 /tmp/strata-hosting-panel-src/installer/upgrade.sh /root/strata-upgrade.sh
    install -m 755 /tmp/strata-hosting-panel-src/installer/upgrade.sh /usr/sbin/strata-upgrade
    cat > /etc/sudoers.d/strata-upgrade <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/sbin/strata-upgrade
EOF
    chmod 440 /etc/sudoers.d/strata-upgrade
    rm -rf /tmp/strata-hosting-panel-src
fi
PANEL_VERSION="${PANEL_VERSION:-$(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo 'dev')}"
success "Panel source ready at $INSTALL_DIR/panel"

# ── Step 12. Panel .env ───────────────────────────────────────────────────────
info "Writing .env…"
cat > "$INSTALL_DIR/panel/.env" <<EOF
APP_NAME="Strata Hosting Panel"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${PANEL_DOMAIN}

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=strata_panel
DB_USERNAME=strata
DB_PASSWORD=${DB_PASSWORD}

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379

STRATA_AGENT_LOCAL_SECRET=${AGENT_HMAC_SECRET}
STRATA_AGENT_LOCAL_NODE_ID=${AGENT_NODE_ID}

STRATA_PDNS_URL=http://127.0.0.1:8053
STRATA_PDNS_API_KEY=${PDNS_API_KEY}
STRATA_DB_ROOT_PASSWORD=${DB_PASSWORD}

# License server — leave blank for Community edition.
STRATA_INSTALL_TOKEN=${INSTALL_TOKEN}
STRATA_INSTALL_SECRET=${INSTALL_SECRET}
STRATA_LICENSE_SERVER_URL=
STRATA_VERSION=${PANEL_VERSION}

# Webmail SSO
STRATA_WEBMAIL_SSO_SECRET=${WEBMAIL_SSO_SECRET}
STRATA_WEBMAIL_URL=/webmail/
STRATA_WEBMAIL_DATA_PATH=${WEBMAIL_DATA}
EOF
chmod 600 "$INSTALL_DIR/panel/.env"

# ── Step 13. Composer install + migrate ───────────────────────────────────────
info "Installing PHP dependencies…"
cd "$INSTALL_DIR/panel"
composer install --no-dev --optimize-autoloader --no-interaction -q

info "Running database migrations and seeding…"
php artisan migrate --force --seed

info "Initial license sync…"
php artisan strata:license-sync 2>/dev/null || warn "License sync skipped (Community edition — OK)."

info "Building frontend assets…"
# Increase Node.js heap for Vite build on low-memory VPS
export NODE_OPTIONS="--max-old-space-size=512"
npm install 2>&1 || die "npm install failed — check Node.js version"
npm run build 2>&1 || die "npm run build failed — see output above"

info "Caching config…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R "$PANEL_USER":www-data "$INSTALL_DIR/panel"
find "$INSTALL_DIR/panel" -type f -exec chmod 644 {} \;
find "$INSTALL_DIR/panel" -type d -exec chmod 755 {} \;
chmod -R 775 "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel/bootstrap/cache"
success "Panel configured."

# ── Step 14. Build agent ──────────────────────────────────────────────────────
info "Building strata-agent…"
cd "$INSTALL_DIR/agent-src"
go mod tidy 2>&1 || die "go mod tidy failed — check network and Go installation"
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/api.Version=${PANEL_VERSION}" \
    -o /usr/sbin/strata-agent \
    .
chmod 755 /usr/sbin/strata-agent
GOOS=linux GOARCH=amd64 go build \
    -o /usr/sbin/strata-webdav \
    ./cmd/strata-webdav
chmod 755 /usr/sbin/strata-webdav
success "strata-agent built."

# ── Step 15. Agent TLS cert ───────────────────────────────────────────────────
info "Generating agent TLS certificate…"
mkdir -p /etc/strata-agent/tls
openssl req -x509 -newkey rsa:4096 -keyout /etc/strata-agent/tls/key.pem \
    -out /etc/strata-agent/tls/cert.pem -days 3650 -nodes \
    -subj "/CN=strata-agent" \
    -addext "subjectAltName=IP:127.0.0.1" >/dev/null 2>&1
chmod 600 /etc/strata-agent/tls/key.pem
success "Agent TLS certificate ready."

# ── Step 16. Agent systemd service ───────────────────────────────────────────
info "Installing strata-agent systemd service…"
cat > /etc/systemd/system/strata-agent.service <<EOF
[Unit]
Description=Strata Agent
After=network.target

[Service]
Type=simple
User=root
ExecStart=/usr/sbin/strata-agent
Restart=always
RestartSec=5
Environment=STRATA_HMAC_SECRET=${AGENT_HMAC_SECRET}
Environment=STRATA_NODE_ID=${AGENT_NODE_ID}
Environment=STRATA_PORT=8743
Environment=STRATA_TLS_CERT=/etc/strata-agent/tls/cert.pem
Environment=STRATA_TLS_KEY=/etc/strata-agent/tls/key.pem
Environment=STRATA_PDNS_API_KEY=${PDNS_API_KEY}
Environment=STRATA_DB_ROOT_PASSWORD=${DB_PASSWORD}
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl daemon-reload
systemctl enable strata-agent
systemctl restart strata-agent
success "strata-agent running."

info "Installing Strata Web Disk systemd service…"
mkdir -p /etc/strata-webdav
touch /etc/strata-webdav/accounts.json
chmod 600 /etc/strata-webdav/accounts.json
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
Environment=STRATA_TLS_CERT=/etc/strata-agent/tls/cert.pem
Environment=STRATA_TLS_KEY=/etc/strata-agent/tls/key.pem

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable strata-webdav
systemctl restart strata-webdav
success "Strata Web Disk running."

cat > /etc/strata-agent/mysql.cnf <<EOF
[client]
user     = root
password = ${DB_PASSWORD}
host     = 127.0.0.1
EOF
chmod 600 /etc/strata-agent/mysql.cnf

# ── Step 17. Queue worker ─────────────────────────────────────────────────────
info "Installing queue worker service…"
cat > /etc/systemd/system/strata-queue.service <<EOF
[Unit]
Description=Strata Hosting Panel Queue Worker
After=network.target redis.service

[Service]
Type=simple
User=${PANEL_USER}
WorkingDirectory=${INSTALL_DIR}/panel
ExecStart=/usr/bin/php${PANEL_PHP_VER} ${INSTALL_DIR}/panel/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable strata-queue
systemctl restart strata-queue
success "Queue worker running."

info "Adding Laravel scheduler cron job…"
(crontab -l -u "$PANEL_USER" 2>/dev/null || true; echo "* * * * * cd ${INSTALL_DIR}/panel && /usr/bin/php${PANEL_PHP_VER} artisan schedule:run >> /dev/null 2>&1") \
    | crontab -u "$PANEL_USER" -
success "Scheduler cron added."

# ── Step 18. Nginx/Apache vhost for panel ─────────────────────────────────────
mkdir -p /etc/strata-panel/tls
mkdir -p "${MAIL_HTTP_ROOT}/.well-known/acme-challenge"
cat > "${MAIL_HTTP_ROOT}/index.html" <<EOF
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${MAIL_DOMAIN}</title>
</head>
<body>
  <p>${MAIL_DOMAIN}</p>
</body>
</html>
EOF

if [[ "$WEB_SERVER" == "apache" ]]; then
    info "Configuring Apache2 for $PANEL_DOMAIN…"
    cat > /etc/apache2/sites-available/strata-mail-http.conf <<EOF
<VirtualHost *:80>
    ServerName ${MAIL_DOMAIN}
    DocumentRoot ${MAIL_HTTP_ROOT}

    <Directory ${MAIL_HTTP_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
EOF
    cat > /etc/apache2/sites-available/strata-panel.conf <<EOF
<VirtualHost *:80>
    ServerName ${PANEL_DOMAIN}
    Redirect permanent / https://${PANEL_DOMAIN}/
</VirtualHost>

<VirtualHost *:443>
    ServerName ${PANEL_DOMAIN}

    SSLEngine on
    SSLCertificateFile    /etc/strata-panel/tls/fullchain.pem
    SSLCertificateKeyFile /etc/strata-panel/tls/privkey.pem
    SSLProtocol           all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite        ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384

    DocumentRoot ${INSTALL_DIR}/panel/public
    DirectoryIndex index.php

    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"

    <Directory ${INSTALL_DIR}/panel/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php${PANEL_PHP_VER}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <FilesMatch "\.(env|git|log|sql)\$">
        Require all denied
    </FilesMatch>

    LimitRequestBody 67108864

    Alias /webmail /var/www/webmail
    <Directory /var/www/webmail>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Alias /phpmyadmin /usr/share/phpmyadmin
    <Directory /usr/share/phpmyadmin>
        Options -Indexes +FollowSymLinks
        DirectoryIndex index.php
        Require all granted
    </Directory>
    Alias /phppgadmin /usr/share/phppgadmin
    <Directory /usr/share/phppgadmin>
        Options -Indexes +FollowSymLinks
        DirectoryIndex index.php
        Require all granted
    </Directory>
    <FilesMatch "^/var/www/webmail/.+\.php\$">
        SetHandler "proxy:unix:/run/php/php${PANEL_PHP_VER}-fpm.sock|fcgi://localhost"
    </FilesMatch>
    <FilesMatch "^/usr/share/(phpmyadmin|phppgadmin)/.+\.php\$">
        SetHandler "proxy:unix:/run/php/php${PANEL_PHP_VER}-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
EOF

    a2ensite strata-mail-http.conf >/dev/null 2>&1
    a2ensite strata-panel.conf >/dev/null 2>&1
    a2dissite 000-default.conf >/dev/null 2>&1 || true

    info "Issuing SSL certificate for $PANEL_DOMAIN…"
    openssl req -x509 -newkey rsa:4096 \
        -keyout /etc/strata-panel/tls/privkey.pem \
        -out    /etc/strata-panel/tls/fullchain.pem \
        -days   90 -nodes -subj "/CN=${PANEL_DOMAIN}" >/dev/null 2>&1
    apache2ctl configtest 2>/dev/null && systemctl restart apache2 || true

    if /root/.acme.sh/acme.sh --issue --apache -d "$PANEL_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
        /root/.acme.sh/acme.sh --install-cert -d "$PANEL_DOMAIN" \
            --key-file       /etc/strata-panel/tls/privkey.pem \
            --fullchain-file /etc/strata-panel/tls/fullchain.pem \
            --reloadcmd      "systemctl reload apache2"
        success "SSL certificate issued."
    else
        warn "Let's Encrypt failed — using self-signed cert. Re-run once DNS is ready:"
        warn "  /root/.acme.sh/acme.sh --issue --apache -d ${PANEL_DOMAIN}"
        cp /etc/strata-panel/tls/fullchain.pem /usr/local/share/ca-certificates/strata-panel.crt
        /usr/sbin/update-ca-certificates >/dev/null || warn "Could not add self-signed panel certificate to local CA trust store."
    fi

    apache2ctl configtest && systemctl restart apache2
    success "Apache2 configured."

    # Switch agent to use panel TLS cert now that it exists (browser-trusted)
    sed -i "s|STRATA_TLS_CERT=.*|STRATA_TLS_CERT=/etc/strata-panel/tls/fullchain.pem|" /etc/systemd/system/strata-agent.service
    sed -i "s|STRATA_TLS_KEY=.*|STRATA_TLS_KEY=/etc/strata-panel/tls/privkey.pem|" /etc/systemd/system/strata-agent.service
    sed -i "s|STRATA_TLS_CERT=.*|STRATA_TLS_CERT=/etc/strata-panel/tls/fullchain.pem|" /etc/systemd/system/strata-webdav.service
    sed -i "s|STRATA_TLS_KEY=.*|STRATA_TLS_KEY=/etc/strata-panel/tls/privkey.pem|" /etc/systemd/system/strata-webdav.service
    systemctl daemon-reload && systemctl restart strata-agent strata-webdav
    success "Agent and Web Disk switched to panel TLS certificate."

else
    info "Configuring Nginx for $PANEL_DOMAIN…"
    cat > /etc/nginx/sites-available/strata-mail-http <<EOF
server {
    listen 80;
    server_name ${MAIL_DOMAIN};
    root ${MAIL_HTTP_ROOT};
    index index.html;

    location /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }
}
EOF
    cat > /etc/nginx/sites-available/strata-panel <<EOF
server {
    listen 80;
    server_name ${PANEL_DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name ${PANEL_DOMAIN};

    ssl_certificate     /etc/strata-panel/tls/fullchain.pem;
    ssl_certificate_key /etc/strata-panel/tls/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    root ${INSTALL_DIR}/panel/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /phpmyadmin { return 301 /phpmyadmin/; }
    location /phpmyadmin/ {
        alias /usr/share/phpmyadmin/;
        index index.php;
        try_files \$uri \$uri/ /phpmyadmin/index.php?\$query_string;
    }

    location ~ ^/phpmyadmin/(.+\.php)\$ {
        alias /usr/share/phpmyadmin/\$1;
        fastcgi_pass unix:/run/php/php${PANEL_PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /usr/share/phpmyadmin/\$1;
        include fastcgi_params;
    }

    location = /phppgadmin { return 301 /phppgadmin/; }
    location /phppgadmin/ {
        alias /usr/share/phppgadmin/;
        index index.php;
        try_files \$uri \$uri/ /phppgadmin/index.php?\$query_string;
    }

    location ~ ^/phppgadmin/(.+\.php)\$ {
        alias /usr/share/phppgadmin/\$1;
        fastcgi_pass unix:/run/php/php${PANEL_PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /usr/share/phppgadmin/\$1;
        include fastcgi_params;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PANEL_PHP_VER}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }
    location ~* \.(env|git|log|sql)\$ { deny all; }

    client_max_body_size 64M;

    location /webmail {
        root /var/www;
        index index.php;
        try_files \$uri \$uri/ /webmail/index.php?\$query_string;

        location ~ ^/webmail/.+\.php\$ {
            root /var/www;
            fastcgi_pass unix:/run/php/php${PANEL_PHP_VER}-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include fastcgi_params;
        }

        location ~* ^/webmail/.+\.(js|css|png|jpg|gif|ico|svg|woff|woff2|ttf)\$ {
            root /var/www;
            expires 30d;
            add_header Cache-Control "public, immutable";
        }
    }
}
EOF

    ln -sf /etc/nginx/sites-available/strata-mail-http /etc/nginx/sites-enabled/strata-mail-http
    ln -sf /etc/nginx/sites-available/strata-panel /etc/nginx/sites-enabled/strata-panel
    rm -f /etc/nginx/sites-enabled/default

    info "Issuing SSL certificate for $PANEL_DOMAIN…"
    openssl req -x509 -newkey rsa:4096 \
        -keyout /etc/strata-panel/tls/privkey.pem \
        -out    /etc/strata-panel/tls/fullchain.pem \
        -days   90 -nodes -subj "/CN=${PANEL_DOMAIN}" >/dev/null 2>&1
    nginx -t 2>/dev/null && systemctl reload nginx || true

    if /root/.acme.sh/acme.sh --issue --nginx -d "$PANEL_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
        /root/.acme.sh/acme.sh --install-cert -d "$PANEL_DOMAIN" \
            --key-file       /etc/strata-panel/tls/privkey.pem \
            --fullchain-file /etc/strata-panel/tls/fullchain.pem \
            --reloadcmd      "systemctl reload nginx"
        success "SSL certificate issued."
    else
        warn "Let's Encrypt failed — using self-signed cert. Re-run once DNS is ready:"
        warn "  /root/.acme.sh/acme.sh --issue --nginx -d ${PANEL_DOMAIN}"
        cp /etc/strata-panel/tls/fullchain.pem /usr/local/share/ca-certificates/strata-panel.crt
        /usr/sbin/update-ca-certificates >/dev/null || warn "Could not add self-signed panel certificate to local CA trust store."
    fi

    nginx -t && systemctl reload nginx
    success "Nginx configured."

    # Switch agent to use panel TLS cert now that it exists (browser-trusted)
    sed -i "s|STRATA_TLS_CERT=.*|STRATA_TLS_CERT=/etc/strata-panel/tls/fullchain.pem|" /etc/systemd/system/strata-agent.service
    sed -i "s|STRATA_TLS_KEY=.*|STRATA_TLS_KEY=/etc/strata-panel/tls/privkey.pem|" /etc/systemd/system/strata-agent.service
    sed -i "s|STRATA_TLS_CERT=.*|STRATA_TLS_CERT=/etc/strata-panel/tls/fullchain.pem|" /etc/systemd/system/strata-webdav.service
    sed -i "s|STRATA_TLS_KEY=.*|STRATA_TLS_KEY=/etc/strata-panel/tls/privkey.pem|" /etc/systemd/system/strata-webdav.service
    systemctl daemon-reload && systemctl restart strata-agent strata-webdav
    success "Agent and Web Disk switched to panel TLS certificate."
fi

# ── Step 19. Firewall (UFW) ───────────────────────────────────────────────────
if [[ "$PANEL_DOMAIN" != "$HOSTNAME_PARENT_DOMAIN" ]]; then
    info "Configuring placeholder site for ${HOSTNAME_PARENT_DOMAIN}…"
    PLACEHOLDER_ROOT="/var/www/strata-placeholder"
    PLACEHOLDER_TLS_DIR="/etc/strata-panel/apex-tls"
    mkdir -p "$PLACEHOLDER_ROOT" "$PLACEHOLDER_TLS_DIR"

    cat > "${PLACEHOLDER_ROOT}/index.html" <<EOF
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${HOSTNAME_PARENT_DOMAIN}</title>
  <style>
    :root { color-scheme: light; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #f4efe6, #d9e7f5);
      color: #18222f;
      font-family: Georgia, "Times New Roman", serif;
    }
    main {
      width: min(92vw, 720px);
      padding: 48px;
      border-radius: 24px;
      background: rgba(255,255,255,0.85);
      box-shadow: 0 24px 80px rgba(24,34,47,0.16);
    }
    h1 { margin: 0 0 16px; font-size: clamp(2rem, 5vw, 3.5rem); }
    p { margin: 0 0 12px; line-height: 1.6; font-size: 1.05rem; }
    .muted { color: #51606f; }
  </style>
</head>
<body>
  <main>
    <h1>${HOSTNAME_PARENT_DOMAIN}</h1>
    <p>This server is online and managed by Strata Hosting Panel.</p>
    <p class="muted">The root domain is reserved for the admin website and is currently showing this placeholder page.</p>
  </main>
</body>
</html>
EOF

    openssl req -x509 -newkey rsa:4096 \
        -keyout "${PLACEHOLDER_TLS_DIR}/privkey.pem" \
        -out    "${PLACEHOLDER_TLS_DIR}/fullchain.pem" \
        -days   90 -nodes -subj "/CN=${HOSTNAME_PARENT_DOMAIN}" >/dev/null 2>&1

    if [[ "$WEB_SERVER" == "apache" ]]; then
        cat > /etc/apache2/sites-available/zzzz-strata-placeholder.conf <<EOF
<VirtualHost *:80>
    ServerName ${HOSTNAME_PARENT_DOMAIN}
    DocumentRoot ${PLACEHOLDER_ROOT}
</VirtualHost>

<VirtualHost *:443>
    ServerName ${HOSTNAME_PARENT_DOMAIN}
    DocumentRoot ${PLACEHOLDER_ROOT}

    SSLEngine on
    SSLCertificateFile    ${PLACEHOLDER_TLS_DIR}/fullchain.pem
    SSLCertificateKeyFile ${PLACEHOLDER_TLS_DIR}/privkey.pem

    <Directory ${PLACEHOLDER_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
EOF
        a2ensite zzzz-strata-placeholder.conf >/dev/null 2>&1
        apache2ctl configtest && systemctl reload apache2
        if /root/.acme.sh/acme.sh --issue --apache -d "$HOSTNAME_PARENT_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$HOSTNAME_PARENT_DOMAIN" \
                --key-file       "${PLACEHOLDER_TLS_DIR}/privkey.pem" \
                --fullchain-file "${PLACEHOLDER_TLS_DIR}/fullchain.pem" \
                --reloadcmd      "systemctl reload apache2"
        else
            warn "Placeholder HTTPS certificate for ${HOSTNAME_PARENT_DOMAIN} is self-signed until Let's Encrypt succeeds."
        fi
        apache2ctl configtest && systemctl reload apache2
    else
        cat > /etc/nginx/sites-available/zzzz-strata-placeholder <<EOF
server {
    listen 80;
    server_name ${HOSTNAME_PARENT_DOMAIN};
    root ${PLACEHOLDER_ROOT};
    index index.html;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

server {
    listen 443 ssl http2;
    server_name ${HOSTNAME_PARENT_DOMAIN};
    root ${PLACEHOLDER_ROOT};
    index index.html;

    ssl_certificate     ${PLACEHOLDER_TLS_DIR}/fullchain.pem;
    ssl_certificate_key ${PLACEHOLDER_TLS_DIR}/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    location / {
        try_files \$uri \$uri/ =404;
    }
}
EOF
        ln -sf /etc/nginx/sites-available/zzzz-strata-placeholder /etc/nginx/sites-enabled/zzzz-strata-placeholder
        nginx -t && systemctl reload nginx
        if /root/.acme.sh/acme.sh --issue --nginx -d "$HOSTNAME_PARENT_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$HOSTNAME_PARENT_DOMAIN" \
                --key-file       "${PLACEHOLDER_TLS_DIR}/privkey.pem" \
                --fullchain-file "${PLACEHOLDER_TLS_DIR}/fullchain.pem" \
                --reloadcmd      "systemctl reload nginx"
        else
            warn "Placeholder HTTPS certificate for ${HOSTNAME_PARENT_DOMAIN} is self-signed until Let's Encrypt succeeds."
        fi
        nginx -t && systemctl reload nginx
    fi

    success "Placeholder site configured for ${HOSTNAME_PARENT_DOMAIN}."
fi

info "Configuring UFW firewall…"
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow ssh comment "SSH"                     >/dev/null
ufw allow 80/tcp comment "HTTP"                 >/dev/null
ufw allow 443/tcp comment "HTTPS"               >/dev/null
ufw allow 53/tcp comment "DNS TCP"              >/dev/null
ufw allow 53/udp comment "DNS UDP"              >/dev/null
ufw allow 21/tcp comment "FTP control"          >/dev/null
ufw allow 30000:50000/tcp comment "FTP passive" >/dev/null
ufw allow 25/tcp comment "SMTP"                 >/dev/null
ufw allow 465/tcp comment "SMTPS"               >/dev/null
ufw allow 587/tcp comment "SMTP submission"     >/dev/null
ufw allow 993/tcp comment "IMAPS"               >/dev/null
ufw allow 995/tcp comment "POP3S"               >/dev/null
ufw allow 2078/tcp comment "Strata Web Disk"    >/dev/null
ufw allow 8743/tcp comment "Strata agent (shell/WebSocket)" >/dev/null
ufw --force enable >/dev/null
success "Firewall enabled."

# ── Step 20. fail2ban jails ───────────────────────────────────────────────────
info "Configuring fail2ban…"
cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 10
backend  = systemd

[sshd]
enabled  = true
port     = ssh
maxretry = 10
logpath  = %(sshd_log)s

[postfix]
enabled  = true
port     = smtp,465,submission
maxretry = 10
logpath  = %(postfix_log)s

[postfix-sasl]
enabled  = true
port     = smtp,465,submission
maxretry = 10
logpath  = %(postfix_log)s

[dovecot]
enabled  = true
port     = imap,imaps,pop3,pop3s,submission,465,sieve
maxretry = 10
logpath  = %(dovecot_log)s

[nginx-http-auth]
enabled  = true
port     = http,https
maxretry = 10
logpath  = %(nginx_error_log)s

[pure-ftpd]
enabled  = true
port     = ftp,ftp-data,ftps,30000:50000
maxretry = 10

[apache-auth]
enabled  = true
port     = http,https
maxretry = 10

[recidive]
enabled  = true
bantime  = 86400
findtime = 86400
maxretry = 5
EOF

systemctl enable --now fail2ban
if compgen -G "/var/lib/clamav/daily.*" >/dev/null; then
    systemctl restart clamav-daemon || warn "clamav-daemon did not restart â€” clamscan can still run on demand."
fi
success "fail2ban configured (SSH, mail, FTP, web auth, and recidive jails active by default)."

# ── Step 21. Register primary node ───────────────────────────────────────────
info "Registering primary node in panel database…"
cat > /tmp/strata-register-node.php <<'PHPEOF'
<?php
require getenv('INSTALL_DIR') . '/panel/vendor/autoload.php';
$app = require getenv('INSTALL_DIR') . '/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Node;
Node::updateOrCreate(
    ['node_id' => getenv('AGENT_NODE_ID')],
    [
        'name'         => 'Primary',
        'hostname'     => getenv('PANEL_DOMAIN'),
        'ip_address'   => getenv('SERVER_IP'),
        'port'         => 8743,
        'hmac_secret'  => getenv('AGENT_HMAC_SECRET'),
        'web_server'   => getenv('WEB_SERVER'),
        'status'       => 'online',
        'is_primary'   => true,
        'hosts_dns'    => true,
        'last_seen_at' => now(),
    ]
);
echo "done\n";
PHPEOF
INSTALL_DIR="$INSTALL_DIR" AGENT_NODE_ID="$AGENT_NODE_ID" PANEL_DOMAIN="$PANEL_DOMAIN" \
    AGENT_HMAC_SECRET="$AGENT_HMAC_SECRET" WEB_SERVER="$WEB_SERVER" SERVER_IP="$SERVER_IP" \
    php /tmp/strata-register-node.php || die "Failed to register primary node"
rm -f /tmp/strata-register-node.php
success "Primary node registered."

info "Bootstrapping host DNS zone..."
cat > /tmp/strata-bootstrap-host-dns.php <<'PHPEOF'
<?php
require getenv('INSTALL_DIR') . '/panel/vendor/autoload.php';
$app = require getenv('INSTALL_DIR') . '/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DnsZone;
use App\Models\Node;
use App\Services\AgentClient;
use App\Services\DnsProvisioner;

$zoneName = strtolower(trim((string) getenv('HOSTNAME_PARENT_DOMAIN'), '.'));
$panelDomain = strtolower(trim((string) getenv('PANEL_DOMAIN'), '.'));
$serverHostname = strtolower(trim((string) getenv('HOSTNAME_FQDN'), '.'));
$serverIp = trim((string) getenv('SERVER_IP'));

if ($zoneName === '' || $serverIp === '') {
    fwrite(STDERR, "Host DNS bootstrap requires HOSTNAME_PARENT_DOMAIN and SERVER_IP.\n");
    exit(1);
}

$primary = Node::where('is_primary', true)->orderBy('id')->firstOrFail();
$client = AgentClient::for($primary);
$provisioner = new DnsProvisioner($client);
$nameservers = $provisioner->authoritativeNameservers();

$response = $client->createDnsZone($zoneName, $nameservers);
if (! DnsProvisioner::zoneProvisionResponseIsUsable($response)) {
    fwrite(STDERR, "Zone creation failed: " . trim($response->body()) . "\n");
    exit(1);
}

$zone = DnsZone::firstOrCreate(
    ['zone_name' => $zoneName],
    ['domain_id' => null, 'account_id' => null, 'node_id' => $primary->id, 'active' => true]
);

$relativeName = static function (string $fqdn, string $zone): ?string {
    $fqdn = strtolower(trim($fqdn, '.'));
    $zone = strtolower(trim($zone, '.'));

    if ($fqdn === '' || $zone === '') {
        return null;
    }

    if ($fqdn === $zone) {
        return '@';
    }

    $suffix = '.' . $zone;
    if (! str_ends_with($fqdn, $suffix)) {
        return null;
    }

    return substr($fqdn, 0, -strlen($suffix));
};

$addressType = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
$mailHost = 'mail.' . $zoneName . '.';
$ipMechanism = $addressType === 'AAAA' ? "ip6:{$serverIp}" : "ip4:{$serverIp}";
$records = [
    ['@', 'NS', 3600, $nameservers, null],
    ['@', $addressType, 300, [$serverIp], null],
    ['mail', $addressType, 300, [$serverIp], null],
    ['@', 'MX', 300, [$mailHost], 10],
    ['@', 'TXT', 300, ["v=spf1 a mx {$ipMechanism} -all"], null],
    ['_dmarc', 'TXT', 300, ["v=DMARC1; p=quarantine; pct=100; rua=mailto:postmaster@{$zoneName}"], null],
    ['smtp', 'CNAME', 300, [$mailHost], null],
    ['imap', 'CNAME', 300, [$mailHost], null],
    ['pop', 'CNAME', 300, [$mailHost], null],
    ['webmail', 'CNAME', 300, [$mailHost], null],
    ['@', 'CAA', 300, ['0 issue "letsencrypt.org"'], null],
];

$panelRelative = $relativeName($panelDomain, $zoneName);
if ($panelRelative && $panelRelative !== '@') {
    $records[] = [$panelRelative, $addressType, 300, [$serverIp], null];
}

$hostnameRelative = $relativeName($serverHostname, $zoneName);
if ($hostnameRelative && $hostnameRelative !== '@' && $hostnameRelative !== $panelRelative) {
    $records[] = [$hostnameRelative, $addressType, 300, [$serverIp], null];
}

$dnsNodes = Node::whereNull('deleted_at')->where('hosts_dns', true)->where('status', 'online')->orderByDesc('is_primary')->orderBy('id')->get();
if ($dnsNodes->isEmpty()) {
    $dnsNodes = Node::whereNull('deleted_at')->where('hosts_dns', true)->orderByDesc('is_primary')->orderBy('id')->get();
}

foreach ($dnsNodes as $index => $node) {
    $nodeIp = trim((string) $node->ip_address);
    if ($nodeIp === '') {
        continue;
    }

    $records[] = [
        'ns' . ($index + 1),
        filter_var($nodeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
        300,
        [$nodeIp],
        null,
    ];
}

foreach ($records as [$name, $type, $ttl, $contents, $priority]) {
    [$ok, $error] = $provisioner->addRecord($zone, $name, $type, $ttl, $contents, true, $priority);
    if (! $ok) {
        fwrite(STDERR, sprintf("Record bootstrap failed for %s %s: %s\n", $type, $name, $error));
        exit(1);
    }
}

echo "done\n";
PHPEOF
INSTALL_DIR="$INSTALL_DIR" HOSTNAME_PARENT_DOMAIN="$HOSTNAME_PARENT_DOMAIN" PANEL_DOMAIN="$PANEL_DOMAIN" \
    HOSTNAME_FQDN="$HOSTNAME_FQDN" SERVER_IP="$SERVER_IP" \
    php /tmp/strata-bootstrap-host-dns.php || die "Failed to bootstrap host DNS zone"
rm -f /tmp/strata-bootstrap-host-dns.php
success "Host DNS zone bootstrapped."

info "Retrying public TLS issuance after DNS bootstrap..."
if [[ "$WEB_SERVER" == "apache" ]]; then
    if /root/.acme.sh/acme.sh --issue --apache -d "$PANEL_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
        /root/.acme.sh/acme.sh --install-cert -d "$PANEL_DOMAIN" \
            --key-file       /etc/strata-panel/tls/privkey.pem \
            --fullchain-file /etc/strata-panel/tls/fullchain.pem \
            --reloadcmd      "systemctl reload apache2"
        systemctl reload apache2
        success "Panel TLS certificate refreshed after DNS bootstrap."
    else
        warn "Panel TLS certificate is still self-signed; retry once public DNS propagation completes."
    fi

    if [[ "$PANEL_DOMAIN" != "$HOSTNAME_PARENT_DOMAIN" ]] && [[ -f /etc/apache2/sites-available/zzzz-strata-placeholder.conf ]]; then
        if /root/.acme.sh/acme.sh --issue --apache -d "$HOSTNAME_PARENT_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$HOSTNAME_PARENT_DOMAIN" \
                --key-file       /etc/strata-panel/apex-tls/privkey.pem \
                --fullchain-file /etc/strata-panel/apex-tls/fullchain.pem \
                --reloadcmd      "systemctl reload apache2"
            systemctl reload apache2
            success "Apex placeholder TLS certificate issued."
        else
            warn "Apex placeholder TLS certificate is still self-signed; retry once public DNS propagation completes."
        fi
    fi

    if [[ -f /etc/apache2/sites-available/strata-mail-http.conf ]]; then
        if /root/.acme.sh/acme.sh --issue -d "$MAIL_DOMAIN" -w "$MAIL_HTTP_ROOT" --server letsencrypt --keylength ec-256 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$MAIL_DOMAIN" --ecc \
                --key-file       "${MAIL_TLS_DIR}/privkey.pem" \
                --fullchain-file "${MAIL_TLS_DIR}/fullchain.pem" \
                --reloadcmd      "systemctl restart dovecot postfix"
            systemctl restart dovecot postfix
            success "Mail TLS certificate issued."
        else
            warn "Mail TLS certificate is still self-signed; retry once public DNS propagation completes."
        fi
    fi
else
    if /root/.acme.sh/acme.sh --issue --nginx -d "$PANEL_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
        /root/.acme.sh/acme.sh --install-cert -d "$PANEL_DOMAIN" \
            --key-file       /etc/strata-panel/tls/privkey.pem \
            --fullchain-file /etc/strata-panel/tls/fullchain.pem \
            --reloadcmd      "systemctl reload nginx"
        systemctl reload nginx
        success "Panel TLS certificate refreshed after DNS bootstrap."
    else
        warn "Panel TLS certificate is still self-signed; retry once public DNS propagation completes."
    fi

    if [[ "$PANEL_DOMAIN" != "$HOSTNAME_PARENT_DOMAIN" ]] && [[ -f /etc/nginx/sites-available/zzzz-strata-placeholder ]]; then
        if /root/.acme.sh/acme.sh --issue --nginx -d "$HOSTNAME_PARENT_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$HOSTNAME_PARENT_DOMAIN" \
                --key-file       /etc/strata-panel/apex-tls/privkey.pem \
                --fullchain-file /etc/strata-panel/apex-tls/fullchain.pem \
                --reloadcmd      "systemctl reload nginx"
            systemctl reload nginx
            success "Apex placeholder TLS certificate issued."
        else
            warn "Apex placeholder TLS certificate is still self-signed; retry once public DNS propagation completes."
        fi
    fi

    if [[ -f /etc/nginx/sites-available/strata-mail-http ]]; then
        if /root/.acme.sh/acme.sh --issue -d "$MAIL_DOMAIN" -w "$MAIL_HTTP_ROOT" --server letsencrypt --keylength ec-256 >/dev/null 2>&1; then
            /root/.acme.sh/acme.sh --install-cert -d "$MAIL_DOMAIN" --ecc \
                --key-file       "${MAIL_TLS_DIR}/privkey.pem" \
                --fullchain-file "${MAIL_TLS_DIR}/fullchain.pem" \
                --reloadcmd      "systemctl restart dovecot postfix"
            systemctl restart dovecot postfix
            success "Mail TLS certificate issued."
        else
            warn "Mail TLS certificate is still self-signed; retry once public DNS propagation completes."
        fi
    fi
fi

# ── Step 22. SnappyMail webmail ───────────────────────────────────────────────
info "Installing SnappyMail v${SNAPPYMAIL_VERSION}…"
mkdir -p "$WEBMAIL_DIR" "$WEBMAIL_DATA"

SNAPPY_ZIP="/tmp/snappymail-${SNAPPYMAIL_VERSION}.zip"
wget -q "https://github.com/the-djmaze/snappymail/releases/download/v${SNAPPYMAIL_VERSION}/snappymail-${SNAPPYMAIL_VERSION}.zip" \
    -O "$SNAPPY_ZIP" || warn "SnappyMail download failed — install manually."

if [[ -f "$SNAPPY_ZIP" ]]; then
    unzip -oq "$SNAPPY_ZIP" -d "$WEBMAIL_DIR"
    rm "$SNAPPY_ZIP"

    if [[ -d "$WEBMAIL_DIR/data" ]]; then
        mv "$WEBMAIL_DIR/data" "$WEBMAIL_DATA" 2>/dev/null || true
    fi
    mkdir -p "$WEBMAIL_DATA/_data_/_default_/configs"
    mkdir -p "$WEBMAIL_DATA/_data_/_default_/themes"

    cat > "$WEBMAIL_DIR/include.php" <<EOF
<?php
define('APP_DATA_FOLDER_PATH', '${WEBMAIL_DATA}/');
EOF

    SNAPPY_SRC="$INSTALL_DIR/agent-src"
    if [[ -f "${SNAPPY_SRC}/../webmail-skin/config/application.ini.template" ]]; then
        cp "${SNAPPY_SRC}/../webmail-skin/config/application.ini.template" \
           "$WEBMAIL_DATA/_data_/_default_/configs/application.ini"
    fi
    mkdir -p "$WEBMAIL_DATA/_data_/_default_/domains"
    cat > "$WEBMAIL_DATA/_data_/_default_/domains/default.json" <<'JSON'
{
    "IMAP": {
        "host": "127.0.0.1",
        "port": 993,
        "type": 1,
        "timeout": 300,
        "shortLogin": false,
        "lowerLogin": true,
        "sasl": ["SCRAM-SHA3-512", "SCRAM-SHA-512", "SCRAM-SHA-256", "SCRAM-SHA-1", "PLAIN", "LOGIN"],
        "ssl": {
            "verify_peer": false,
            "verify_peer_name": false,
            "allow_self_signed": true,
            "SNI_enabled": true,
            "disable_compression": true,
            "security_level": 1
        },
        "disabled_capabilities": ["METADATA", "OBJECTID", "PREVIEW", "STATUS=SIZE"],
        "use_expunge_all_on_delete": false,
        "fast_simple_search": true,
        "force_select": false,
        "message_all_headers": false,
        "message_list_limit": 10000,
        "search_filter": "",
        "spam_headers": "",
        "virus_headers": ""
    },
    "SMTP": {
        "host": "127.0.0.1",
        "port": 587,
        "type": 2,
        "timeout": 60,
        "shortLogin": false,
        "lowerLogin": true,
        "sasl": ["SCRAM-SHA3-512", "SCRAM-SHA-512", "SCRAM-SHA-256", "SCRAM-SHA-1", "PLAIN", "LOGIN"],
        "ssl": {
            "verify_peer": false,
            "verify_peer_name": false,
            "allow_self_signed": true,
            "SNI_enabled": true,
            "disable_compression": true,
            "security_level": 1
        },
        "useAuth": true,
        "setSender": false,
        "usePhpMail": false
    },
    "Sieve": {
        "host": "",
        "port": 4190,
        "type": 0,
        "timeout": 10,
        "shortLogin": false,
        "lowerLogin": true,
        "sasl": ["SCRAM-SHA3-512", "SCRAM-SHA-512", "SCRAM-SHA-256", "SCRAM-SHA-1", "PLAIN", "LOGIN"],
        "ssl": {
            "verify_peer": false,
            "verify_peer_name": false,
            "allow_self_signed": true,
            "SNI_enabled": true,
            "disable_compression": true,
            "security_level": 1
        },
        "enabled": false
    },
    "whiteList": ""
}
JSON
    if [[ -d "${SNAPPY_SRC}/../webmail-skin/themes/strata-dark" ]]; then
        cp -r "${SNAPPY_SRC}/../webmail-skin/themes/strata-dark" \
            "$WEBMAIL_DATA/_data_/_default_/themes/Strata Dark"
    fi
    if [[ -f "${SNAPPY_SRC}/../webmail-skin/sso.php" ]]; then
        cp "${SNAPPY_SRC}/../webmail-skin/sso.php" "$WEBMAIL_DIR/sso.php"
    fi

    # Set SnappyMail admin password
    php -r "
        if (file_exists('${WEBMAIL_DIR}/snappymail/v/0.0.0/app/include.php')) {
            define('APP_DATA_FOLDER_PATH', '${WEBMAIL_DATA}/');
            require_once '${WEBMAIL_DIR}/snappymail/v/0.0.0/app/include.php';
            if (class_exists('RainLoop\\\\Api')) {
                \\\$cfg = \\\\RainLoop\\\\Api::Config();
                \\\$cfg->Set('security', 'admin_password', hash('sha256', '${SNAPPYMAIL_ADMIN_PASS}'));
                \\\$cfg->Save();
            }
        }
    " 2>/dev/null || true

    chown -R www-data:www-data "$WEBMAIL_DIR" "$WEBMAIL_DATA"
    find "$WEBMAIL_DIR" -type f -exec chmod 644 {} \;
    find "$WEBMAIL_DIR" -type d -exec chmod 755 {} \;
    find "$WEBMAIL_DATA" -type f -exec chmod 600 {} \;
    find "$WEBMAIL_DATA" -type d -exec chmod 700 {} \;
    success "SnappyMail installed."
fi

(cd "$INSTALL_DIR/panel" && php artisan strata:webmail-configure 2>/dev/null) || warn "SnappyMail managed domain profile repair skipped."

mkdir -p /etc/strata-panel
cat > /etc/strata-panel/webmail-sso.php <<EOF
<?php
return [
    'hmac_secret'    => '${WEBMAIL_SSO_SECRET}',
    'redis_host'     => '127.0.0.1',
    'redis_port'     => 6379,
    'redis_password' => '${REDIS_PASSWORD}',
    'redis_db'       => 0,
    'webmail_root'   => '${WEBMAIL_DIR}',
    'data_path'      => '${WEBMAIL_DATA}/',
    'token_ttl'      => 60,
];
EOF
    chmod 644 "$WEBMAIL_DIR/include.php"
    chown www-data:www-data "$WEBMAIL_DIR/include.php"
    chmod 600 /etc/strata-panel/webmail-sso.php

# ── Step 23. Set admin account ────────────────────────────────────────────────
info "Setting up admin account…"
cat > /tmp/strata-set-admin.php <<'PHPEOF'
<?php
require getenv('INSTALL_DIR') . '/panel/vendor/autoload.php';
$app = require getenv('INSTALL_DIR') . '/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
$u = User::where('email', 'admin@localhost')
         ->orWhere('email', getenv('ADMIN_EMAIL'))
         ->first();
if ($u) {
    $u->update([
        'name'     => getenv('ADMIN_NAME'),
        'email'    => getenv('ADMIN_EMAIL'),
        'password' => bcrypt(getenv('ADMIN_PASSWORD')),
    ]);
    echo "done\n";
} else {
    fwrite(STDERR, "Admin user not found\n");
    exit(1);
}
PHPEOF
INSTALL_DIR="$INSTALL_DIR" ADMIN_EMAIL="$ADMIN_EMAIL" \
    ADMIN_NAME="$ADMIN_NAME" ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    php /tmp/strata-set-admin.php || die "Failed to configure admin account"
rm -f /tmp/strata-set-admin.php
success "Admin account configured: ${ADMIN_EMAIL}"

# ── Step 24. Save credentials ─────────────────────────────────────────────────
CREDS_FILE="/root/strata-credentials.txt"
cat > "$CREDS_FILE" <<EOF
# ============================================================
#  Strata Hosting Panel — Installation Credentials
#  Generated: $(date)
#  KEEP THIS FILE SECURE. chmod 600 is set automatically.
# ============================================================

Server hostname:    ${HOSTNAME_FQDN}
Server IP:          ${SERVER_IP}
Panel URL:          https://${PANEL_DOMAIN}
Webmail URL:        https://${PANEL_DOMAIN}/webmail/
Hosting data root:  ${HOSTING_STORAGE_ROOT}  (bind-mounted to ${HOSTING_BIND_TARGET})
Backup data root:   ${BACKUP_STORAGE_ROOT}  (bind-mounted to ${BACKUP_BIND_TARGET})

Admin email:        ${ADMIN_EMAIL}
Admin name:         ${ADMIN_NAME}
Admin password:     (as entered during install — not stored here)

MariaDB root pass:  ${DB_PASSWORD}
MariaDB strata user: strata / ${DB_PASSWORD}  (db: strata_panel)
PowerDNS DB pass:   ${PDNS_DB_PASSWORD}        (user: pdns, db: pdns)
Redis password:     ${REDIS_PASSWORD}
SnappyMail admin:   https://${PANEL_DOMAIN}/webmail/?admin
  Webmail admin pw: ${SNAPPYMAIL_ADMIN_PASS}

Agent HMAC secret:  ${AGENT_HMAC_SECRET}
Agent node ID:      ${AGENT_NODE_ID}
PowerDNS API key:   ${PDNS_API_KEY}
Install token:      ${INSTALL_TOKEN}

Files:
  Panel .env:       ${INSTALL_DIR}/panel/.env
  MariaDB client:   /root/.my.cnf
  Agent config:     /etc/strata-agent/
  fail2ban jails:   /etc/fail2ban/jail.local

Logs:
  Panel:            ${INSTALL_DIR}/panel/storage/logs/laravel.log
  Agent:            journalctl -u strata-agent -f
  Queue:            journalctl -u strata-queue -f
  Nginx/Apache:     /var/log/nginx/ or /var/log/apache2/
  Mail:             /var/log/mail.log
EOF
chmod 600 "$CREDS_FILE"
success "Credentials saved to ${CREDS_FILE}"

# Disarm the error trap now that everything succeeded
trap - ERR

# ── Step 25. Generate uninstall script ───────────────────────────────────────
info "Generating uninstall script…"
cat > /root/strata-uninstall.sh <<UNINSTEOF
#!/usr/bin/env bash
# =============================================================================
#  Strata Hosting Panel — Uninstaller
#  Generated: $(date)
#  Run as root to fully remove Strata Hosting Panel from this server.
# =============================================================================
set -euo pipefail
[[ \$EUID -eq 0 ]] || { echo "Must be run as root."; exit 1; }

read -rp "This will PERMANENTLY remove Strata Hosting Panel and all its data. Type YES to confirm: " _CONFIRM
[[ "\$_CONFIRM" == "YES" ]] || { echo "Aborted."; exit 0; }

echo "[*] Stopping services…"
for svc in strata-agent strata-webdav strata-queue; do
    systemctl stop    "\$svc" 2>/dev/null || true
    systemctl disable "\$svc" 2>/dev/null || true
    rm -f "/etc/systemd/system/\${svc}.service"
done
systemctl daemon-reload

echo "[*] Dropping databases…"
mysql -u root -p'${DB_PASSWORD}' -h 127.0.0.1 2>/dev/null <<SQLEOF || true
DROP DATABASE IF EXISTS strata_panel;
DROP USER IF EXISTS 'strata'@'localhost';
DROP DATABASE IF EXISTS pdns;
DROP USER IF EXISTS 'pdns'@'localhost';
FLUSH PRIVILEGES;
SQLEOF

echo "[*] Removing files and directories…"
rm -rf '${INSTALL_DIR}'
rm -rf '${WEBMAIL_DIR}'
rm -rf '${WEBMAIL_DATA}'
rm -rf /etc/strata-agent
rm -rf /etc/strata-webdav
rm -rf /etc/strata-panel
rm -f  /usr/sbin/strata-agent
rm -f  /usr/sbin/strata-webdav
rm -f  /root/.my.cnf
if mountpoint -q '${HOSTING_BIND_TARGET}'; then umount '${HOSTING_BIND_TARGET}' 2>/dev/null || true; fi
if mountpoint -q '${BACKUP_BIND_TARGET}'; then umount '${BACKUP_BIND_TARGET}' 2>/dev/null || true; fi
grep -vF '# strata-hosting-storage' /etc/fstab | grep -vF '# strata-backup-storage' > /etc/fstab.strata.tmp 2>/dev/null || true
if [[ -f /etc/fstab.strata.tmp ]]; then mv /etc/fstab.strata.tmp /etc/fstab; fi

echo "[*] Removing system user '${PANEL_USER}'…"
crontab -r -u '${PANEL_USER}' 2>/dev/null || true
/usr/sbin/userdel -r '${PANEL_USER}' 2>/dev/null || true

echo "[*] Removing web vhost configs…"
rm -f /etc/nginx/sites-enabled/strata-panel
rm -f /etc/nginx/sites-available/strata-panel
rm -f /etc/apache2/sites-enabled/strata-panel.conf
rm -f /etc/apache2/sites-available/strata-panel.conf
systemctl reload nginx   2>/dev/null || true
systemctl reload apache2 2>/dev/null || true

echo "[*] Removing apt source files…"
rm -f /etc/apt/sources.list.d/php.list
rm -f /etc/apt/sources.list.d/rspamd.list
rm -f /usr/share/keyrings/deb.sury.org-php.gpg
rm -f /usr/share/keyrings/rspamd.gpg

echo ""
echo "[ok] Strata Hosting Panel removed."
echo "     Packages (mariadb-server, postgresql, pdns-server, redis-server, etc.) were NOT purged."
echo "     To remove them: apt-get purge mariadb-server postgresql postgresql-client pdns-server pdns-backend-mysql redis-server pure-ftpd postfix dovecot-core rspamd fail2ban"
UNINSTEOF
chmod 700 /root/strata-uninstall.sh
success "Uninstall script saved to /root/strata-uninstall.sh"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║      Strata Hosting Panel installation complete!             ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}Hostname:${NC}         ${HOSTNAME_FQDN}"
echo -e "  ${BOLD}Panel URL:${NC}        https://${PANEL_DOMAIN}"
echo -e "  ${BOLD}Webmail:${NC}          https://${PANEL_DOMAIN}/webmail/"
echo -e "  ${BOLD}Admin login:${NC}      ${ADMIN_EMAIL}"
echo -e "  ${BOLD}Admin password:${NC}   (as entered)"
echo -e "  ${BOLD}Web server:${NC}       ${WEB_SERVER}"
echo -e "  ${BOLD}Hosting data:${NC}     ${HOSTING_STORAGE_ROOT} -> ${HOSTING_BIND_TARGET}"
echo -e "  ${BOLD}Backup data:${NC}      ${BACKUP_STORAGE_ROOT} -> ${BACKUP_BIND_TARGET}"
echo ""
echo -e "  ${BOLD}Webmail admin:${NC}    https://${PANEL_DOMAIN}/webmail/?admin"
echo -e "  ${BOLD}Webmail admin pw:${NC} ${SNAPPYMAIL_ADMIN_PASS}"
echo ""
echo -e "  ${YELLOW}All service credentials saved to: ${BOLD}/root/strata-credentials.txt${NC}
  ${YELLOW}To uninstall:                     ${BOLD}bash /root/strata-uninstall.sh${NC}"
echo ""
if [[ -f /etc/strata-panel/tls/fullchain.pem ]] && \
   openssl x509 -in /etc/strata-panel/tls/fullchain.pem -noout -issuer 2>/dev/null | grep -qi 'let.s encrypt'; then
    echo -e "  ${GREEN}SSL: Let's Encrypt certificate installed.${NC}"
else
    echo -e "  ${YELLOW}SSL: Self-signed certificate in use.${NC}"
    echo -e "  ${YELLOW}      Point DNS A record for ${PANEL_DOMAIN} to ${SERVER_IP} then run:${NC}"
    echo -e "  ${YELLOW}      /root/.acme.sh/acme.sh --issue --${WEB_SERVER} -d ${PANEL_DOMAIN}${NC}"
fi
echo ""
echo -e "  ${BOLD}To add a child node:${NC}"
echo -e "    STRATA_HMAC_SECRET=<secret> STRATA_NODE_ID=<id> STRATA_NODE_HOSTNAME=node1.example.com bash agent.sh"
echo -e "    (generate secrets in Admin → Nodes → Add Node)"
echo ""
