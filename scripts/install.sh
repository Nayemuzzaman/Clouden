#!/usr/bin/env bash
#
# PrivateCloud installer for a fresh Ubuntu 24.04 LTS server (e.g. a Vultr VPS).
#
#   sudo ./scripts/install.sh --domain cloud.example.com --email you@example.com
#
# Safe to re-run: an existing .env is never overwritten (it is validated
# instead), existing Docker, firewall, swap, cron and systemd configuration is
# kept, and an existing administrator account is left alone.
#
# Secrets are generated on the server, written only to .env (mode 600, root)
# and never printed.
set -euo pipefail
umask 022

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="/var/lib/privatecloud"
DATA_DIR_SET=0
DOMAIN=""
EMAIL=""
ADMIN_EMAIL="${PC_ADMIN_EMAIL:-}"
ADMIN_PASSWORD_FILE=""
SKIP_FIREWALL=0
SKIP_SWAP=0
ALLOW_UNSUPPORTED_OS=0
MIN_DOCKER_MAJOR=25 # Engine API v1.44

usage() {
  cat <<USAGE
Usage: sudo $0 --domain <dashboard domain> --email <letsencrypt email> [options]

Required:
  --domain NAME              Dashboard hostname, e.g. cloud.example.com
  --email ADDRESS            Email for Let's Encrypt expiry notices; also the
                             administrator login unless --admin-email is given

Options:
  --admin-email ADDRESS      Administrator login email
  --admin-password-file FILE Read the administrator password from FILE instead
                             of prompting (for unattended installs; delete the
                             file afterwards). PC_ADMIN_PASSWORD also works.
  --data-dir PATH            Data directory (default: /var/lib/privatecloud)
  --skip-firewall            Do not configure ufw
  --skip-swap                Do not create a swap file on small servers
  --allow-unsupported-os     Continue on an OS other than Ubuntu 24.04 (untested)
  -h, --help                 Show this help
USAGE
}

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
need_value() { [[ $# -ge 2 && -n "$2" && "$2" != --* ]] || fail "$1 needs a value."; }
secret_hex() { openssl rand -hex "${1:-24}"; }
compose() { docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" "$@"; }
env_value() { # value of KEY in a dotenv file, without surrounding quotes
  local line
  line="$(grep -E "^$1=" "$2" | tail -1 || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"; line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) need_value "$@"; DOMAIN="$2"; shift 2 ;;
    --email) need_value "$@"; EMAIL="$2"; shift 2 ;;
    --admin-email) need_value "$@"; ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-password-file) need_value "$@"; ADMIN_PASSWORD_FILE="$2"; shift 2 ;;
    --data-dir) need_value "$@"; DATA_DIR="$2"; DATA_DIR_SET=1; shift 2 ;;
    --skip-firewall) SKIP_FIREWALL=1; shift ;;
    --skip-swap) SKIP_SWAP=1; shift ;;
    --allow-unsupported-os) ALLOW_UNSUPPORTED_OS=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage; fail "Unknown option: $1" ;;
  esac
done

EMAIL_RE='^[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,63}$'
PLACEHOLDER_RE='(^|\.)(example\.(com|org|net)|localhost|local|test|invalid)$'

# ================================================================ checks
step "Checking the server"
[[ $EUID -eq 0 ]] || fail "Run this installer as root: sudo $0 ..."
[[ -n "$DOMAIN" ]] || { usage; fail "--domain is required."; }
DOMAIN="${DOMAIN,,}"
[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail "\"$DOMAIN\" is not a valid domain name."
[[ ! "$DOMAIN" =~ $PLACEHOLDER_RE ]] || fail "\"$DOMAIN\" is a placeholder or local name. Use the real hostname of the dashboard."
[[ -n "$EMAIL" ]] || { usage; fail "--email is required (Let's Encrypt account and expiry notices)."; }
[[ "$EMAIL" =~ $EMAIL_RE ]] || fail "\"$EMAIL\" is not a valid email address."
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL}"
[[ "$ADMIN_EMAIL" =~ $EMAIL_RE ]] || fail "\"$ADMIN_EMAIL\" is not a valid administrator email."
[[ ! "${ADMIN_EMAIL#*@}" =~ $PLACEHOLDER_RE ]] || fail "\"$ADMIN_EMAIL\" is a placeholder address. Use a real administrator email."
[[ "$DATA_DIR" =~ ^/[A-Za-z0-9._/-]+$ && "$DATA_DIR" != "/" && ! "$DATA_DIR" =~ (^|/)\.\.(/|$) ]] \
  || fail "--data-dir must be an absolute path made of letters, digits, '.', '_', '-' and '/'."
