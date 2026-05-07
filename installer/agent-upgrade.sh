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

ensure_sudo_installed() {
    if command -v sudo >/dev/null 2>&1; then
        return
    fi

    echo "[*] Installing sudo..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y sudo
}

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

    apt-get update >/dev/null 2>&1 || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y python3-dkim >/dev/null 2>&1 || true
    cat > /usr/local/sbin/strata-dkim-final-sign.py <<'PY'
#!/usr/bin/env python3
import argparse
import smtplib
import sys
from email import policy
from email.parser import BytesParser
from email.utils import parseaddr
from pathlib import Path

import dkim


def normalize_crlf(raw: bytes) -> bytes:
    raw = raw.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    return raw.replace(b"\n", b"\r\n")


def extract_header_names(message: bytes) -> list[bytes]:
    header_blob = message.split(b"\r\n\r\n", 1)[0]
    names: list[bytes] = []
    for line in header_blob.split(b"\r\n"):
        if not line:
            break
        if line[:1] in (b" ", b"\t"):
            continue
        if b":" not in line:
            continue
        name = line.split(b":", 1)[0].strip().lower()
        if name in {b"return-path", b"received", b"authentication-results", b"dkim-signature", b"arc-seal", b"arc-message-signature", b"arc-authentication-results"}:
            continue
        names.append(name)
    preferred = [b"date", b"from", b"sender", b"reply-to", b"subject", b"to", b"cc", b"message-id", b"in-reply-to", b"references", b"mime-version", b"content-type", b"content-transfer-encoding"]
    ordered = [name for name in preferred if name in names]
    for name in names:
        if name not in ordered:
            ordered.append(name)
    return ordered


def determine_domain(message: bytes, sender: str) -> str:
    parsed = BytesParser(policy=policy.default).parsebytes(message, headersonly=True)
    from_header = parsed.get("From", "")
    addr = parseaddr(from_header)[1] or sender
    if "@" not in addr:
        raise SystemExit("unable to determine signing domain")
    return addr.rsplit("@", 1)[1].lower()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--sender", required=True)
    parser.add_argument("--recipient", action="append", required=True)
    args = parser.parse_args()
    raw = sys.stdin.buffer.read()
    message = normalize_crlf(raw)
    domain = determine_domain(message, args.sender)
    key_path = Path(f"/etc/opendkim/userkeys/{domain}/default.private")
    if not key_path.exists():
        raise SystemExit(f"missing DKIM private key: {key_path}")
    privkey = key_path.read_bytes()
    signature = dkim.sign(message=message, selector=b"default", domain=domain.encode(), privkey=privkey, canonicalize=(b"relaxed", b"relaxed"), include_headers=extract_header_names(message), signature_algorithm=b"rsa-sha256")
    signed = signature + message
    with smtplib.SMTP("127.0.0.1", 10031) as smtp:
        smtp.sendmail(args.sender, args.recipient, signed)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
