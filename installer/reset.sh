#!/usr/bin/env bash
# =============================================================================
#  Strata Hosting Panel — Hard Reset Script
#  Removes everything installed by install.sh and returns the server to a
#  clean Debian base. Packages, databases, users, files, and configs are all
#  purged. This action is IRREVERSIBLE.
#
#  Usage:
#    bash reset.sh
#  Or fetch and run directly:
#    bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/reset.sh)
# =============================================================================
set -uo pipefail

# Ensure /usr/sbin and /sbin are in PATH — not guaranteed on minimal Debian installs
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

RED='\033[0;31m'; YELLOW='\033[1;33m'; GREEN='\033[0;32m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

step()    { echo -e "\n${CYAN}[*]${NC} $*"; }
ok()      { echo -e "${GREEN}[ok]${NC} $*"; }
warn()    { echo -e "${YELLOW}[warn]${NC} $*"; }

[[ $EUID -eq 0 ]] || { echo -e "${RED}Must be run as root.${NC}"; exit 1; }

echo ""
echo -e "${BOLD}${RED}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${RED}║          Strata Hosting Panel — Hard Reset                   ║${NC}"
echo -e "${BOLD}${RED}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  This script will ${BOLD}permanently remove${NC}:"
echo -e "    • All Strata Hosting Panel files, databases, and configuration"
echo -e "    • MariaDB, PowerDNS, Redis, Pure-FTPd, Postfix, Dovecot"
echo -e "    • Rspamd, OpenDKIM, fail2ban, Nginx/Apache2"
echo -e "    • PHP 8.1 / 8.2 / 8.3, Node.js, Go, Composer, acme.sh"
echo -e "    • System users: strata, vmail"
echo -e "    • All mail data in /var/mail/vmail"
echo ""
echo -e "  ${RED}There is no undo. Make sure you have a server snapshot first.${NC}"
echo ""
read -rp "  Type RESET to confirm, or anything else to abort: " _CONFIRM
echo ""
[[ "$_CONFIRM" == "RESET" ]] || { echo "Aborted."; exit 0; }

# ── 1. Stop and disable all Strata services ───────────────────────────────────
step "Stopping services…"
for svc in strata-agent strata-queue pdns pure-ftpd redis-server \
           postfix dovecot rspamd opendkim fail2ban nginx apache2; do
    if systemctl list-units --full -all 2>/dev/null | grep -q "^${svc}\.service"; then
        systemctl stop    "$svc" 2>/dev/null || true
        systemctl disable "$svc" 2>/dev/null || true
    fi
done

# Remove Strata systemd unit files
rm -f /etc/systemd/system/strata-agent.service
rm -f /etc/systemd/system/strata-queue.service
systemctl daemon-reload
ok "Services stopped."

# ── 2. Drop databases ─────────────────────────────────────────────────────────
step "Dropping Strata databases…"
# Try with /root/.my.cnf credentials first, then no-password (fresh MariaDB)
_mysql() { mysql "$@" 2>/dev/null || true; }
_mysql -u root -h 127.0.0.1 <<'SQLEOF'
DROP DATABASE IF EXISTS strata_panel;
DROP USER IF EXISTS 'strata'@'localhost';
DROP DATABASE IF EXISTS pdns;
DROP USER IF EXISTS 'pdns'@'localhost';
FLUSH PRIVILEGES;
SQLEOF
ok "Databases dropped."

# ── 3. Purge all installed packages ──────────────────────────────────────────
step "Purging packages…"
DEBIAN_FRONTEND=noninteractive apt-get purge -y \
    mariadb-server mariadb-client mariadb-common \
    postgresql postgresql-client postgresql-common \
    pdns-server pdns-backend-mysql \
    pure-ftpd pure-ftpd-common \
    redis-server \
    postfix postfix-mysql \
    dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql \
    opendkim opendkim-tools libsasl2-modules \
    rspamd \
    fail2ban \
    nginx nginx-common \
    apache2 apache2-utils apache2-bin \
    nodejs \
    2>/dev/null || true

# Purge all PHP versions
DEBIAN_FRONTEND=noninteractive apt-get purge -y \
    'php8.1*' 'php8.2*' 'php8.3*' \
    2>/dev/null || true

DEBIAN_FRONTEND=noninteractive apt-get autoremove --purge -y 2>/dev/null || true
DEBIAN_FRONTEND=noninteractive apt-get autoclean -y 2>/dev/null || true
ok "Packages purged."

