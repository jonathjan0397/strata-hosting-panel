#!/usr/bin/env bash
# =============================================================================
#  Strata Hosting Panel - Remote Node Installer
#  Supported: Debian 11/12/13
#
#  Run this after creating the node in Admin -> Nodes -> Add Node:
#
#    STRATA_HMAC_SECRET=xxx STRATA_NODE_ID=yyy STRATA_NODE_HOSTNAME=node1.example.com \
#      bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/agent.sh)
# =============================================================================
set -euo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}[info]${NC} $*"; }
success() { echo -e "${GREEN}[ok]${NC}   $*"; }
warn()    { echo -e "${YELLOW}[warn]${NC} $*"; }
die()     { echo -e "${RED}[fail]${NC} $*" >&2; exit 1; }

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

resolve_installer_version() {
    local version=""
    local script_dir=""

    if [[ -n "${STRATA_INSTALLER_VERSION:-}" ]]; then
        printf '%s\n' "${STRATA_INSTALLER_VERSION}"
        return
    fi

    script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || true)"
    if [[ -n "$script_dir" && -f "$script_dir/../VERSION" ]]; then
        version="$(tr -d '\r\n' < "$script_dir/../VERSION")"
    fi

    if [[ -z "$version" && -f "./VERSION" ]]; then
        version="$(tr -d '\r\n' < ./VERSION)"
    fi

    if [[ -z "$version" ]]; then
        version="$(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/VERSION 2>/dev/null | tr -d '\r\n' || true)"
    fi

    printf '%s\n' "${version:-dev}"
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
    local default_override="${3:-}"
    local __resultvar="$4"
    local line mountpoint size avail fstype
    local recommended_mount default_path choice selected_mount

    collect_storage_mounts
    recommended_mount="$(recommended_storage_mount)"
    default_path="${default_override:-$(storage_path_for_mount "$recommended_mount" "$suffix")}"

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
    read -rp "$(echo -e "${CYAN}${label} root [${default_path}]: ${NC}")" choice
    choice="${choice:-$default_path}"

    if [[ "$choice" =~ ^[0-9]+$ ]] && (( choice >= 1 && choice < index )); then
        line="${STORAGE_MOUNTS[$((choice - 1))]}"
        read -r selected_mount _ <<< "$line"
        printf -v "$__resultvar" '%s' "$(storage_path_for_mount "$selected_mount" "$suffix")"
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

if [[ $EUID -ne 0 ]]; then
    if command -v sudo &>/dev/null; then
        exec sudo --preserve-env=HOME,USER,LOGNAME,STRATA_HMAC_SECRET,STRATA_NODE_ID,STRATA_NODE_HOSTNAME,STRATA_WEB_SERVER,STRATA_PORT bash "$BASH_SOURCE" "$@"
    fi
    die "Must be run as root (sudo not found)."
fi

DEBIAN_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$DEBIAN_VERSION" == "11" || "$DEBIAN_VERSION" == "12" || "$DEBIAN_VERSION" == "13" ]] \
    || die "Debian 11, 12, or 13 required."
INSTALLER_VERSION="$(resolve_installer_version)"

gen_pass() { openssl rand -base64 40 | tr -dc 'a-zA-Z0-9' | head -c "${1:-32}"; }
gen_hex()  { openssl rand -hex "${1:-32}"; }

HMAC_SECRET="${STRATA_HMAC_SECRET:-}"
NODE_ID="${STRATA_NODE_ID:-}"
HOSTNAME_FQDN="${STRATA_NODE_HOSTNAME:-$(hostname -f 2>/dev/null || hostname)}"
HOSTNAME_PARENT_DOMAIN="${HOSTNAME_FQDN#*.}"
if [[ "$HOSTNAME_PARENT_DOMAIN" == "$HOSTNAME_FQDN" || -z "$HOSTNAME_PARENT_DOMAIN" ]]; then
    HOSTNAME_PARENT_DOMAIN="$HOSTNAME_FQDN"