PY
    chmod 755 /usr/local/sbin/strata-dkim-final-sign.py

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
begin = '# BEGIN STRATA DKIM FINAL SIGN\n'
end = '# END STRATA DKIM FINAL SIGN\n'
old_begin = '# BEGIN STRATA DKIM REINJECT\n'
old_end = '# END STRATA DKIM REINJECT\n'
block = (
    begin +
    'submission inet n       -       y       -       -       smtpd\n'
    '    -o syslog_name=postfix/submission\n'
    '    -o smtpd_tls_security_level=encrypt\n'
    '    -o smtpd_sasl_auth_enable=yes\n'
    '    -o smtpd_relay_restrictions=permit_sasl_authenticated,reject\n'
    '    -o content_filter=dkim-sign-pipe:\n'
    '    -o smtpd_milters=\n'
    '    -o non_smtpd_milters=\n'
    'smtps      inet  n       -       y       -       -       smtpd\n'
    '    -o syslog_name=postfix/smtps\n'
    '    -o smtpd_tls_wrappermode=yes\n'
    '    -o smtpd_sasl_auth_enable=yes\n'
    '    -o smtpd_relay_restrictions=permit_sasl_authenticated,reject\n'
    '    -o content_filter=dkim-sign-pipe:\n'
    '    -o smtpd_milters=\n'
    '    -o non_smtpd_milters=\n'
    'dkim-sign-pipe unix -       n       n       -       -       pipe\n'
    '  flags=Rq user=opendkim argv=/usr/local/sbin/strata-dkim-final-sign.py --sender ${sender} --recipient ${recipient}\n'
    '127.0.0.1:10031 inet n    -       n       -       -       smtpd\n'
    '  -o content_filter=\n'
    '  -o receive_override_options=no_header_body_checks\n'
    '  -o smtpd_helo_restrictions=\n'
    '  -o smtpd_client_restrictions=permit_mynetworks,reject\n'
    '  -o smtpd_sender_restrictions=\n'
    '  -o smtpd_recipient_restrictions=permit_mynetworks,reject\n'
    '  -o smtpd_relay_restrictions=permit_mynetworks,reject\n'
    '  -o mynetworks=127.0.0.0/8\n'
    '  -o local_recipient_maps=\n'
    '  -o relay_recipient_maps=\n'
    '  -o smtpd_milters=\n'
    '  -o non_smtpd_milters=\n'
    + end
)

if old_begin in text and old_end in text:
    start = text.index(old_begin)
    finish = text.index(old_end, start) + len(old_end)
    updated = text[:start] + block + text[finish:]
elif begin in text and end in text:
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
    postconf -P "submission/inet/content_filter=dkim-sign-pipe:" >/dev/null 2>&1 || true
    postconf -P "submission/inet/smtpd_milters=" >/dev/null 2>&1 || true
    postconf -P "submission/inet/non_smtpd_milters=" >/dev/null 2>&1 || true
    postconf -M smtps/inet="smtps inet n - y - - smtpd" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/syslog_name=postfix/smtps" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/smtpd_tls_wrappermode=yes" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/smtpd_sasl_auth_enable=yes" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" >/dev/null 2>&1 || true
    postconf -P "smtps/inet/content_filter=dkim-sign-pipe:" >/dev/null 2>&1 || true
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

ensure_sudo_installed

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

reassert_storage_mounts() {
    if [[ -f /etc/strata-agent/install.env ]]; then
        # shellcheck disable=SC1091
        source /etc/strata-agent/install.env
    fi

    if [[ -n "${STRATA_HOSTING_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$STRATA_HOSTING_STORAGE_ROOT" "/var/www" "strata-hosting-storage"
    fi
    if [[ -n "${STRATA_BACKUP_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$STRATA_BACKUP_STORAGE_ROOT" "/var/backups/strata" "strata-backup-storage"
    fi
    if [[ -n "${STRATA_MAIL_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$STRATA_MAIL_STORAGE_ROOT" "/var/mail" "strata-mail-storage"
    fi
    if [[ -n "${STRATA_MYSQL_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$STRATA_MYSQL_STORAGE_ROOT" "/var/lib/mysql" "strata-mysql-storage"
    fi
    if [[ -n "${STRATA_POSTGRES_STORAGE_ROOT:-}" ]]; then
        ensure_bind_mount "$STRATA_POSTGRES_STORAGE_ROOT" "/var/lib/postgresql" "strata-postgresql-storage"
    fi
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
    -ldflags "-X github.com/jonathjan0397/strata-hosting-panel/agent/internal/buildinfo.Version=${VERSION}" \
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
install_storage_migration_tools "$WORKDIR/src"
install_rspamd_if_missing
reassert_storage_mounts
repair_fail2ban_defaults
repair_powerdns_soa_defaults
repair_mail_tls_defaults
if id vmail >/dev/null 2>&1; then
    mkdir -p /var/mail /var/mail/vmail /var/mail/vhosts
    chown vmail:vmail /var/mail/vmail /var/mail/vhosts
    chmod 0750 /var/mail/vmail /var/mail/vhosts
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
