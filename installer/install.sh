#!/usr/bin/env bash
# =============================================================================
#  Strata Panel — Installer
#  Supported: Debian 11 (Bullseye) · Debian 12 (Bookworm) · Debian 13 (Trixie)
#  Run as root on a fresh server.
#
#  Usage:
#    bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh)
#  Or:
#    bash install.sh
# =============================================================================
set -euo pipefail

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}[info]${NC} $*"; }
success() { echo -e "${GREEN}[ok]${NC}   $*"; }
warn()    { echo -e "${YELLOW}[warn]${NC} $*"; }
die()     { echo -e "${RED}[fail]${NC} $*" >&2; exit 1; }
prompt()  { echo -e "${CYAN}$*${NC}"; }

# ── Root check ────────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Must be run as root."

DEBIAN_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$DEBIAN_VERSION" == "11" || "$DEBIAN_VERSION" == "12" || "$DEBIAN_VERSION" == "13" ]] \
    || die "Unsupported OS. Debian 11, 12, or 13 required (detected: $DEBIAN_VERSION)."

SERVER_IP=$(curl -4 -fsSL https://icanhazip.com 2>/dev/null || hostname -I | awk '{print $1}')
DETECTED_HOSTNAME=$(hostname -f 2>/dev/null || hostname)

echo -e "\n${BOLD}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║           Strata Panel Installer  v1.0-Beta          ║${NC}"
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
    for svc in strata-agent strata-queue; do
        systemctl stop    "$svc" 2>/dev/null || true
        systemctl disable "$svc" 2>/dev/null || true
        rm -f "/etc/systemd/system/${svc}.service"
    done
    systemctl daemon-reload 2>/dev/null || true

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
    rm -rf /etc/strata-agent
    rm -rf /etc/strata-panel
    rm -f  /usr/sbin/strata-agent
    rm -f  /root/.my.cnf

    # Remove the strata system user
    userdel -r "${PANEL_USER:-strata}" 2>/dev/null || true

    # Remove scheduler cron
    crontab -r -u "${PANEL_USER:-strata}" 2>/dev/null || true

    # Remove web vhost configs
    rm -f /etc/nginx/sites-enabled/strata-panel
    rm -f /etc/nginx/sites-available/strata-panel
    rm -f /etc/apache2/sites-enabled/strata-panel.conf
    rm -f /etc/apache2/sites-available/strata-panel.conf
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
read -rp "$(prompt 'Panel domain (e.g. panel.example.com): ')" PANEL_DOMAIN
[[ -n "$PANEL_DOMAIN" ]] || die "Panel domain is required."

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

# ── Confirm before proceeding ─────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Summary ──────────────────────────────────────────────${NC}"
echo ""
echo -e "  Server hostname:${BOLD}${HOSTNAME_FQDN}${NC}"
echo -e "  Panel domain:   ${BOLD}${PANEL_DOMAIN}${NC}"
echo -e "  Web server:     ${BOLD}${WEB_SERVER}${NC}"
echo -e "  Admin email:    ${BOLD}${ADMIN_EMAIL}${NC}"
echo -e "  Admin name:     ${BOLD}${ADMIN_NAME}${NC}"
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

# ── Step 1. System update ─────────────────────────────────────────────────────
info "Checking for interrupted dpkg operations…"
# Force-remove any packages stuck in 'needs reinstall' state (iF flag in dpkg -l)
_broken=$(dpkg -l 2>/dev/null | awk '/^iF/{print $2}')
if [[ -n "$_broken" ]]; then
    warn "Removing broken packages: ${_broken}"
    # shellcheck disable=SC2086
    dpkg --remove --force-remove-reinstreq $_broken 2>/dev/null || true
fi
dpkg --configure -a 2>/dev/null || true
DEBIAN_FRONTEND=noninteractive apt-get install -f -y 2>/dev/null || true

info "Updating package lists…"
apt-get update || die "apt-get update failed — check your sources.list and network connectivity."

info "Installing base packages…"
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl wget gnupg2 ca-certificates lsb-release \
    git unzip zip openssl ufw fail2ban \
    acl sudo cron

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

PHP_VERSIONS=(8.1 8.2 8.3)
PHP_EXTENSIONS="fpm cli common curl mbstring xml zip bcmath intl gd mysql redis"

info "Installing PHP 8.1, 8.2, 8.3 + extensions…"
for VER in "${PHP_VERSIONS[@]}"; do
    PKG_LIST=""
    for EXT in $PHP_EXTENSIONS; do
        PKG_LIST="$PKG_LIST php${VER}-${EXT}"
    done
    DEBIAN_FRONTEND=noninteractive apt-get install -y $PKG_LIST
done
success "PHP installed."

# ── Step 3. MariaDB ───────────────────────────────────────────────────────────
info "Installing MariaDB…"
DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client
systemctl enable --now mariadb

info "Securing MariaDB and creating databases…"
# Set root password
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';" 2>/dev/null || \
    mysql -e "UPDATE mysql.user SET Password=PASSWORD('${DB_PASSWORD}') WHERE User='root'; FLUSH PRIVILEGES;" 2>/dev/null || true

MYSQL_CMD() { mysql -u root -p"${DB_PASSWORD}" -h 127.0.0.1 "$@" 2>/dev/null; }

MYSQL_CMD -e "
    DELETE FROM mysql.user WHERE User='';
    DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
    DROP DATABASE IF EXISTS test;
    DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%';
    CREATE DATABASE IF NOT EXISTS strata_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'strata'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON strata_panel.* TO 'strata'@'localhost';
    FLUSH PRIVILEGES;
"

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
cat > /etc/powerdns/pdns.conf <<EOF
# PowerDNS — managed by Strata Panel
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

# Logging
log-dns-queries=no
loglevel=4
EOF

systemctl enable --now pdns
success "PowerDNS ready (API on 127.0.0.1:8053)."

# ── Step 3c. Pure-FTPd ────────────────────────────────────────────────────────
info "Installing Pure-FTPd…"
DEBIAN_FRONTEND=noninteractive apt-get install -y pure-ftpd pure-ftpd-common

mkdir -p /etc/pureftpd

# Virtual users
echo "yes" > /etc/pure-ftpd/conf/VirtualChroot
echo "/etc/pureftpd/pureftpd.pdb" > /etc/pure-ftpd/conf/PureDB
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

systemctl enable --now redis-server
success "Redis ready (localhost only, password set)."

# ── Step 4b. Mail stack ───────────────────────────────────────────────────────
info "Creating vmail system user…"
if ! getent group vmail > /dev/null 2>&1; then
    groupadd -g 5000 vmail 2>/dev/null || groupadd vmail || die "Failed to create vmail group"
fi
if ! getent passwd vmail > /dev/null 2>&1; then
    useradd -u 5000 -g vmail -d /var/mail/vmail -s /usr/sbin/nologin vmail 2>/dev/null \
        || useradd -g vmail -d /var/mail/vmail -s /usr/sbin/nologin vmail \
        || die "Failed to create vmail user"
fi
mkdir -p /var/mail/vmail
chown -R vmail:vmail /var/mail/vmail

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
postconf -e "virtual_mailbox_base = /var/mail/vmail"
postconf -e "virtual_minimum_uid = 5000"
postconf -e "virtual_uid_maps = static:5000"
postconf -e "virtual_gid_maps = static:5000"
postconf -e "virtual_mailbox_domains = proxy:mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
postconf -e "virtual_mailbox_maps = proxy:mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"
postconf -e "virtual_alias_maps = proxy:mysql:/etc/postfix/mysql-virtual-alias-maps.cf"
postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = yes"
postconf -e "smtpd_tls_cert_file = /etc/strata-panel/tls/fullchain.pem"
postconf -e "smtpd_tls_key_file = /etc/strata-panel/tls/privkey.pem"
postconf -e "smtpd_tls_security_level = may"
postconf -e "smtpd_relay_restrictions = permit_mynetworks permit_sasl_authenticated defer_unauth_destination"
postconf -e "milter_default_action = accept"
postconf -e "milter_protocol = 6"
postconf -e "smtpd_milters = local:opendkim/opendkim.sock"
postconf -e "non_smtpd_milters = local:opendkim/opendkim.sock"

cat > /etc/postfix/mysql-virtual-mailbox-domains.cf <<EOF
user     = strata
password = ${DB_PASSWORD}
hosts    = 127.0.0.1
dbname   = strata_panel
query    = SELECT domain FROM domains WHERE domain='%s' LIMIT 1
EOF

cat > /etc/postfix/mysql-virtual-mailbox-maps.cf <<EOF
user     = strata
password = ${DB_PASSWORD}
hosts    = 127.0.0.1
dbname   = strata_panel
query    = SELECT 1 FROM email_accounts WHERE email='%s' LIMIT 1
EOF

cat > /etc/postfix/mysql-virtual-alias-maps.cf <<EOF
user     = strata
password = ${DB_PASSWORD}
hosts    = 127.0.0.1
dbname   = strata_panel
query    = SELECT destination FROM email_forwarders WHERE source='%s' LIMIT 1
EOF

chmod 640 /etc/postfix/mysql-virtual-*.cf
chown root:postfix /etc/postfix/mysql-virtual-*.cf

postconf -M "submission/inet=submission inet n - y - - smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject" 2>/dev/null || true

# Dovecot
sed -i 's/^!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/#!include auth-sql.conf.ext/!include auth-sql.conf.ext/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's/^auth_mechanisms =.*/auth_mechanisms = plain login/' /etc/dovecot/conf.d/10-auth.conf 2>/dev/null || true
sed -i 's|^mail_location =.*|mail_location = maildir:/var/mail/vmail/%d/%n|' /etc/dovecot/conf.d/10-mail.conf 2>/dev/null || true
grep -q '^mail_uid' /etc/dovecot/conf.d/10-mail.conf || echo "mail_uid = vmail" >> /etc/dovecot/conf.d/10-mail.conf
grep -q '^mail_gid' /etc/dovecot/conf.d/10-mail.conf || echo "mail_gid = vmail" >> /etc/dovecot/conf.d/10-mail.conf

cat > /etc/dovecot/dovecot-sql.conf.ext <<EOF
driver   = mysql
connect  = host=127.0.0.1 dbname=strata_panel user=strata password=${DB_PASSWORD}
default_pass_scheme = BLF-CRYPT

password_query = SELECT password FROM email_accounts WHERE email = '%u'
user_query     = SELECT 5000 AS uid, 5000 AS gid, '/var/mail/vmail/%d/%n' AS home FROM email_accounts WHERE email = '%u'
EOF
chmod 640 /etc/dovecot/dovecot-sql.conf.ext
chown root:dovecot /etc/dovecot/dovecot-sql.conf.ext

cat > /etc/dovecot/conf.d/10-master.conf <<'DOVEOF'
service imap-login {
  inet_listener imap { port = 0 }
  inet_listener imaps { port = 993 ssl = yes }
}
service pop3-login {
  inet_listener pop3 { port = 0 }
  inet_listener pop3s { port = 995 ssl = yes }
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
  unix_listener auth-userdb { mode = 0600 user = vmail }
  user = dovecot
}
service auth-worker { user = vmail }
DOVEOF

cat > /etc/dovecot/conf.d/10-ssl.conf <<EOF
ssl = yes
ssl_cert = </etc/strata-panel/tls/fullchain.pem
ssl_key  = </etc/strata-panel/tls/privkey.pem
ssl_min_protocol = TLSv1.2
EOF

systemctl enable --now postfix dovecot rspamd
success "Mail stack ready (Postfix + Dovecot + Rspamd)."

# OpenDKIM
info "Configuring OpenDKIM…"
mkdir -p /etc/opendkim/keys

cat > /etc/opendkim.conf <<EOF
# OpenDKIM — managed by Strata Panel
Syslog          yes
UMask           002
Mode            sv
SignatureAlgorithm rsa-sha256
UserID          opendkim:opendkim
Socket          local:/var/spool/postfix/opendkim/opendkim.sock
PidFile         /var/run/opendkim/opendkim.pid
TrustAnchorFile /usr/share/dns/root.key
OversignHeaders From
InternalHosts   /etc/opendkim/trusted.hosts
ExternalIgnoreList /etc/opendkim/trusted.hosts
KeyTable        /etc/opendkim/key.table
SigningTable    refile:/etc/opendkim/signing.table
EOF

cat > /etc/opendkim/trusted.hosts <<EOF
127.0.0.1
localhost
${HOSTNAME_FQDN}
EOF

touch /etc/opendkim/key.table
touch /etc/opendkim/signing.table

mkdir -p /var/spool/postfix/opendkim
chown opendkim:postfix /var/spool/postfix/opendkim
usermod -aG opendkim postfix 2>/dev/null || true

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
id "$PANEL_USER" &>/dev/null || useradd -r -m -d "$INSTALL_DIR" -s /bin/bash "$PANEL_USER"
success "User '${PANEL_USER}' ready."

# ── Step 11. Clone panel ──────────────────────────────────────────────────────
info "Cloning Strata Panel…"
if [[ -d "$INSTALL_DIR/panel" ]]; then
    warn "Panel directory exists — pulling latest…"
    git -C "$INSTALL_DIR/panel" pull --ff-only
else
    git clone --depth=1 https://github.com/jonathjan0397/strata-panel.git /tmp/strata-panel-src
    mkdir -p "$INSTALL_DIR"
    cp -r /tmp/strata-panel-src/panel "$INSTALL_DIR/panel"
    cp -r /tmp/strata-panel-src/agent "$INSTALL_DIR/agent-src"
    rm -rf /tmp/strata-panel-src
fi
success "Panel source ready at $INSTALL_DIR/panel"

# ── Step 12. Panel .env ───────────────────────────────────────────────────────
info "Writing .env…"
cat > "$INSTALL_DIR/panel/.env" <<EOF
APP_NAME="Strata Panel"
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
STRATA_VERSION=1.0.0

# Webmail SSO
STRATA_WEBMAIL_SSO_SECRET=${WEBMAIL_SSO_SECRET}
STRATA_WEBMAIL_URL=/webmail/
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
npm ci --silent
npm run build

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
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-panel/agent/internal/api.Version=$(git -C "$INSTALL_DIR" describe --tags --always 2>/dev/null || echo 'v1.0-beta')" \
    -o /usr/sbin/strata-agent \
    .
chmod 755 /usr/sbin/strata-agent
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
ProtectSystem=full
PrivateTmp=true
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now strata-agent
success "strata-agent running."

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
Description=Strata Panel Queue Worker
After=network.target redis.service

[Service]
Type=simple
User=${PANEL_USER}
WorkingDirectory=${INSTALL_DIR}/panel
ExecStart=/usr/bin/php ${INSTALL_DIR}/panel/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now strata-queue
success "Queue worker running."

info "Adding Laravel scheduler cron job…"
(crontab -l -u "$PANEL_USER" 2>/dev/null; echo "* * * * * cd ${INSTALL_DIR}/panel && /usr/bin/php artisan schedule:run >> /dev/null 2>&1") \
    | crontab -u "$PANEL_USER" -
success "Scheduler cron added."

# ── Step 18. Nginx/Apache vhost for panel ─────────────────────────────────────
mkdir -p /etc/strata-panel/tls

if [[ "$WEB_SERVER" == "apache" ]]; then
    info "Configuring Apache2 for $PANEL_DOMAIN…"
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
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
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
    <FilesMatch "^/var/www/webmail/.+\.php\$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
EOF

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
    fi

    apache2ctl configtest && systemctl restart apache2
    success "Apache2 configured."

else
    info "Configuring Nginx for $PANEL_DOMAIN…"
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

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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
    fi

    nginx -t && systemctl reload nginx
    success "Nginx configured."
fi

# ── Step 19. Firewall (UFW) ───────────────────────────────────────────────────
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
ufw allow 587/tcp comment "SMTP submission"     >/dev/null
ufw allow 993/tcp comment "IMAPS"               >/dev/null
ufw allow 995/tcp comment "POP3S"               >/dev/null
# Agent port (8743) intentionally NOT opened — panel talks to it on localhost only
ufw --force enable >/dev/null
success "Firewall enabled."

# ── Step 20. fail2ban jails ───────────────────────────────────────────────────
info "Configuring fail2ban…"
cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5
backend  = systemd

[sshd]
enabled  = true
port     = ssh
logpath  = %(sshd_log)s

[postfix]
enabled  = true
port     = smtp,submission
logpath  = %(postfix_log)s

[dovecot]
enabled  = true
port     = imap3,imaps,pop3,pop3s
logpath  = %(dovecot_log)s

[nginx-http-auth]
enabled  = true
port     = http,https
logpath  = %(nginx_error_log)s
EOF

systemctl enable --now fail2ban
success "fail2ban configured (SSH, Postfix, Dovecot, Nginx jails active)."

# ── Step 21. Register primary node ───────────────────────────────────────────
info "Registering primary node in panel database…"
cd "$INSTALL_DIR/panel"
php artisan tinker --no-interaction <<TINKER 2>/dev/null
use App\Models\Node;
Node::updateOrCreate(
    ['node_id' => '${AGENT_NODE_ID}'],
    [
        'name'        => 'Primary',
        'hostname'    => '${HOSTNAME_FQDN}',
        'ip_address'  => '127.0.0.1',
        'port'        => 8743,
        'hmac_secret' => '${AGENT_HMAC_SECRET}',
        'web_server'  => '${WEB_SERVER}',
        'status'      => 'online',
        'is_primary'  => true,
        'last_seen_at'=> now(),
    ]
);
TINKER
success "Primary node registered."

# ── Step 22. SnappyMail webmail ───────────────────────────────────────────────
info "Installing SnappyMail v${SNAPPYMAIL_VERSION}…"
mkdir -p "$WEBMAIL_DIR" "$WEBMAIL_DATA"

SNAPPY_ZIP="/tmp/snappymail-${SNAPPYMAIL_VERSION}.zip"
wget -q "https://github.com/the-djmaze/snappymail/releases/download/v${SNAPPYMAIL_VERSION}/snappymail-${SNAPPYMAIL_VERSION}.zip" \
    -O "$SNAPPY_ZIP" || warn "SnappyMail download failed — install manually."

if [[ -f "$SNAPPY_ZIP" ]]; then
    unzip -q "$SNAPPY_ZIP" -d "$WEBMAIL_DIR"
    rm "$SNAPPY_ZIP"

    if [[ -d "$WEBMAIL_DIR/data" ]]; then
        mv "$WEBMAIL_DIR/data" "$WEBMAIL_DATA" 2>/dev/null || true
    fi
    mkdir -p "$WEBMAIL_DATA/_data_/_default_/configs"
    mkdir -p "$WEBMAIL_DATA/_data_/_default_/themes"

    if [[ -f "$WEBMAIL_DIR/index.php" ]]; then
        sed -i "s|define('APP_DATA_FOLDER_PATH'.*|define('APP_DATA_FOLDER_PATH', '${WEBMAIL_DATA}/');|" \
            "$WEBMAIL_DIR/index.php" 2>/dev/null || true
    fi

    SNAPPY_SRC="$INSTALL_DIR/agent-src"
    if [[ -f "${SNAPPY_SRC}/../webmail-skin/config/application.ini.template" ]]; then
        cp "${SNAPPY_SRC}/../webmail-skin/config/application.ini.template" \
           "$WEBMAIL_DATA/_data_/_default_/configs/application.ini"
    fi
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
chmod 600 /etc/strata-panel/webmail-sso.php

# ── Step 23. Set admin account ────────────────────────────────────────────────
info "Setting up admin account…"
cd "$INSTALL_DIR/panel"
php artisan tinker --no-interaction <<TINKER 2>/dev/null
use App\Models\User;
\$u = User::where('email', 'admin@localhost')->orWhere('email', '${ADMIN_EMAIL}')->first();
if (\$u) {
    \$u->update([
        'name'     => '${ADMIN_NAME}',
        'email'    => '${ADMIN_EMAIL}',
        'password' => bcrypt('${ADMIN_PASSWORD}'),
    ]);
}
TINKER
success "Admin account configured: ${ADMIN_EMAIL}"

# ── Step 24. Save credentials ─────────────────────────────────────────────────
CREDS_FILE="/root/strata-credentials.txt"
cat > "$CREDS_FILE" <<EOF
# ============================================================
#  Strata Panel — Installation Credentials
#  Generated: $(date)
#  KEEP THIS FILE SECURE. chmod 600 is set automatically.
# ============================================================

Server hostname:    ${HOSTNAME_FQDN}
Server IP:          ${SERVER_IP}
Panel URL:          https://${PANEL_DOMAIN}
Webmail URL:        https://${PANEL_DOMAIN}/webmail/

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
#  Strata Panel — Uninstaller
#  Generated: $(date)
#  Run as root to fully remove Strata Panel from this server.
# =============================================================================
set -euo pipefail
[[ \$EUID -eq 0 ]] || { echo "Must be run as root."; exit 1; }

read -rp "This will PERMANENTLY remove Strata Panel and all its data. Type YES to confirm: " _CONFIRM
[[ "\$_CONFIRM" == "YES" ]] || { echo "Aborted."; exit 0; }

echo "[*] Stopping services…"
for svc in strata-agent strata-queue; do
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
rm -rf /etc/strata-agent
rm -rf /etc/strata-panel
rm -f  /usr/sbin/strata-agent
rm -f  /root/.my.cnf

echo "[*] Removing system user '${PANEL_USER}'…"
crontab -r -u '${PANEL_USER}' 2>/dev/null || true
userdel -r '${PANEL_USER}' 2>/dev/null || true

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
echo "[ok] Strata Panel removed."
echo "     Packages (mariadb-server, pdns-server, redis-server, etc.) were NOT purged."
echo "     To remove them: apt-get purge mariadb-server pdns-server pdns-backend-mysql \\"
echo "       redis-server pure-ftpd postfix dovecot-core rspamd fail2ban"
UNINSTEOF
chmod 700 /root/strata-uninstall.sh
success "Uninstall script saved to /root/strata-uninstall.sh"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║      Strata Panel installation complete!             ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}Hostname:${NC}         ${HOSTNAME_FQDN}"
echo -e "  ${BOLD}Panel URL:${NC}        https://${PANEL_DOMAIN}"
echo -e "  ${BOLD}Webmail:${NC}          https://${PANEL_DOMAIN}/webmail/"
echo -e "  ${BOLD}Admin login:${NC}      ${ADMIN_EMAIL}"
echo -e "  ${BOLD}Admin password:${NC}   (as entered)"
echo -e "  ${BOLD}Web server:${NC}       ${WEB_SERVER}"
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
echo -e "    STRATA_HMAC_SECRET=<secret> STRATA_NODE_ID=<id> bash agent.sh"
echo -e "    (generate secrets in Admin → Nodes → Add Node)"
echo ""
