#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Run with sudo on the new Ubuntu server.' >&2; exit 1; }
. /etc/os-release
[ "$ID" = ubuntu ] || { echo 'This installer supports Ubuntu only.' >&2; exit 1; }
if command -v docker >/dev/null 2>&1; then
    docker compose version
    echo 'Docker already exists; no packages or repository settings were changed.'
    exit 0
fi
apt-get update
apt-get install -y ca-certificates curl python3
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
docker compose version