fi
WEB_SERVER="${STRATA_WEB_SERVER:-nginx}"
AGENT_PORT="${STRATA_PORT:-8743}"
MAIL_DOMAIN="mail.${HOSTNAME_PARENT_DOMAIN}"
MAIL_TLS_DIR="/etc/strata-agent/mail-tls"
HOSTING_BIND_TARGET="/var/www"
BACKUP_BIND_TARGET="/var/backups/strata"
HOSTING_STORAGE_ROOT="${STRATA_HOSTING_STORAGE_ROOT:-}"
BACKUP_STORAGE_ROOT="${STRATA_BACKUP_STORAGE_ROOT:-}"
REQUESTED_DB_PASSWORD="${STRATA_DB_ROOT_PASSWORD:-}"
REQUESTED_PDNS_DB_PASSWORD="${STRATA_PDNS_DB_PASSWORD:-}"
REQUESTED_PDNS_API_KEY="${STRATA_PDNS_API_KEY:-}"

EXISTING_DB_PASSWORD=""
EXISTING_PDNS_DB_PASSWORD=""
EXISTING_PDNS_API_KEY=""
if [[ -f /etc/strata-agent/install.env ]]; then
    # shellcheck disable=SC1091
    source /etc/strata-agent/install.env
    EXISTING_DB_PASSWORD="${STRATA_DB_ROOT_PASSWORD:-}"
    EXISTING_PDNS_DB_PASSWORD="${STRATA_PDNS_DB_PASSWORD:-}"
    EXISTING_PDNS_API_KEY="${STRATA_PDNS_API_KEY:-}"
fi

DB_PASSWORD="${REQUESTED_DB_PASSWORD:-${EXISTING_DB_PASSWORD:-$(gen_pass 32)}}"
PDNS_DB_PASSWORD="${REQUESTED_PDNS_DB_PASSWORD:-${EXISTING_PDNS_DB_PASSWORD:-$(gen_pass 32)}}"
PDNS_API_KEY="${REQUESTED_PDNS_API_KEY:-${EXISTING_PDNS_API_KEY:-$(gen_hex 32)}}"

if [[ -z "$HMAC_SECRET" ]]; then
    read -rp "$(echo -e "${CYAN}HMAC Secret${NC} (from panel node details): ")" HMAC_SECRET
fi
if [[ -z "$NODE_ID" ]]; then
    read -rp "$(echo -e "${CYAN}Node ID${NC} (from panel node details): ")" NODE_ID
fi
if [[ -z "${STRATA_NODE_HOSTNAME:-}" ]]; then
    read -rp "$(echo -e "${CYAN}Node hostname${NC} [${HOSTNAME_FQDN}]: ")" HOSTNAME_INPUT
    HOSTNAME_FQDN="${HOSTNAME_INPUT:-$HOSTNAME_FQDN}"
fi
[[ -n "$HMAC_SECRET" && -n "$NODE_ID" && -n "$HOSTNAME_FQDN" ]] || die "HMAC secret, node ID, and hostname are required."
[[ "$WEB_SERVER" == "nginx" || "$WEB_SERVER" == "apache" ]] || die "STRATA_WEB_SERVER must be nginx or apache."

if [[ -z "$HOSTING_STORAGE_ROOT" ]]; then
    prompt_storage_root "Hosting data" "strata/www" "" HOSTING_STORAGE_ROOT
fi
if [[ -z "$BACKUP_STORAGE_ROOT" ]]; then
    prompt_storage_root "Backup data" "strata/backups" "" BACKUP_STORAGE_ROOT
fi

