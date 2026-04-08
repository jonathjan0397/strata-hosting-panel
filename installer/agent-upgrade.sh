#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
DOWNLOAD_URL="${2:-}"
WORKDIR="$(mktemp -d /tmp/strata-agent-upgrade.XXXXXX)"
BACKUP="/usr/sbin/strata-agent.backup.$(date +%Y%m%d-%H%M%S)"
WEBDAV_BACKUP="/usr/sbin/strata-webdav.backup.$(date +%Y%m%d-%H%M%S)"
NEW_BINARY="/usr/sbin/strata-agent.new"
NEW_WEBDAV_BINARY="/usr/sbin/strata-webdav.new"
export PATH="/usr/local/go/bin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

install_rspamd_if_missing() {
    if systemctl list-unit-files rspamd.service >/dev/null 2>&1; then
        return
    fi

    . /etc/os-release
    case "${VERSION_ID}" in
        13) php_codename="trixie" ;;
        12) php_codename="bookworm" ;;
        *)  php_codename="bullseye" ;;
    esac

    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y curl gnupg2 ca-certificates
    curl -fsSL https://rspamd.com/apt-stable/gpg.key | gpg --dearmor > /usr/share/keyrings/rspamd.gpg
    echo "deb [signed-by=/usr/share/keyrings/rspamd.gpg] https://rspamd.com/apt-stable/ ${php_codename} main" > /etc/apt/sources.list.d/rspamd.list
    apt-get update
    apt-get install -y rspamd
    systemctl enable rspamd >/dev/null 2>&1 || true
    systemctl restart rspamd
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

cleanup() {
    rm -rf "$WORKDIR"
}

rollback() {
    if [[ -f "$BACKUP" ]]; then
        cp -a "$BACKUP" /usr/sbin/strata-agent
        chmod 755 /usr/sbin/strata-agent
        systemctl restart strata-agent 2>/dev/null || true
    fi
    if [[ -f "$WEBDAV_BACKUP" ]]; then
        cp -a "$WEBDAV_BACKUP" /usr/sbin/strata-webdav
        chmod 755 /usr/sbin/strata-webdav
        systemctl restart strata-webdav 2>/dev/null || true
    fi
}

on_error() {
    rollback
    cleanup
    exit 1
}

trap on_error ERR
trap cleanup EXIT

[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ -n "$VERSION" && -n "$DOWNLOAD_URL" ]] || { echo "Usage: $0 <version> <download-url>" >&2; exit 1; }

command -v curl >/dev/null 2>&1 || { echo "curl is required." >&2; exit 1; }
command -v tar >/dev/null 2>&1 || { echo "tar is required." >&2; exit 1; }
command -v go >/dev/null 2>&1 || { echo "go is required." >&2; exit 1; }

case "$DOWNLOAD_URL" in
    https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/tags/*.tar.gz|\
    https://github.com/jonathjan0397/strata-hosting-panel/archive/refs/heads/*.tar.gz|\
    https://github.com/jonathjan0397/strata-hosting-panel/releases/download/*)
        ;;
    *)
        echo "download URL is not allowed: $DOWNLOAD_URL" >&2
        exit 1
        ;;
esac

curl -fL "$DOWNLOAD_URL" -o "$WORKDIR/source.tar.gz"
mkdir -p "$WORKDIR/src"
tar -xzf "$WORKDIR/source.tar.gz" -C "$WORKDIR/src" --strip-components=1
[[ -d "$WORKDIR/src/agent" ]] || { echo "archive does not contain agent source." >&2; exit 1; }

cd "$WORKDIR/src/agent"
go mod tidy
GOOS=linux GOARCH=amd64 go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/api.Version=${VERSION}" \
    -o "$NEW_BINARY" \
    .
chmod 755 "$NEW_BINARY"
GOOS=linux GOARCH=amd64 go build \
    -o "$NEW_WEBDAV_BINARY" \
    ./cmd/strata-webdav
chmod 755 "$NEW_WEBDAV_BINARY"

if [[ -f /usr/sbin/strata-agent ]]; then
    cp -a /usr/sbin/strata-agent "$BACKUP"
fi
if [[ -f /usr/sbin/strata-webdav ]]; then
    cp -a /usr/sbin/strata-webdav "$WEBDAV_BACKUP"
fi

mv "$NEW_BINARY" /usr/sbin/strata-agent
mv "$NEW_WEBDAV_BINARY" /usr/sbin/strata-webdav
install_rspamd_if_missing
repair_fail2ban_defaults
if id vmail >/dev/null 2>&1; then
    mkdir -p /var/mail/vhosts
    chown vmail:vmail /var/mail/vhosts
    chmod 0750 /var/mail/vhosts
    find /var/mail/vhosts -mindepth 1 -maxdepth 1 -type d -exec chown -R vmail:vmail {} \; -exec chmod 0750 {} \; 2>/dev/null || true
fi
mkdir -p /etc/strata-webdav
touch /etc/strata-webdav/accounts.json
chmod 600 /etc/strata-webdav/accounts.json
if [[ ! -f /etc/systemd/system/strata-webdav.service ]]; then
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
fi
systemctl daemon-reload
systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl enable strata-webdav >/dev/null 2>&1 || true
systemctl restart strata-agent
systemctl restart strata-webdav
systemctl restart fail2ban >/dev/null 2>&1 || true
systemctl restart rspamd >/dev/null 2>&1 || true
sleep 2
systemctl is-active --quiet strata-agent
systemctl is-active --quiet strata-webdav

echo "strata-agent and strata-webdav upgraded to ${VERSION}"