# ── 4. Remove Strata files and directories ────────────────────────────────────
step "Removing Strata files…"
rm -rf /opt/strata-panel
rm -rf /etc/strata-agent
rm -rf /etc/strata-panel
rm -f  /usr/sbin/strata-agent
rm -f  /root/.my.cnf
rm -f  /root/strata-credentials.txt
rm -f  /root/strata-uninstall.sh
ok "Strata files removed."

# ── 5. Remove webmail ─────────────────────────────────────────────────────────
step "Removing SnappyMail webmail…"
rm -rf /var/www/webmail
rm -rf /var/lib/snappymail
ok "Webmail removed."

# ── 6. Remove mail data ───────────────────────────────────────────────────────
step "Removing mail data…"
rm -rf /var/mail/vmail
rm -rf /var/spool/postfix/opendkim
ok "Mail data removed."

# ── 7. Remove config remnants ─────────────────────────────────────────────────
step "Removing config remnants…"
rm -rf /etc/powerdns
rm -rf /etc/opendkim
rm -f  /etc/opendkim.conf
rm -f  /etc/fail2ban/jail.local
rm -rf /etc/pureftpd
rm -f  /etc/pure-ftpd/conf/VirtualChroot
rm -f  /etc/pure-ftpd/conf/PureDB
rm -f  /etc/pure-ftpd/conf/NoAnonymous
rm -f  /etc/pure-ftpd/conf/ChrootEveryone
rm -f  /etc/pure-ftpd/conf/PassivePortRange
rm -f  /etc/pure-ftpd/conf/TLS
rm -f  /etc/ssl/private/pure-ftpd.pem
rm -f  /etc/postfix/mysql-virtual-mailbox-domains.cf
rm -f  /etc/postfix/mysql-virtual-mailbox-maps.cf
rm -f  /etc/postfix/mysql-virtual-alias-maps.cf
rm -f  /etc/nginx/sites-available/strata-panel
rm -f  /etc/nginx/sites-enabled/strata-panel
rm -f  /etc/apache2/sites-available/strata-panel.conf
rm -f  /etc/apache2/sites-enabled/strata-panel.conf
ok "Config remnants removed."

# ── 8. Remove apt sources added by installer ─────────────────────────────────
step "Removing apt sources…"
rm -f /etc/apt/sources.list.d/php.list
rm -f /etc/apt/sources.list.d/rspamd.list
rm -f /etc/apt/sources.list.d/nodesource.list
rm -f /usr/share/keyrings/deb.sury.org-php.gpg
rm -f /usr/share/keyrings/rspamd.gpg
apt-get update -q 2>/dev/null || true
ok "Apt sources removed."

# ── 9. Remove Go, Node tools, Composer, acme.sh ──────────────────────────────
step "Removing Go, Composer, acme.sh…"
rm -rf /usr/local/go
rm -f  /etc/profile.d/go.sh
rm -f  /usr/local/bin/composer
rm -rf /root/.acme.sh
rm -rf /root/.composer
ok "Go / Composer / acme.sh removed."

# ── 10. Remove system users ───────────────────────────────────────────────────
step "Removing system users…"
crontab -r -u strata 2>/dev/null || true
/usr/sbin/userdel -r strata  2>/dev/null || true
/usr/sbin/userdel -r vmail   2>/dev/null || true
/usr/sbin/groupdel  vmail    2>/dev/null || true
ok "System users removed."

# ── 11. Revert /etc/hosts entry added by installer ───────────────────────────
step "Reverting /etc/hosts…"
# Remove any 127.0.1.1 line the installer may have added
sed -i '/^127\.0\.1\.1/d' /etc/hosts 2>/dev/null || true
ok "/etc/hosts reverted."

# ── 12. Revert systemd-resolved stub if we disabled it ───────────────────────
step "Checking systemd-resolved…"
if grep -q 'DNSStubListener=no' /etc/systemd/resolved.conf 2>/dev/null; then
    sed -i '/^DNSStubListener/d' /etc/systemd/resolved.conf
    systemctl restart systemd-resolved 2>/dev/null || true
    ok "systemd-resolved stub re-enabled."
else
    ok "systemd-resolved unchanged."
fi

# ── 13. Reset UFW to defaults ─────────────────────────────────────────────────
step "Resetting UFW firewall…"
ufw --force reset >/dev/null 2>&1 || true
ufw disable       >/dev/null 2>&1 || true
ok "UFW reset and disabled."

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║      Server reset to stock. All Strata data gone.    ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
warn "The hostname set during install was NOT reverted — change it manually:"
warn "  hostnamectl set-hostname <original-hostname>"
echo ""
