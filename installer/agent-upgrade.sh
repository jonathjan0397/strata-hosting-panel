#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
DOWNLOAD_URL="${2:-}"
WORKDIR="$(mktemp -d /tmp/strata-agent-upgrade.XXXXXX)"
BACKUP="/usr/sbin/strata-agent.backup.$(date +%Y%m%d-%H%M%S)"
NEW_BINARY="/usr/sbin/strata-agent.new"
export PATH="/usr/local/go/bin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

cleanup() {
    rm -rf "$WORKDIR"
}

rollback() {
    if [[ -f "$BACKUP" ]]; then
        cp -a "$BACKUP" /usr/sbin/strata-agent
        chmod 755 /usr/sbin/strata-agent
        systemctl restart strata-agent 2>/dev/null || true
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

if [[ -f /usr/sbin/strata-agent ]]; then
    cp -a /usr/sbin/strata-agent "$BACKUP"
fi

mv "$NEW_BINARY" /usr/sbin/strata-agent
systemctl restart strata-agent
sleep 2
systemctl is-active --quiet strata-agent

echo "strata-agent upgraded to ${VERSION}"