if [[ -n "$ADMIN_PASSWORD_FILE" ]]; then
  [[ -f "$ADMIN_PASSWORD_FILE" && -r "$ADMIN_PASSWORD_FILE" ]] || fail "Cannot read --admin-password-file $ADMIN_PASSWORD_FILE."
fi

[[ -r /etc/os-release ]] || fail "Cannot identify the operating system (/etc/os-release is missing)."
# shellcheck disable=SC1091
. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
  if (( ALLOW_UNSUPPORTED_OS )); then
    warn "Detected ${PRETTY_NAME:-an unknown OS}; only Ubuntu 24.04 is tested. Continuing because of --allow-unsupported-os."
  else
    fail "This installer supports Ubuntu 24.04 LTS only (detected: ${PRETTY_NAME:-unknown}). Use a fresh Ubuntu 24.04 server, or pass --allow-unsupported-os at your own risk."
  fi
fi
case "$(uname -m)" in
  x86_64|aarch64) ;;
  *) fail "Unsupported CPU architecture $(uname -m). Use an x86_64 (amd64) or arm64 server." ;;
esac
[[ -d /run/systemd/system ]] || fail "systemd is not running. Install PrivateCloud on a regular VM, not in a container."
command -v apt-get >/dev/null || fail "apt-get not found."

MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
DISK_GB=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
info "Ubuntu ${VERSION_ID:-?} ($(uname -m)), memory ${MEM_MB} MB, free disk ${DISK_GB} GB"
(( DISK_GB >= 5 )) || fail "Only ${DISK_GB} GB of free disk. PrivateCloud needs at least 5 GB (25 GB+ recommended) for images, builds and backups."
(( DISK_GB >= 15 )) || warn "Less than 15 GB of free disk: images and backups will fill it quickly."
(( MEM_MB >= 900 )) || warn "Less than 1 GB of RAM: builds will be slow or fail."

if command -v ss >/dev/null; then
  for port in 80 443; do
    if ss -H -ltn "sport = :$port" | grep -q . && ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'privatecloud-caddy'; then
      fail "Port $port is already in use by another program ($(ss -H -ltnp "sport = :$port" | sed -n 's/.*users:((\"\([^\"]*\)\".*/\1/p' | head -1)). Stop it (e.g. nginx/apache) and run the installer again."
    fi
  done
fi

