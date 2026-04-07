#!/usr/bin/env bash
# =============================================================================
#  Strata Agent — Child Node Installer
#  Installs only strata-agent on a Debian 11/12 server.
#  Run as root or any sudo-capable user on the child node AFTER adding it in the panel.
#
#  Usage:
#    STRATA_HMAC_SECRET=xxx STRATA_NODE_ID=yyy bash agent.sh
#  Or interactive:
#    bash agent.sh
# =============================================================================
set -euo pipefail

# Ensure /usr/sbin and /sbin are in PATH — not guaranteed on minimal Debian installs
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}[info]${NC} $*"; }
success() { echo -e "${GREEN}[ok]${NC}   $*"; }
die()     { echo -e "${RED}[fail]${NC} $*" >&2; exit 1; }

if [[ $EUID -ne 0 ]]; then
    if command -v sudo &>/dev/null; then
        exec sudo --preserve-env=HOME,USER,LOGNAME,STRATA_HMAC_SECRET,STRATA_NODE_ID,STRATA_PORT bash "$BASH_SOURCE" "$@"
    fi
    die "Must be run as root (sudo not found)."
fi

DEBIAN_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$DEBIAN_VERSION" == "11" || "$DEBIAN_VERSION" == "12" ]] \
    || die "Debian 11 or 12 required."

# Accept env vars or prompt
HMAC_SECRET="${STRATA_HMAC_SECRET:-}"
NODE_ID="${STRATA_NODE_ID:-}"
AGENT_PORT="${STRATA_PORT:-8743}"

if [[ -z "$HMAC_SECRET" ]]; then
    read -rp "$(echo -e "${CYAN}HMAC Secret${NC} (from panel node details): ")" HMAC_SECRET
fi
if [[ -z "$NODE_ID" ]]; then
    read -rp "$(echo -e "${CYAN}Node ID${NC} (from panel node details): ")" NODE_ID
fi
[[ -n "$HMAC_SECRET" && -n "$NODE_ID" ]] || die "HMAC secret and Node ID are required."

echo ""
info "Installing strata-agent on Debian $DEBIAN_VERSION…"

# Dependencies
apt-get update -qq
apt-get install -y -qq curl wget openssl

# Go
if ! command -v go &>/dev/null; then
    info "Installing Go 1.23…"
    wget -q "https://go.dev/dl/go1.23.8.linux-amd64.tar.gz" -O /tmp/go.tar.gz
    rm -rf /usr/local/go
    tar -C /usr/local -xzf /tmp/go.tar.gz
    rm /tmp/go.tar.gz
    export PATH="/usr/local/go/bin:$PATH"
    echo 'export PATH="/usr/local/go/bin:$PATH"' >> /etc/profile.d/go.sh
fi

# Clone and build
info "Building strata-agent…"
git clone --depth=1 https://github.com/jonathjan0397/strata-hosting-panel.git /tmp/strata-src 2>/dev/null
AGENT_VERSION="$(cat /tmp/strata-src/VERSION 2>/dev/null || echo 'dev')"
cd /tmp/strata-src/agent
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/api.Version=${AGENT_VERSION}" \
    -o /usr/sbin/strata-agent \
    ./main.go
chmod 755 /usr/sbin/strata-agent
install -m 755 /tmp/strata-src/installer/agent-upgrade.sh /usr/sbin/strata-agent-upgrade
rm -rf /tmp/strata-src
success "Agent binary installed."

# TLS cert
mkdir -p /etc/strata-agent/tls
openssl req -x509 -newkey rsa:4096 \
    -keyout /etc/strata-agent/tls/key.pem \
    -out    /etc/strata-agent/tls/cert.pem \
    -days 3650 -nodes \
    -subj "/CN=strata-agent" >/dev/null 2>&1
chmod 600 /etc/strata-agent/tls/key.pem
success "TLS certificate generated."

# Show fingerprint for panel registration
FINGERPRINT=$(openssl x509 -in /etc/strata-agent/tls/cert.pem -fingerprint -sha256 -noout | cut -d= -f2)
info "TLS fingerprint: ${FINGERPRINT}"

# Systemd service
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
Environment=STRATA_HMAC_SECRET=${HMAC_SECRET}
Environment=STRATA_NODE_ID=${NODE_ID}
Environment=STRATA_PORT=${AGENT_PORT}
Environment=STRATA_TLS_CERT=/etc/strata-agent/tls/cert.pem
Environment=STRATA_TLS_KEY=/etc/strata-agent/tls/key.pem
ProtectSystem=full
PrivateTmp=true
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now strata-agent
success "strata-agent running on port ${AGENT_PORT}."

# Firewall: allow agent port from anywhere (panel will connect from primary IP)
if command -v ufw &>/dev/null; then
    ufw allow "${AGENT_PORT}/tcp" comment "strata-agent" >/dev/null 2>&1 || true
fi

echo ""
echo -e "${BOLD}${GREEN}strata-agent installed successfully.${NC}"
echo ""
echo -e "  ${BOLD}Port:${NC}          ${AGENT_PORT}"
echo -e "  ${BOLD}TLS fingerprint:${NC} ${FINGERPRINT}"
echo -e "  ${BOLD}Log:${NC}           journalctl -u strata-agent -f"
echo ""
echo -e "  Update the node TLS fingerprint in the Strata Hosting Panel admin UI."
echo ""
