#!/usr/bin/env bash
# =============================================================================
#  Strata Panel — Installer
#  Supported: Debian 11 (Bullseye) · Debian 12 (Bookworm)
#  Run as root on a fresh server.
#
#  Usage:
#    curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/install.sh | bash
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

# ── Checks ────────────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Must be run as root."

DEBIAN_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$DEBIAN_VERSION" == "11" || "$DEBIAN_VERSION" == "12" ]] \
    || die "Unsupported OS. Debian 11 or 12 required (detected: $DEBIAN_VERSION)."

HOSTNAME_FQDN=$(hostname -f 2>/dev/null || hostname)
SERVER_IP=$(curl -4 -fsSL https://icanhazip.com 2>/dev/null || hostname -I | awk '{print $1}')

echo -e "\n${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║        Strata Panel Installer            ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════╝${NC}\n"
info "Debian $DEBIAN_VERSION · $HOSTNAME_FQDN · $SERVER_IP"
echo ""

# ── Configuration prompts ─────────────────────────────────────────────────────
read -rp "$(echo -e "${CYAN}Panel domain${NC} (e.g. panel.example.com): ")" PANEL_DOMAIN
[[ -n "$PANEL_DOMAIN" ]] || die "Panel domain is required."

read -rp "$(echo -e "${CYAN}Admin email${NC}: ")" ADMIN_EMAIL
[[ -n "$ADMIN_EMAIL" ]] || die "Admin email is required."

read -rsp "$(echo -e "${CYAN}Admin password${NC} (min 12 chars): ")" ADMIN_PASSWORD
echo ""
[[ ${#ADMIN_PASSWORD} -ge 12 ]] || die "Password must be at least 12 characters."

# ── Install directory ─────────────────────────────────────────────────────────
INSTALL_DIR="/opt/strata-panel"
AGENT_DIR="/opt/strata-agent"
PANEL_USER="strata"
AGENT_HMAC_SECRET=$(openssl rand -hex 32)
AGENT_NODE_ID=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen)
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
PDNS_DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
PDNS_API_KEY=$(openssl rand -hex 32)
INSTALL_TOKEN=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen)
INSTALL_SECRET=$(openssl rand -hex 32)
APP_KEY="base64:$(openssl rand -base64 32)"

echo ""
info "Starting installation…"
echo ""

# ── 1. System update ──────────────────────────────────────────────────────────
info "Updating package lists…"
apt-get update -qq

info "Installing base packages…"
apt-get install -y -qq \
    curl wget gnupg2 ca-certificates lsb-release \
    software-properties-common apt-transport-https \
    git unzip zip openssl ufw fail2ban \
    acl sudo

# ── 2. PHP (Ondrej PPA) ───────────────────────────────────────────────────────
info "Adding PHP repository (ondrej/php)…"
if [[ "$DEBIAN_VERSION" == "12" ]]; then
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg \
        https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list
else
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg \
        https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bullseye main" \
        > /etc/apt/sources.list.d/php.list
fi
apt-get update -qq

PHP_VERSIONS=(8.1 8.2 8.3)
PHP_EXTENSIONS="fpm cli common curl mbstring xml zip bcmath intl gd mysql redis"

info "Installing PHP 8.1, 8.2, 8.3 + extensions…"
for VER in "${PHP_VERSIONS[@]}"; do
    PKG_LIST=""
    for EXT in $PHP_EXTENSIONS; do
        PKG_LIST="$PKG_LIST php${VER}-${EXT}"
    done
    apt-get install -y -qq $PKG_LIST
done
success "PHP installed."

# ── 3. MariaDB ────────────────────────────────────────────────────────────────
info "Installing MariaDB…"
apt-get install -y -qq mariadb-server mariadb-client

systemctl enable --now mariadb

info "Securing MariaDB…"
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';" 2>/dev/null || true
mysql -u root -p"${DB_PASSWORD}" -e "
    DELETE FROM mysql.user WHERE User='';
    DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
    DROP DATABASE IF EXISTS test;
    DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
    CREATE DATABASE IF NOT EXISTS strata_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'strata'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON strata_panel.* TO 'strata'@'localhost';
    FLUSH PRIVILEGES;
" 2>/dev/null
success "MariaDB ready. Root password saved to /root/.my.cnf"
echo "[client]
user=root
password=${DB_PASSWORD}" > /root/.my.cnf
chmod 600 /root/.my.cnf

# ── 3b. PowerDNS ─────────────────────────────────────────────────────────────
info "Installing PowerDNS with MySQL backend…"
apt-get install -y -qq pdns-server pdns-backend-mysql

# Create PowerDNS database and user
MYSQL_PWD="${DB_PASSWORD}" mysql -u root -h 127.0.0.1 -e "
    CREATE DATABASE IF NOT EXISTS pdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON pdns.* TO 'pdns'@'localhost';
    FLUSH PRIVILEGES;
"

# Import PowerDNS MySQL schema
SCHEMA_FILE=$(find /usr/share -name "schema.mysql.sql" 2>/dev/null | head -1)
if [[ -n "$SCHEMA_FILE" ]]; then
    MYSQL_PWD="${PDNS_DB_PASSWORD}" mysql -u pdns -h 127.0.0.1 pdns < "$SCHEMA_FILE" 2>/dev/null || true
else
    warn "PowerDNS MySQL schema not found — import manually from pdns-backend-mysql docs."
fi

# Write PowerDNS configuration
mkdir -p /etc/powerdns
cat > /etc/powerdns/pdns.conf <<EOF
# PowerDNS — Strata Panel managed
local-address=0.0.0.0
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

# Disable systemd-resolved stub on port 53 if needed
if ss -tlnp | grep -q ':53 '; then
    if systemctl is-active --quiet systemd-resolved; then
        sed -i 's/#DNSStubListener=yes/DNSStubListener=no/' /etc/systemd/resolved.conf
        sed -i 's/DNSStubListener=yes/DNSStubListener=no/' /etc/systemd/resolved.conf
        systemctl restart systemd-resolved
        ln -sf /run/systemd/resolve/resolv.conf /etc/resolv.conf
    fi
fi

systemctl enable --now pdns
success "PowerDNS ready (API on 127.0.0.1:8053)."

# ── 3c. Pure-FTPd ─────────────────────────────────────────────────────────────
info "Installing Pure-FTPd…"
apt-get install -y -qq pure-ftpd pure-ftpd-common openssl

mkdir -p /etc/pureftpd
touch /etc/pureftpd/passwd

# Configure Pure-FTPd to use virtual users
echo "yes" > /etc/pure-ftpd/conf/VirtualChroot
echo "/etc/pureftpd/pureftpd.pdb" > /etc/pure-ftpd/conf/PureDB
echo "yes" > /etc/pure-ftpd/conf/NoAnonymous
echo "yes" > /etc/pure-ftpd/conf/ChrootEveryone

# Generate TLS certificate for FTPS
mkdir -p /etc/ssl/private
openssl req -x509 -newkey rsa:2048 \
    -keyout /etc/ssl/private/pure-ftpd.pem \
    -out    /etc/ssl/private/pure-ftpd.pem \
    -days   3650 -nodes \
    -subj   "/CN=${HOSTNAME_FQDN}" >/dev/null 2>&1
chmod 600 /etc/ssl/private/pure-ftpd.pem
echo "1" > /etc/pure-ftpd/conf/TLS

# Build the DB (empty to start)
pure-pw mkdb /etc/pureftpd/pureftpd.pdb -f /etc/pureftpd/passwd 2>/dev/null || true

systemctl enable --now pure-ftpd
success "Pure-FTPd ready (virtual users, TLS enabled)."

# ── 4. Redis ──────────────────────────────────────────────────────────────────
info "Installing Redis…"
apt-get install -y -qq redis-server
sed -i 's/^supervised no/supervised systemd/' /etc/redis/redis.conf
systemctl enable --now redis-server
success "Redis ready."

# ── 5. Nginx ──────────────────────────────────────────────────────────────────
info "Installing Nginx…"
apt-get install -y -qq nginx
systemctl enable nginx
success "Nginx installed."

# ── 6. Node.js 20 ─────────────────────────────────────────────────────────────
info "Installing Node.js 20…"
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
apt-get install -y -qq nodejs
success "Node.js $(node -v) installed."

# ── 7. Composer ───────────────────────────────────────────────────────────────
info "Installing Composer…"
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
success "Composer $(composer --version --no-ansi 2>&1 | head -1) installed."

# ── 8. Go (for agent build) ───────────────────────────────────────────────────
info "Installing Go 1.23…"
GO_VERSION="1.23.8"
wget -q "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -O /tmp/go.tar.gz
rm -rf /usr/local/go
tar -C /usr/local -xzf /tmp/go.tar.gz
rm /tmp/go.tar.gz
export PATH="/usr/local/go/bin:$PATH"
echo 'export PATH="/usr/local/go/bin:$PATH"' >> /etc/profile.d/go.sh
success "Go $(go version) installed."

# ── 9. acme.sh (SSL) ──────────────────────────────────────────────────────────
info "Installing acme.sh…"
curl -fsSL https://get.acme.sh | sh -s email="$ADMIN_EMAIL" >/dev/null 2>&1 || warn "acme.sh install failed — install manually later"
/root/.acme.sh/acme.sh --set-default-ca --server letsencrypt 2>/dev/null || true
success "acme.sh ready."

# ── 10. System user ───────────────────────────────────────────────────────────
info "Creating system user '${PANEL_USER}'…"
id "$PANEL_USER" &>/dev/null || useradd -r -m -d "$INSTALL_DIR" -s /bin/bash "$PANEL_USER"
success "User '${PANEL_USER}' ready."

# ── 11. Clone panel ───────────────────────────────────────────────────────────
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

# ── 12. Panel .env ────────────────────────────────────────────────────────────
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
REDIS_PASSWORD=null
REDIS_PORT=6379

STRATA_AGENT_LOCAL_SECRET=${AGENT_HMAC_SECRET}
STRATA_AGENT_LOCAL_NODE_ID=${AGENT_NODE_ID}

STRATA_PDNS_API_KEY=${PDNS_API_KEY}
STRATA_DB_ROOT_PASSWORD=${DB_PASSWORD}

# License server — set STRATA_LICENSE_SERVER_URL to enable feature gating.
# Leave blank for Community edition (all free features, no remote checks).
STRATA_INSTALL_TOKEN=${INSTALL_TOKEN}
STRATA_INSTALL_SECRET=${INSTALL_SECRET}
STRATA_LICENSE_SERVER_URL=
STRATA_VERSION=1.0.0
EOF
chmod 600 "$INSTALL_DIR/panel/.env"

# ── 13. Composer install + migrations ────────────────────────────────────────
info "Installing PHP dependencies…"
cd "$INSTALL_DIR/panel"
composer install --no-dev --optimize-autoloader --no-interaction -q

info "Running database migrations…"
php artisan migrate --force --seed

info "Initial license sync…"
php artisan strata:license-sync || warn "License sync skipped (no server configured — OK for Community edition)."

info "Building frontend assets…"
npm ci --silent
npm run build

info "Caching config…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Fix permissions
chown -R "$PANEL_USER":www-data "$INSTALL_DIR/panel"
find "$INSTALL_DIR/panel" -type f -exec chmod 644 {} \;
find "$INSTALL_DIR/panel" -type d -exec chmod 755 {} \;
chmod -R 775 "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel/bootstrap/cache"
success "Panel configured."

# ── 14. Build agent ───────────────────────────────────────────────────────────
info "Building strata-agent…"
cd "$INSTALL_DIR/agent-src"
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-panel/agent/internal/api.Version=$(git -C "$INSTALL_DIR" describe --tags --always 2>/dev/null || echo dev)" \
    -o /usr/sbin/strata-agent \
    ./main.go
chmod 755 /usr/sbin/strata-agent
success "strata-agent built."

# ── 15. Agent TLS cert (self-signed for localhost) ───────────────────────────
info "Generating agent TLS certificate…"
mkdir -p /etc/strata-agent/tls
openssl req -x509 -newkey rsa:4096 -keyout /etc/strata-agent/tls/key.pem \
    -out /etc/strata-agent/tls/cert.pem -days 3650 -nodes \
    -subj "/CN=strata-agent" \
    -addext "subjectAltName=IP:127.0.0.1" >/dev/null 2>&1
chmod 600 /etc/strata-agent/tls/key.pem
success "Agent TLS certificate ready."

# ── 16. Agent systemd service ─────────────────────────────────────────────────
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

# ── 17. Panel queue worker (systemd) ─────────────────────────────────────────
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

# ── 18. Nginx vhost for panel ─────────────────────────────────────────────────
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

    root ${INSTALL_DIR}/panel/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }
    location ~* \.(env|git|log|sql)$ { deny all; }

    client_max_body_size 64M;
}
EOF