SERVER_IP=$(curl -4 -fsSL https://icanhazip.com 2>/dev/null || hostname -I | awk '{print $1}')

mkdir -p /etc/strata-agent
cat > /etc/strata-agent/install.env <<EOF
STRATA_DB_ROOT_PASSWORD='${DB_PASSWORD}'
STRATA_PDNS_DB_PASSWORD='${PDNS_DB_PASSWORD}'
STRATA_PDNS_API_KEY='${PDNS_API_KEY}'
STRATA_HOSTING_STORAGE_ROOT='${HOSTING_STORAGE_ROOT}'
STRATA_BACKUP_STORAGE_ROOT='${BACKUP_STORAGE_ROOT}'
EOF
chmod 600 /etc/strata-agent/install.env

info "Installing Strata remote node ${INSTALLER_VERSION} on Debian ${DEBIAN_VERSION}."
info "Hostname: ${HOSTNAME_FQDN}; web server: ${WEB_SERVER}; agent port: ${AGENT_PORT}."

hostnamectl set-hostname "$HOSTNAME_FQDN"
SHORT="${HOSTNAME_FQDN%%.*}"
sed -i "/^127\.0\.1\.1/d" /etc/hosts
echo "127.0.1.1  ${HOSTNAME_FQDN} ${SHORT}" >> /etc/hosts

info "Preparing storage mounts..."
ensure_bind_mount "$HOSTING_STORAGE_ROOT" "$HOSTING_BIND_TARGET" "strata-hosting-storage"
ensure_bind_mount "$BACKUP_STORAGE_ROOT" "$BACKUP_BIND_TARGET" "strata-backup-storage"
success "Hosting data path: ${HOSTING_STORAGE_ROOT} -> ${HOSTING_BIND_TARGET}"
success "Backup data path: ${BACKUP_STORAGE_ROOT} -> ${BACKUP_BIND_TARGET}"

info "Installing base packages..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl wget gnupg2 ca-certificates lsb-release git unzip zip openssl \
    ufw fail2ban acl sudo cron build-essential tar

info "Installing ClamAV..."
DEBIAN_FRONTEND=noninteractive apt-get install -y clamav clamav-daemon || warn "ClamAV install failed; malware scans will report unavailable."
systemctl enable --now clamav-freshclam 2>/dev/null || true
systemctl enable --now clamav-daemon 2>/dev/null || true

info "Installing PHP..."
curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
case "$DEBIAN_VERSION" in
    13) PHP_CODENAME="trixie"; PHP_VERSIONS=(8.1 8.2 8.4) ;;
    12) PHP_CODENAME="bookworm"; PHP_VERSIONS=(8.1 8.2 8.3) ;;
    *)  PHP_CODENAME="bullseye"; PHP_VERSIONS=(8.1 8.2 8.3) ;;
esac
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${PHP_CODENAME} main" \
    > /etc/apt/sources.list.d/php.list
apt-get update
PHP_EXTENSIONS="fpm cli common curl mbstring xml zip bcmath intl gd mysql pgsql redis"
INSTALLED_PHP_VERSIONS=()
for VER in "${PHP_VERSIONS[@]}"; do
    PKG_LIST=""
    for EXT in $PHP_EXTENSIONS; do
        PKG_LIST="$PKG_LIST php${VER}-${EXT}"
    done
    if DEBIAN_FRONTEND=noninteractive apt-get install -y $PKG_LIST; then
        INSTALLED_PHP_VERSIONS+=("$VER")
    else
        warn "PHP ${VER} not available; skipping."
    fi
