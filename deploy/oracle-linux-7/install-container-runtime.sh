#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

source /etc/os-release
if [[ "${ID:-}" != "ol" || "${VERSION_ID:-}" != 7.* ]]; then
    echo "This script supports Oracle Linux 7.x only; found ${PRETTY_NAME:-unknown}." >&2
    exit 1
fi

yum -y install yum-utils curl nginx openssl
yum-config-manager --enable ol7_addons
yum -y update
yum -y install docker-engine docker-cli
systemctl enable --now docker

# Oracle's OL7 Docker package does not include Compose. Compose 1.29.2 is the
# final v1 release and understands the 2.4 file used by this deployment.
if [[ "$(uname -m)" != "x86_64" ]]; then
    echo "This OL7 installer supports x86_64 only; found $(uname -m)." >&2
    exit 1
fi

compose_tmp="$(mktemp /tmp/docker-compose.XXXXXX)"
trap 'rm -f "$compose_tmp"' EXIT
curl --fail --location --proto '=https' --tlsv1.2 \
    https://github.com/docker/compose/releases/download/1.29.2/docker-compose-Linux-x86_64 \
    --output "$compose_tmp"

if [[ ! -s "$compose_tmp" ]]; then
    echo "Docker Compose download is empty." >&2
    exit 1
fi

# /usr/bin is present in sudo's secure_path on a default Oracle Linux 7 host.
install -o root -g root -m 0755 "$compose_tmp" /usr/bin/docker-compose
rm -f "$compose_tmp"
trap - EXIT

docker info >/dev/null
/usr/bin/docker-compose version

echo
echo "Container runtime installed. If yum installed a new kernel, reboot before deployment."
echo "Oracle requires UEK R5 or later: verify with 'uname -r' after reboot."