ln -sf /etc/nginx/sites-available/strata-panel /etc/nginx/sites-enabled/strata-panel
rm -f /etc/nginx/sites-enabled/default

# ── 19. SSL for panel domain ──────────────────────────────────────────────────
info "Issuing SSL certificate for $PANEL_DOMAIN via Let's Encrypt…"
mkdir -p /etc/strata-panel/tls

# Temporarily serve on port 80 for ACME challenge
nginx -t 2>/dev/null && systemctl reload nginx || true

# Issue cert (will fail if DNS not pointing here — fallback to self-signed)
if /root/.acme.sh/acme.sh --issue --nginx -d "$PANEL_DOMAIN" --keylength 4096 >/dev/null 2>&1; then
    /root/.acme.sh/acme.sh --install-cert -d "$PANEL_DOMAIN" \
        --key-file       /etc/strata-panel/tls/privkey.pem \
        --fullchain-file /etc/strata-panel/tls/fullchain.pem \
        --reloadcmd      "systemctl reload nginx"
    success "SSL certificate issued for $PANEL_DOMAIN."
else
    warn "Let's Encrypt failed (DNS may not be pointing here yet). Using self-signed cert."
    openssl req -x509 -newkey rsa:4096 \
        -keyout /etc/strata-panel/tls/privkey.pem \
        -out    /etc/strata-panel/tls/fullchain.pem \
        -days   90 -nodes \
        -subj "/CN=${PANEL_DOMAIN}" >/dev/null 2>&1
    warn "Re-run: /root/.acme.sh/acme.sh --issue --nginx -d ${PANEL_DOMAIN} once DNS is ready."