# ----------------------------------------------------- existing configuration
ENV_FILE="$ROOT/.env"
if [[ -f "$ENV_FILE" ]]; then
  step "Validating the existing $ENV_FILE (it is never overwritten)"
  problems=()
  for key in APP_KEY DB_PASSWORD PC_APPS_DB_ADMIN_PASSWORD PC_DASHBOARD_DOMAIN PC_DATA_DIR; do
    [[ -n "$(env_value "$key" "$ENV_FILE")" ]] || problems+=("$key is missing or empty")
  done
  [[ "$(env_value APP_ENV "$ENV_FILE")" == "production" ]] || problems+=("APP_ENV must be production (this looks like a development .env)")
  [[ "$(env_value APP_DEBUG "$ENV_FILE")" != "true" ]] || problems+=("APP_DEBUG=true")
  [[ "$(env_value PC_AUTO_HTTPS "$ENV_FILE")" != "off" ]] || problems+=("PC_AUTO_HTTPS=off")
  [[ "$(env_value PC_ALLOW_INSECURE_GIT "$ENV_FILE")" != "true" ]] || problems+=("PC_ALLOW_INSECURE_GIT=true")
  [[ "$(env_value SESSION_SECURE_COOKIE "$ENV_FILE")" != "false" ]] || problems+=("SESSION_SECURE_COOKIE=false")
  [[ "$(env_value PC_UID "$ENV_FILE")" != "0" ]] || problems+=("PC_UID=0 (the control plane must not run as root)")
  existing_domain="$(env_value PC_DASHBOARD_DOMAIN "$ENV_FILE")"
  [[ -z "$existing_domain" || "$existing_domain" == "$DOMAIN" ]] || problems+=("PC_DASHBOARD_DOMAIN is $existing_domain, not $DOMAIN")
  if (( ${#problems[@]} )); then
    printf '\033[1;31m    x %s\033[0m\n' "${problems[@]}"
    fail "The existing .env is not a valid production configuration. Fix it by hand, or move it away (it contains APP_KEY: keep a copy if any data was encrypted with it) and run the installer again."
  fi
  existing_data_dir="$(env_value PC_DATA_DIR "$ENV_FILE")"
  if (( DATA_DIR_SET )) && [[ "$existing_data_dir" != "$DATA_DIR" ]]; then
    fail "--data-dir $DATA_DIR differs from PC_DATA_DIR=$existing_data_dir in the existing .env."
  fi
  DATA_DIR="$existing_data_dir"
  chown root:root "$ENV_FILE"
  chmod 600 "$ENV_FILE"
  info "OK (owner root, mode 600)"
fi

# ============================================================== packages
step "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl git openssl ufw dnsutils iproute2 cron >/dev/null
info "done"

step "Installing Docker Engine"
if dpkg-query -W -f='${Status}' docker.io 2>/dev/null | grep -q "install ok installed"; then
  fail "Ubuntu's docker.io package is installed. PrivateCloud needs Docker CE with the compose plugin. Remove it first (apt-get remove docker.io), then run the installer again."
fi
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
fi
docker compose version >/dev/null 2>&1 || fail "The Docker compose plugin is missing. Install docker-compose-plugin from Docker's repository."

if [[ ! -f /etc/docker/daemon.json ]]; then
  # Rotate container logs so they cannot fill the disk; keep containers running
  # while the Docker daemon itself restarts (e.g. during package upgrades).
  install -d -m 0755 /etc/docker
  cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
JSON
  info "Configured Docker log rotation and live-restore"
  systemctl enable docker >/dev/null 2>&1 || true
  systemctl restart docker
else
  grep -q '"max-size"' /etc/docker/daemon.json || warn "/etc/docker/daemon.json exists without log rotation; container logs may grow without limit."
  grep -q '"hosts"' /etc/docker/daemon.json && warn "/etc/docker/daemon.json sets \"hosts\": make sure the Docker API is NOT listening on a network port."
fi
systemctl enable --now docker >/dev/null
docker_major="$(docker version --format '{{.Server.Version}}' | cut -d. -f1)"
[[ "$docker_major" =~ ^[0-9]+$ ]] && (( docker_major >= MIN_DOCKER_MAJOR )) \
  || fail "Docker Engine $(docker version --format '{{.Server.Version}}') is too old; PrivateCloud needs ${MIN_DOCKER_MAJOR}.0 or newer."
info "Docker $(docker version --format '{{.Server.Version}}'), $(docker compose version --short 2>/dev/null || echo compose)"

# ============================================================== firewall
if (( SKIP_FIREWALL == 0 )); then
  step "Configuring the firewall (ufw)"
  # Keep every port sshd listens on open, so enabling the firewall can never lock you out.
  mapfile -t ssh_ports < <( { sshd -T 2>/dev/null | awk '$1=="port"{print $2}'; ss -H -ltnp 2>/dev/null | awk '/"sshd"/{n=split($4,a,":"); print a[n]}'; } | sort -un)
  (( ${#ssh_ports[@]} )) || ssh_ports=(22)
  for port in "${ssh_ports[@]}"; do ufw allow "${port}/tcp" comment 'SSH' >/dev/null; done
  ufw allow 80/tcp comment 'HTTP (Caddy)' >/dev/null
  ufw allow 443/tcp comment 'HTTPS (Caddy)' >/dev/null
  ufw allow 443/udp comment 'HTTP/3 (Caddy)' >/dev/null
  if ! ufw status | grep -q "Status: active"; then
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    ufw --force enable >/dev/null
  fi
  info "Allowed: SSH (${ssh_ports[*]}), HTTP (80), HTTPS (443). Everything else is closed."
  info "PostgreSQL, Redis, the API container and the Docker API publish no ports at all."
else
  warn "Skipping the firewall (--skip-firewall). Only ports ${ssh_ports[*]:-22}, 80 and 443 should be reachable."
fi

# ================================================================== swap
if (( SKIP_SWAP == 0 )) && (( MEM_MB < 4096 )) && [[ -z "$(swapon --show --noheadings)" ]] && [[ ! -e /swapfile ]] && (( DISK_GB >= 12 )); then
  step "Creating a 2 GB swap file (helps Docker builds on small servers)"
  fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  info "done"
fi

# ======================================================== data directory
step "Preparing $DATA_DIR"
install -d -m 0750 -o 33 -g 33 "$DATA_DIR"
for sub in caddy caddy/sites caddy/logs backups builds; do
  install -d -m 0750 -o 33 -g 33 "$DATA_DIR/$sub"
done
chmod 0755 "$DATA_DIR/caddy" "$DATA_DIR/caddy/sites" "$DATA_DIR/caddy/logs" # read by the Caddy container
install -d -m 0700 -o root -g root "$DATA_DIR/backups/platform"            # platform dumps + .env copies
info "owned by uid 33 (the control plane's user); platform backups root-only"

# ================================================================== .env
PUBLIC_IPV4="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
[[ "$PUBLIC_IPV4" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || PUBLIC_IPV4="$(hostname -I | awk '{print $1}')"
PUBLIC_IPV6="$(curl -6 -fsS --max-time 5 https://api64.ipify.org 2>/dev/null || true)"
[[ "$PUBLIC_IPV6" == *:* ]] || PUBLIC_IPV6=""
DOCKER_GID="$(stat -c %g /var/run/docker.sock)"
HOST_NAME="$(hostname)"

if [[ ! -f "$ENV_FILE" ]]; then
  step "Writing configuration"
  # Generated first and checked: a failing command inside the heredoc below would
  # otherwise silently produce empty secrets.
  command -v openssl >/dev/null || fail "openssl is required to generate secrets."
  APP_KEY_VALUE="$(openssl rand -base64 32)"
  DB_PASSWORD_VALUE="$(secret_hex 24)"
  APPS_DB_PASSWORD_VALUE="$(secret_hex 24)"
  [[ ${#APP_KEY_VALUE} -eq 44 && ${#DB_PASSWORD_VALUE} -eq 48 && ${#APPS_DB_PASSWORD_VALUE} -eq 48 ]] \
    || fail "Could not generate random secrets with openssl."
  tmp="$(mktemp "$ROOT/.env.XXXXXX")"
  chmod 600 "$tmp"
  cat > "$tmp" <<ENV
# Generated by scripts/install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ).
# Keep this file private and back it up OFF the server: APP_KEY decrypts every
# stored secret. Never change APP_KEY after installation.
PC_NAME=PrivateCloud
PC_DASHBOARD_DOMAIN=${DOMAIN}
APP_URL=https://${DOMAIN}
PC_ACME_EMAIL=${EMAIL}
PC_PUBLIC_IPV4=${PUBLIC_IPV4}
PC_PUBLIC_IPV6=${PUBLIC_IPV6}
PC_HOST_HOSTNAME=${HOST_NAME}
PC_DATA_DIR=${DATA_DIR}

APP_NAME=PrivateCloud
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:${APP_KEY_VALUE}
LOG_LEVEL=info
SESSION_LIFETIME=480
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

DB_PASSWORD=${DB_PASSWORD_VALUE}
PC_APPS_DB_ADMIN_PASSWORD=${APPS_DB_PASSWORD_VALUE}

PC_UID=33
PC_GID=33
DOCKER_GID=${DOCKER_GID}
PC_AUTO_HTTPS=on
ENV
  mv "$tmp" "$ENV_FILE"
  unset APP_KEY_VALUE DB_PASSWORD_VALUE APPS_DB_PASSWORD_VALUE
  chown root:root "$ENV_FILE"
  info "Created $ENV_FILE with random secrets (owner root, mode 600)"
else
  current_gid="$(env_value DOCKER_GID "$ENV_FILE")"
  [[ "$current_gid" == "$DOCKER_GID" ]] || warn "DOCKER_GID in .env is $current_gid but the Docker socket's group is $DOCKER_GID. Update .env, or the control plane cannot reach Docker."
fi

# =================================================================== DNS
step "Checking DNS for ${DOMAIN}"
RESOLVED_A="$(dig +short A "$DOMAIN" @1.1.1.1 2>/dev/null | grep -E '^[0-9.]+$' | sort | tr '\n' ' ' | sed 's/ $//' || true)"
RESOLVED_AAAA="$(dig +short AAAA "$DOMAIN" @1.1.1.1 2>/dev/null | grep ':' | sort | tr '\n' ' ' | sed 's/ $//' || true)"
if [[ "$RESOLVED_A" == "$PUBLIC_IPV4" ]]; then
  info "A record: ${DOMAIN} -> ${PUBLIC_IPV4} (this server)"
else
  warn "A record of ${DOMAIN} is '${RESOLVED_A:-missing}', but this server is ${PUBLIC_IPV4}."
  warn "Create the record ${DOMAIN} A ${PUBLIC_IPV4}. HTTPS starts automatically once DNS is correct."
fi
if [[ -n "$RESOLVED_AAAA" && "$RESOLVED_AAAA" != "$PUBLIC_IPV6" ]]; then
  warn "${DOMAIN} has an AAAA record (${RESOLVED_AAAA}) that does not point to this server${PUBLIC_IPV6:+ (${PUBLIC_IPV6})}."
  warn "Let's Encrypt prefers IPv6: fix or remove the AAAA record, or certificate issuance will fail."
fi

# ================================================================= start
step "Building PrivateCloud (several minutes the first time)"
compose build --pull
step "Starting services"
compose up -d --remove-orphans
info "Waiting for the API to become healthy…"
status="starting"
for _ in $(seq 1 90); do
  status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' privatecloud-app 2>/dev/null || echo starting)"
  [[ "$status" == "healthy" ]] && break
  sleep 2
done
if [[ "$status" != "healthy" ]]; then
  docker logs --tail 40 privatecloud-app 2>&1 | sed 's/^/    | /' >&2 || true
  fail "The API did not become healthy (status: $status). The log above shows why; full log: docker logs privatecloud-app"
fi
info "All services are running"

step "Installing the systemd unit"
sed "s#__ROOT__#${ROOT}#g" "$ROOT/infrastructure/systemd/privatecloud.service" > /etc/systemd/system/privatecloud.service
systemctl daemon-reload
systemctl enable privatecloud.service >/dev/null
info "PrivateCloud starts automatically after a reboot"

step "Scheduling the nightly platform backup"
if [[ -f /etc/cron.d/privatecloud ]]; then
  info "/etc/cron.d/privatecloud already exists: kept unchanged"
else
  cat > /etc/cron.d/privatecloud <<CRON
# PrivateCloud: nightly backup of the platform database and .env (see docs/backups.md).
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 3 * * * root ${ROOT}/scripts/backup-platform.sh >> /var/log/privatecloud-backup.log 2>&1
CRON
  chmod 644 /etc/cron.d/privatecloud
  info "03:15 daily -> ${DATA_DIR}/backups/platform (log: /var/log/privatecloud-backup.log)"
fi

# ================================================================= admin
step "Administrator account"
if compose exec -T app php artisan privatecloud:admin --check-exists >/dev/null 2>&1; then
  info "An administrator already exists (reset its password with: docker compose exec app php artisan privatecloud:admin --email=<email> --reset)."
else
  read_password() {
    local p1 p2
    while true; do
      read -r -s -p "    Choose a password for ${ADMIN_EMAIL} (min. 12 characters, letters and numbers): " p1 </dev/tty; echo >/dev/tty
      read -r -s -p "    Repeat the password: " p2 </dev/tty; echo >/dev/tty
      [[ "$p1" == "$p2" ]] && { PASSWORD="$p1"; return; }
      warn "Passwords do not match, try again."
    done
  }
  interactive=0
  if [[ -n "$ADMIN_PASSWORD_FILE" ]]; then
    PASSWORD="$(head -n1 "$ADMIN_PASSWORD_FILE")"
  elif [[ -n "${PC_ADMIN_PASSWORD:-}" ]]; then
    PASSWORD="$PC_ADMIN_PASSWORD"
  elif [[ -r /dev/tty ]] && { : </dev/tty; } 2>/dev/null; then
    interactive=1
    read_password
  else
    fail "No terminal to ask for the administrator password. Re-run interactively, or pass --admin-password-file."
  fi
  # The password goes through stdin, never the command line or the environment of the container.
  until printf '%s' "$PASSWORD" | compose exec -T app php artisan privatecloud:admin --email="$ADMIN_EMAIL" --name=Administrator --password-stdin; do
    (( interactive )) || { unset PASSWORD; fail "The administrator account was not created (see the message above)."; }
    read_password
  done
  unset PASSWORD
  [[ -z "$ADMIN_PASSWORD_FILE" ]] || warn "Delete $ADMIN_PASSWORD_FILE now (e.g. shred -u $ADMIN_PASSWORD_FILE)."
fi

# ============================================================== validate
step "Validating the installation"
"$ROOT/scripts/validate-install.sh" --quiet || warn "Some checks did not pass (see above). Re-run later with: sudo $ROOT/scripts/validate-install.sh"

cat <<DONE

$(printf '\033[1;32m')PrivateCloud is installed.$(printf '\033[0m')

  Dashboard:      https://${DOMAIN}
  Administrator:  ${ADMIN_EMAIL}
  Data:           ${DATA_DIR}
  Configuration:  ${ENV_FILE} (root only; contains every secret, never printed)

Next steps:
  1. Make sure ${DOMAIN} has an A record pointing to ${PUBLIC_IPV4}; HTTPS is
     issued automatically once it does (check: sudo ${ROOT}/scripts/validate-install.sh).
  2. Copy ${ENV_FILE} to a safe place OFF this server (password manager or
     encrypted storage). Without its APP_KEY, stored secrets cannot be decrypted.
  3. Open https://${DOMAIN}, sign in, connect GitHub in Settings and create a project.
  4. Set up off-server backups (docs/backups.md). Backups stored only on this
     server are lost together with the server.
DONE
