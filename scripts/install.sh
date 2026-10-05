#!/usr/bin/env bash
#
# PrivateCloud installer for a fresh Ubuntu 24.04 LTS server (e.g. a Vultr VPS).
#
#   sudo ./scripts/install.sh --domain cloud.example.com --email you@example.com
#
# Safe to re-run: an existing .env is never overwritten, and every step checks
# what is already in place before changing anything.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="/var/lib/privatecloud"
DOMAIN=""
EMAIL=""
SKIP_FIREWALL=0
SKIP_SWAP=0
ADMIN_EMAIL="${PC_ADMIN_EMAIL:-}"

usage() {
  cat <<USAGE
Usage: sudo $0 --domain <dashboard domain> --email <letsencrypt email> [options]

Options:
  --domain NAME       Dashboard hostname, e.g. cloud.example.com (required)
  --email ADDRESS     Email for Let's Encrypt notices (recommended)
  --admin-email EMAIL Administrator login email (default: --email)
  --data-dir PATH     Data directory (default: /var/lib/privatecloud)
  --skip-firewall     Do not configure ufw
  --skip-swap         Do not create a swap file on small servers
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
    --data-dir) DATA_DIR="$2"; shift 2 ;;
    --skip-firewall) SKIP_FIREWALL=1; shift ;;
    --skip-swap) SKIP_SWAP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