fi

nginx -t && systemctl reload nginx
success "Nginx configured."

# ── 20. Firewall ──────────────────────────────────────────────────────────────
info "Configuring UFW firewall…"
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow ssh >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 53/tcp >/dev/null     # DNS (TCP for zone transfers)
ufw allow 53/udp >/dev/null     # DNS (UDP for queries)
ufw allow 21/tcp >/dev/null     # FTP control
ufw allow 30000:50000/tcp >/dev/null  # FTP passive range
ufw allow 25/tcp >/dev/null     # SMTP
ufw allow 587/tcp >/dev/null    # SMTP submission
ufw allow 993/tcp >/dev/null    # IMAPS
ufw allow 995/tcp >/dev/null    # POP3S
# Agent port is NOT opened externally — panel talks to it on localhost only
ufw --force enable >/dev/null
success "Firewall enabled (SSH, HTTP, HTTPS, DNS, FTP, Mail open)."

# ── 21. Register primary node in panel DB ─────────────────────────────────────
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
        'status'      => 'online',
        'is_primary'  => true,
        'last_seen_at'=> now(),
    ]
);
TINKER
success "Primary node registered."

# ── 22. Update admin password ─────────────────────────────────────────────────
info "Updating admin account password…"
cd "$INSTALL_DIR/panel"
php artisan tinker --no-interaction <<TINKER 2>/dev/null
use App\Models\User;
\$u = User::where('email', 'admin@localhost')->first();
if (\$u) {
    \$u->update(['email' => '${ADMIN_EMAIL}', 'password' => bcrypt('${ADMIN_PASSWORD}')]);
}
TINKER
success "Admin account updated: ${ADMIN_EMAIL}"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║   Strata Panel installation complete!               ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}Panel URL:${NC}       https://${PANEL_DOMAIN}"
echo -e "  ${BOLD}Admin login:${NC}     ${ADMIN_EMAIL}"
echo -e "  ${BOLD}Admin password:${NC}  (as entered)"
echo ""
echo -e "  ${BOLD}MariaDB root:${NC}    /root/.my.cnf"
echo -e "  ${BOLD}Panel .env:${NC}      ${INSTALL_DIR}/panel/.env"
echo -e "  ${BOLD}Agent log:${NC}       journalctl -u strata-agent -f"
echo -e "  ${BOLD}Queue log:${NC}       journalctl -u strata-queue -f"
echo -e "  ${BOLD}PowerDNS API:${NC}    127.0.0.1:8053 (key in .env)"
echo -e "  ${BOLD}FTP:${NC}             port 21, virtual users via Pure-FTPd"
echo -e "  ${BOLD}Install token:${NC}   ${INSTALL_TOKEN}"
echo -e "  ${YELLOW}To enable premium features, set STRATA_LICENSE_SERVER_URL in .env${NC}"
echo ""
echo -e "  ${YELLOW}If using a self-signed cert, add DNS A record for ${PANEL_DOMAIN}"
echo -e "  then run: /root/.acme.sh/acme.sh --issue --nginx -d ${PANEL_DOMAIN}${NC}"
echo ""
