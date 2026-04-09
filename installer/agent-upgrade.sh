#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
DOWNLOAD_URL="${2:-}"
WORKDIR="$(mktemp -d /tmp/strata-agent-upgrade.XXXXXX)"
BACKUP="/usr/sbin/strata-agent.backup.$(date +%Y%m%d-%H%M%S)"
WEBDAV_BACKUP="/usr/sbin/strata-webdav.backup.$(date +%Y%m%d-%H%M%S)"
NEW_BINARY="$WORKDIR/strata-agent"
NEW_WEBDAV_BINARY="$WORKDIR/strata-webdav"
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

derive_parent_domain() {
    local host_candidate=""

    host_candidate="${STRATA_NODE_HOSTNAME:-}"
    if [[ -z "$host_candidate" && -f /etc/strata-agent/agent.env ]]; then
        host_candidate="$(grep -E '^STRATA_NODE_HOSTNAME=' /etc/strata-agent/agent.env | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    fi
    if [[ -z "$host_candidate" ]]; then
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
    local mail_tls_dir="/etc/strata-agent/mail-tls"
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
EOF
    fi

    systemctl restart dovecot >/dev/null 2>&1 || true
    systemctl restart postfix >/dev/null 2>&1 || true
}

detect_goarch() {
    case "$(uname -m)" in
        x86_64|amd64) echo "amd64" ;;
        aarch64|arm64) echo "arm64" ;;
        armv7l) echo "arm" ;;
        *)
            echo "unsupported architecture: $(uname -m)" >&2
            exit 1
            ;;
    esac
}

validate_binary() {
    local path="$1"

    [[ -s "$path" ]] || {
        echo "binary validation failed: $path is empty" >&2
        exit 1
    }

    file "$path" | grep -Eq 'ELF .* executable' || {
        echo "binary validation failed: $path is not a native executable" >&2
        exit 1
    }
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
command -v file >/dev/null 2>&1 || { echo "file is required." >&2; exit 1; }

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
GOARCH_TARGET="$(detect_goarch)"
GOOS=linux GOARCH="$GOARCH_TARGET" go build \
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/api.Version=${VERSION}" \
    -o "$NEW_BINARY" \
    .
chmod 755 "$NEW_BINARY"
GOOS=linux GOARCH="$GOARCH_TARGET" go build \
    -o "$NEW_WEBDAV_BINARY" \
    ./cmd/strata-webdav
chmod 755 "$NEW_WEBDAV_BINARY"
validate_binary "$NEW_BINARY"
validate_binary "$NEW_WEBDAV_BINARY"

if [[ -f /usr/sbin/strata-agent ]]; then
    cp -a /usr/sbin/strata-agent "$BACKUP"
fi
if [[ -f /usr/sbin/strata-webdav ]]; then
    cp -a /usr/sbin/strata-webdav "$WEBDAV_BACKUP"
fi

install -m 755 "$NEW_BINARY" /usr/sbin/strata-agent
install -m 755 "$NEW_WEBDAV_BINARY" /usr/sbin/strata-webdav
install_rspamd_if_missing
repair_fail2ban_defaults
repair_powerdns_soa_defaults
repair_mail_tls_defaults
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