done
[[ ${#INSTALLED_PHP_VERSIONS[@]} -gt 0 ]] || die "No PHP-FPM version could be installed."

info "Installing database services..."
DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client postgresql postgresql-client
systemctl enable --now mariadb postgresql
for _i in $(seq 1 30); do mysqladmin ping --silent 2>/dev/null && break; sleep 1; done

MC=$(command -v mariadb 2>/dev/null || command -v mysql)
SQL=$(cat <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
CREATE DATABASE IF NOT EXISTS pdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
ALTER USER 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON pdns.* TO 'pdns'@'localhost';
FLUSH PRIVILEGES;
SQL
)
run_mariadb_setup() {
    "$MC" -e "$SQL" 2>/dev/null \
        || "$MC" -u root -p"${DB_PASSWORD}" -h 127.0.0.1 -e "$SQL" 2>/dev/null
}

if ! run_mariadb_setup; then
    # Debian 13 can initialize MariaDB with a root auth mode that blocks both
    # local socket login and the generated password on a fresh node install.
    if [[ ! -f /etc/strata-agent/mysql.cnf && ! -f /etc/systemd/system/strata-agent.service ]]; then
        warn "MariaDB root auth is not usable yet; reinitializing for a fresh node install."
        systemctl stop mariadb || true
        find /var/lib/mysql -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
        mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=normal >/dev/null
        chown -R mysql:mysql /var/lib/mysql
        systemctl start mariadb
        for _i in $(seq 1 30); do
            [[ -S /run/mysqld/mysqld.sock ]] && break
            sleep 1
        done
        run_mariadb_setup || die "Unable to initialize MariaDB root credentials after reinitialization."
    else
        die "Unable to authenticate to MariaDB as root. Reset the node or provide STRATA_DB_ROOT_PASSWORD matching the existing MariaDB root password."
    fi
fi

info "Installing PowerDNS..."
DEBIAN_FRONTEND=noninteractive apt-get install -y pdns-server pdns-backend-mysql
SCHEMA_FILE="/usr/share/pdns-backend-mysql/schema/schema.mysql.sql"
if [[ -f "$SCHEMA_FILE" ]]; then
    mysql -u pdns -p"${PDNS_DB_PASSWORD}" -h 127.0.0.1 pdns < "$SCHEMA_FILE" 2>/dev/null || true
fi
rm -f /etc/powerdns/pdns.d/*.conf 2>/dev/null || true
cat > /etc/powerdns/pdns.conf <<EOF
launch=gmysql
gmysql-host=127.0.0.1
gmysql-dbname=pdns
gmysql-user=pdns
gmysql-password=${PDNS_DB_PASSWORD}
api=yes
api-key=${PDNS_API_KEY}
webserver=yes
webserver-address=127.0.0.1
webserver-port=8053
webserver-allow-from=127.0.0.1,::1
local-address=0.0.0.0
default-soa-content=ns1.${HOSTNAME_PARENT_DOMAIN} hostmaster.${HOSTNAME_PARENT_DOMAIN} 0 10800 3600 1209600 3600
EOF
systemctl enable --now pdns

info "Installing FTP and mail services..."
DEBIAN_FRONTEND=noninteractive apt-get install -y pure-ftpd pure-ftpd-common \
    postfix postfix-mysql dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql \
    opendkim opendkim-tools

info "Installing Rspamd..."
curl -fsSL https://rspamd.com/apt-stable/gpg.key | gpg --dearmor > /usr/share/keyrings/rspamd.gpg 2>/dev/null
echo "deb [signed-by=/usr/share/keyrings/rspamd.gpg] https://rspamd.com/apt-stable/ ${PHP_CODENAME} main" \
    > /etc/apt/sources.list.d/rspamd.list
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y rspamd

if ! getent group vmail >/dev/null 2>&1; then
    groupadd -g 5000 vmail 2>/dev/null || groupadd vmail
fi
if ! id -u vmail >/dev/null 2>&1; then
    useradd -u 5000 -g vmail -d /var/mail/vhosts -s /usr/sbin/nologin vmail 2>/dev/null \
        || useradd -g vmail -d /var/mail/vhosts -s /usr/sbin/nologin vmail
else
    usermod -g vmail -d /var/mail/vhosts vmail 2>/dev/null || true
fi
mkdir -p /var/mail/vhosts /etc/strata-agent/tls
chown -R vmail:vmail /var/mail/vhosts
chmod 0750 /var/mail/vhosts
mkdir -p "$MAIL_TLS_DIR"

openssl req -x509 -newkey rsa:4096 \
    -keyout /etc/strata-agent/tls/key.pem \
    -out /etc/strata-agent/tls/cert.pem \
    -days 3650 -nodes \
    -subj "/CN=${HOSTNAME_FQDN}" \
    -addext "subjectAltName=DNS:${HOSTNAME_FQDN},IP:127.0.0.1" >/dev/null 2>&1
chmod 600 /etc/strata-agent/tls/key.pem

openssl req -x509 -newkey rsa:4096 \
    -keyout "${MAIL_TLS_DIR}/privkey.pem" \
    -out "${MAIL_TLS_DIR}/fullchain.pem" \
    -days 3650 -nodes \
    -subj "/CN=${MAIL_DOMAIN}" \
    -addext "subjectAltName=DNS:${MAIL_DOMAIN}" >/dev/null 2>&1
chmod 600 "${MAIL_TLS_DIR}/privkey.pem"

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

sed -i 's/^!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/^!include auth-sql.conf.ext/#!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/^!include auth-passwdfile.conf.ext/#!include auth-passwdfile.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
grep -q '^!include auth-strata-passwdfile.conf.ext' /etc/dovecot/conf.d/10-auth.conf || echo '!include auth-strata-passwdfile.conf.ext' >> /etc/dovecot/conf.d/10-auth.conf
sed -i 's/^auth_mechanisms =.*/auth_mechanisms = plain login/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's|^mail_location =.*|mail_location = maildir:/var/mail/vhosts/%d/%n|' /etc/dovecot/conf.d/10-mail.conf 2>/dev/null || true
sed -i 's/^  auth_username_format = %{user | username | lower}$/  auth_username_format = %{user}/' /etc/dovecot/conf.d/20-lmtp.conf 2>/dev/null || true
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
cat > /etc/dovecot/conf.d/10-master.conf <<'EOF'
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
    mode = 0600
    user = postfix
    group = postfix
  }
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
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
EOF
cat > /etc/dovecot/conf.d/10-ssl.conf <<EOF
ssl = yes
ssl_server_cert_file = ${MAIL_TLS_DIR}/fullchain.pem
ssl_server_key_file = ${MAIL_TLS_DIR}/privkey.pem
EOF
systemctl enable --now postfix dovecot
systemctl enable --now rspamd

mkdir -p /etc/opendkim/userkeys /var/spool/postfix/opendkim
cat > /etc/opendkim.conf <<EOF
Syslog          yes
UMask           002
Mode            sv
SignatureAlgorithm rsa-sha256
Canonicalization relaxed/relaxed
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
touch /etc/opendkim/KeyTable /etc/opendkim/SigningTable
chown -R opendkim:opendkim /etc/opendkim/userkeys
chown opendkim:postfix /var/spool/postfix/opendkim
chmod 750 /var/spool/postfix/opendkim
rm -f /etc/systemd/system/opendkim-socket-perms.service /etc/systemd/system/opendkim-socket-perms.path
systemctl disable --now opendkim-socket-perms.path >/dev/null 2>&1 || true
systemctl daemon-reload >/dev/null 2>&1 || true
systemctl enable --now opendkim

rm -f /etc/pure-ftpd/conf/VirtualChroot
mkdir -p /etc/pure-ftpd/auth
echo "/etc/pureftpd/pureftpd.pdb" > /etc/pure-ftpd/conf/PureDB
echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
echo "no" > /etc/pure-ftpd/conf/UnixAuthentication
rm -f /etc/pure-ftpd/auth/*
ln -sf ../conf/PureDB /etc/pure-ftpd/auth/60puredb
echo "yes" > /etc/pure-ftpd/conf/NoAnonymous
echo "yes" > /etc/pure-ftpd/conf/ChrootEveryone
echo "30000 50000" > /etc/pure-ftpd/conf/PassivePortRange
echo "1" > /etc/pure-ftpd/conf/TLS
mkdir -p /etc/ssl/private
openssl req -x509 -newkey rsa:2048 \
    -keyout /etc/ssl/private/pure-ftpd.pem \
    -out /etc/ssl/private/pure-ftpd.pem \
    -days 3650 -nodes -subj "/CN=${HOSTNAME_FQDN}" >/dev/null 2>&1
chmod 600 /etc/ssl/private/pure-ftpd.pem
systemctl enable --now pure-ftpd

info "Installing web server..."
if [[ "$WEB_SERVER" == "apache" ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y apache2
    a2enmod proxy_fcgi setenvif headers rewrite ssl >/dev/null 2>&1
    systemctl enable --now apache2
else
    DEBIAN_FRONTEND=noninteractive apt-get install -y nginx
    systemctl enable --now nginx
fi

info "Installing Go and building agent..."
if ! command -v go &>/dev/null; then
    wget -q "https://go.dev/dl/go1.23.8.linux-amd64.tar.gz" -O /tmp/go.tar.gz
    rm -rf /usr/local/go
    tar -C /usr/local -xzf /tmp/go.tar.gz
    rm -f /tmp/go.tar.gz
    export PATH="/usr/local/go/bin:$PATH"
    echo 'export PATH="/usr/local/go/bin:$PATH"' > /etc/profile.d/go.sh
fi
rm -rf /tmp/strata-src
git clone --depth=1 https://github.com/jonathjan0397/strata-hosting-panel.git /tmp/strata-src
AGENT_VERSION="$(cat /tmp/strata-src/VERSION 2>/dev/null || echo 'dev')"
cd /tmp/strata-src/agent
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/buildinfo.Version=${AGENT_VERSION}" \
    -o /usr/sbin/strata-agent \
    ./main.go
GOOS=linux GOARCH=amd64 go build -o /usr/sbin/strata-webdav ./cmd/strata-webdav
chmod 755 /usr/sbin/strata-agent /usr/sbin/strata-webdav
install -m 755 /tmp/strata-src/installer/agent-upgrade.sh /usr/sbin/strata-agent-upgrade
rm -rf /tmp/strata-src

mkdir -p /etc/strata-agent /etc/strata-webdav
cat > /etc/strata-agent/mysql.cnf <<EOF
[client]
user     = root
password = ${DB_PASSWORD}
host     = 127.0.0.1
EOF
chmod 600 /etc/strata-agent/mysql.cnf

cat > /etc/systemd/system/strata-agent.service <<EOF
[Unit]
Description=Strata Agent
After=network.target mariadb.service pdns.service

[Service]
Type=simple
User=root
ExecStart=/usr/sbin/strata-agent
Restart=always
RestartSec=5
Environment=STRATA_HMAC_SECRET=${HMAC_SECRET}
Environment=STRATA_NODE_ID=${NODE_ID}
Environment=STRATA_PORT=${AGENT_PORT}
Environment=STRATA_TLS_CERT=/etc/strata-agent/tls/cert.pem
Environment=STRATA_TLS_KEY=/etc/strata-agent/tls/key.pem
Environment=STRATA_PDNS_API_KEY=${PDNS_API_KEY}
Environment=STRATA_DB_ROOT_PASSWORD=${DB_PASSWORD}
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF

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

info "Configuring firewall and Fail2Ban..."
ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming >/dev/null 2>&1 || true
ufw default allow outgoing >/dev/null 2>&1 || true
for rule in 22/tcp 53/tcp 53/udp 80/tcp 443/tcp 25/tcp 465/tcp 587/tcp 993/tcp 995/tcp 2078/tcp "${AGENT_PORT}/tcp" 30000:50000/tcp; do
    ufw allow "$rule" >/dev/null 2>&1 || true
done
ufw --force enable >/dev/null 2>&1 || true
cat > /etc/fail2ban/jail.d/strata-defaults.local <<'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 10
backend = systemd

[sshd]
enabled = true
maxretry = 10

[postfix]
enabled = true
maxretry = 10

[postfix-sasl]
enabled = true
maxretry = 10

[dovecot]
enabled = true
maxretry = 10

[nginx-http-auth]
enabled = true
maxretry = 10

[pure-ftpd]
enabled = true
maxretry = 10

[apache-auth]
enabled = true
maxretry = 10

[recidive]
enabled = true
bantime = 86400
findtime = 86400
maxretry = 5
EOF
systemctl enable --now fail2ban

systemctl daemon-reload
systemctl enable --now strata-agent strata-webdav
systemctl restart postfix dovecot rspamd opendkim pure-ftpd pdns strata-agent strata-webdav

if command -v acme.sh >/dev/null 2>&1 || curl -fsSL https://get.acme.sh | sh -s email="admin@${HOSTNAME_FQDN#*.}" >/dev/null 2>&1; then
    export PATH="/root/.acme.sh:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH}"
    /root/.acme.sh/acme.sh --set-default-ca --server letsencrypt >/dev/null 2>&1 || true
    mkdir -p /var/www/html/.well-known/acme-challenge
    if /root/.acme.sh/acme.sh --issue -w /var/www/html -d "$HOSTNAME_FQDN" --keylength 4096 >/dev/null 2>&1; then
        /root/.acme.sh/acme.sh --install-cert -d "$HOSTNAME_FQDN" \
            --key-file /etc/strata-agent/tls/key.pem \
            --fullchain-file /etc/strata-agent/tls/cert.pem \
            --reloadcmd "systemctl restart strata-agent strata-webdav postfix dovecot rspamd" >/dev/null 2>&1 || true
    else
        warn "Let's Encrypt certificate was not issued; self-signed agent certificate remains in use."
    fi
fi

FINGERPRINT=$(openssl x509 -in /etc/strata-agent/tls/cert.pem -fingerprint -sha256 -noout | cut -d= -f2)

success "Remote node installed."
echo ""
echo "Node hostname:      ${HOSTNAME_FQDN}"
echo "Agent port:         ${AGENT_PORT}"
echo "Agent version:      ${AGENT_VERSION}"
echo "Hosting data:       ${HOSTING_STORAGE_ROOT} -> ${HOSTING_BIND_TARGET}"
echo "Backup data:        ${BACKUP_STORAGE_ROOT} -> ${BACKUP_BIND_TARGET}"
echo "TLS fingerprint:    ${FINGERPRINT}"
echo "PowerDNS API key:   ${PDNS_API_KEY}"
echo "MariaDB root pass:  (stored in /etc/systemd/system/strata-agent.service and /etc/strata-agent/mysql.cnf)"
echo ""
echo "If the panel cannot reach this node over TLS, update the node fingerprint in Admin -> Nodes."