rand() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }
compose() { docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" "$@"; }

# ---------------------------------------------------------------- checks
step "Checking the server"
[[ $EUID -eq 0 ]] || fail "Run this installer as root (sudo)."
[[ -n "$DOMAIN" ]] || { usage; fail "--domain is required."; }
[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail "\"$DOMAIN\" is not a valid domain name."
[[ "$DATA_DIR" = /* ]] || fail "--data-dir must be an absolute path."
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL}"

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
  warn "This installer is tested on Ubuntu 24.04. Detected: ${PRETTY_NAME:-unknown}. Continuing anyway."
fi
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
DISK_GB=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
info "Memory: ${MEM_MB} MB, free disk: ${DISK_GB} GB"
(( MEM_MB >= 900 )) || warn "Less than 1 GB of RAM: builds will be slow or fail."
(( DISK_GB >= 10 )) || warn "Less than 10 GB of free disk: images and backups need space."
for port in 80 443; do
  if ss -ltn "sport = :$port" | grep -q LISTEN && ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^privatecloud-caddy$'; then
    fail "Port $port is already in use by another program. Stop it (e.g. nginx/apache) and run the installer again."
  fi
done

# ------------------------------------------------------------- packages
step "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl git openssl ufw dnsutils >/dev/null
info "done"

step "Installing Docker Engine"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  info "Docker $(docker version --format '{{.Server.Version}}') is already installed"
else
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
  info "Installed Docker $(docker version --format '{{.Server.Version}}')"
fi
if [[ ! -f /etc/docker/daemon.json ]]; then
  # Rotate container logs so they cannot fill the disk.
  cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
JSON
  info "Configured Docker log rotation"
fi
systemctl enable --now docker >/dev/null
systemctl restart docker

# -------------------------------------------------------------- firewall
if (( SKIP_FIREWALL == 0 )); then
  step "Configuring the firewall (ufw)"
  ufw allow OpenSSH >/dev/null
  ufw allow 80/tcp >/dev/null
  ufw allow 443/tcp >/dev/null
  ufw allow 443/udp >/dev/null
  if ! ufw status | grep -q "Status: active"; then
    ufw --force enable >/dev/null
  fi
  info "Allowed: SSH (22), HTTP (80), HTTPS (443). PostgreSQL, Redis and the Docker API are not published."
fi

# ------------------------------------------------------------------ swap
if (( SKIP_SWAP == 0 )) && (( MEM_MB < 4096 )) && [[ -z "$(swapon --show --noheadings)" ]]; then
  step "Creating a 2 GB swap file (helps Docker builds on small servers)"
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  info "done"
fi

# ---------------------------------------------------------- data + .env
step "Preparing directories"
mkdir -p "$DATA_DIR"/{caddy/sites,caddy/logs,backups,builds}
chown -R 33:33 "$DATA_DIR"
chmod 750 "$DATA_DIR"
info "$DATA_DIR"

step "Writing configuration"
PUBLIC_IPV4="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
PUBLIC_IPV6="$(curl -6 -fsS --max-time 5 https://api64.ipify.org 2>/dev/null || true)"
DOCKER_GID="$(stat -c %g /var/run/docker.sock)"
if [[ -f "$ROOT/.env" ]]; then
  warn "$ROOT/.env already exists: keeping it unchanged (delete it to regenerate)."
else
  umask 077
  cat > "$ROOT/.env" <<ENV
# Generated by scripts/install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ). Keep this file private.
PC_NAME=PrivateCloud
PC_DASHBOARD_DOMAIN=${DOMAIN}
APP_URL=https://${DOMAIN}
PC_ACME_EMAIL=${EMAIL}
PC_PUBLIC_IPV4=${PUBLIC_IPV4}
PC_PUBLIC_IPV6=${PUBLIC_IPV6}
PC_HOST_HOSTNAME=$(hostname)
PC_DATA_DIR=${DATA_DIR}

APP_NAME=PrivateCloud
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
LOG_LEVEL=info
SESSION_LIFETIME=480
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

DB_PASSWORD=$(rand 40)
PC_APPS_DB_ADMIN_PASSWORD=$(rand 40)

PC_UID=33
PC_GID=33
DOCKER_GID=${DOCKER_GID}
PC_AUTO_HTTPS=on
ENV
  chmod 600 "$ROOT/.env"
  info "Created $ROOT/.env with random secrets (mode 600)"
fi

# ------------------------------------------------------------------- DNS
step "Checking DNS for ${DOMAIN}"
RESOLVED="$(dig +short A "$DOMAIN" | tail -1 || true)"
if [[ "$RESOLVED" == "$PUBLIC_IPV4" ]]; then
  info "${DOMAIN} points to this server (${PUBLIC_IPV4})"
else
  warn "${DOMAIN} resolves to '${RESOLVED:-nothing}', but this server is ${PUBLIC_IPV4}."
  warn "Create an A record ${DOMAIN} -> ${PUBLIC_IPV4}. HTTPS starts working automatically once DNS is correct."
fi

# ----------------------------------------------------------------- start
step "Building PrivateCloud (this takes a few minutes the first time)"
compose build
step "Starting services"
compose up -d
info "Waiting for the API to become healthy…"
for _ in $(seq 1 90); do
  status="$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null || echo starting)"
  [[ "$status" == "healthy" ]] && break
  sleep 2
done
[[ "$status" == "healthy" ]] || fail "The API did not become healthy. Inspect: docker logs privatecloud-app"
info "All services are running"

step "Installing the systemd unit"
sed "s#__ROOT__#${ROOT}#g" "$ROOT/infrastructure/systemd/privatecloud.service" > /etc/systemd/system/privatecloud.service
systemctl daemon-reload
systemctl enable privatecloud.service >/dev/null
info "PrivateCloud starts automatically after a reboot"

# ----------------------------------------------------------------- admin
step "Administrator account"
if compose exec -T app php artisan tinker --execute 'exit(App\Models\User::where("role","admin")->exists() ? 0 : 1);' >/dev/null 2>&1; then
  info "An administrator already exists."
else
  [[ -n "$ADMIN_EMAIL" ]] || read -r -p "    Administrator email: " ADMIN_EMAIL
  if [[ -n "${PC_ADMIN_PASSWORD:-}" ]]; then
    PASSWORD="$PC_ADMIN_PASSWORD"
  else
    while true; do
      read -r -s -p "    Choose a password (min. 12 characters, letters and numbers): " PASSWORD; echo
      read -r -s -p "    Repeat the password: " PASSWORD2; echo
      [[ "$PASSWORD" == "$PASSWORD2" ]] && break
      warn "Passwords do not match, try again."
    done
  fi
  # The password is passed on stdin, never as a command-line argument.
  printf '%s' "$PASSWORD" | compose exec -T app php artisan privatecloud:admin --email="$ADMIN_EMAIL" --name=Administrator --password-stdin
  unset PASSWORD PASSWORD2
fi

cat <<DONE

$(printf '\033[1;32m')PrivateCloud is installed.$(printf '\033[0m')

  Dashboard:  https://${DOMAIN}
  Data:       ${DATA_DIR}
  Config:     ${ROOT}/.env  (keep it secret; back it up somewhere safe)

Next steps:
  1. Make sure ${DOMAIN} has an A record pointing to ${PUBLIC_IPV4}.
  2. Open https://${DOMAIN} and sign in.
  3. Connect GitHub in Settings, then create your first project.

Backups are stored on this server. Copy them to another location regularly
(see docs/backups.md) — they do not protect you if the server itself is lost.
DONE
