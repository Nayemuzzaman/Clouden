#!/usr/bin/env bash
#
# Read-only checks of a PrivateCloud production installation:
# configuration, exposed ports, firewall, services, HTTPS and recovery setup.
#
#   sudo ./scripts/validate-install.sh [--quiet]
#
# Exit code 0 when nothing FAILED (warnings are allowed), 1 otherwise.
# Never prints secret values.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
QUIET=0
[[ "${1:-}" == "--quiet" ]] && QUIET=1

PASS=0; WARN=0; FAIL=0
ok()   { PASS=$((PASS + 1)); (( QUIET )) || printf '  \033[32mPASS\033[0m %s\n' "$*"; }
warn() { WARN=$((WARN + 1)); printf '  \033[33mWARN\033[0m %s\n' "$*"; }
bad()  { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }
section() { (( QUIET )) || printf '\n\033[1m%s\033[0m\n' "$*"; }
env_value() {
  local line
  line="$(grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -1 || true)"
  line="${line#*=}"; line="${line%\"}"; line="${line#\"}"
  printf '%s' "$line"
}
in_app() { docker exec privatecloud-app "$@"; }

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo): the checks read .env, the firewall and listening sockets." >&2; exit 1; }

# ------------------------------------------------------------ configuration
section "Configuration"
if [[ ! -f "$ENV_FILE" ]]; then
  bad ".env not found at $ENV_FILE"
else
  mode="$(stat -c '%a %U' "$ENV_FILE")"
  [[ "$mode" == "600 root" ]] && ok ".env is root-only (600)" || bad ".env permissions are '$mode' (expected 600 root): chmod 600 $ENV_FILE"
  [[ "$(env_value APP_ENV)" == "production" ]] && ok "APP_ENV=production" || bad "APP_ENV is '$(env_value APP_ENV)', expected production"
  [[ "$(env_value APP_DEBUG)" != "true" ]] && ok "APP_DEBUG is off" || bad "APP_DEBUG=true exposes secrets in error pages"
  [[ "$(env_value PC_AUTO_HTTPS)" != "off" ]] && ok "Automatic HTTPS is on" || bad "PC_AUTO_HTTPS=off"
  [[ "$(env_value PC_ALLOW_INSECURE_GIT)" != "true" ]] && ok "Insecure git URLs are refused" || bad "PC_ALLOW_INSECURE_GIT=true"
  for key in APP_KEY DB_PASSWORD PC_APPS_DB_ADMIN_PASSWORD; do
    value="$(env_value "$key")"
    (( ${#value} >= 32 )) && ok "$key is set (${#value} characters)" || bad "$key is missing or shorter than 32 characters"
  done
  [[ "$(env_value PC_UID)" != "0" ]] && ok "Control plane runs as uid $(env_value PC_UID)" || bad "PC_UID=0: the control plane runs as root"
  if git -C "$ROOT" ls-files --error-unmatch .env >/dev/null 2>&1; then bad ".env is tracked by git!"; else ok ".env is not tracked by git"; fi
fi
DOMAIN="$(env_value PC_DASHBOARD_DOMAIN)"
DATA_DIR="$(env_value PC_DATA_DIR)"

# ------------------------------------------------------------------- docker
section "Docker"
if ! docker info >/dev/null 2>&1; then
  bad "Docker is not running"
else
  ok "Docker $(docker version --format '{{.Server.Version}}') is running"
  if ss -H -ltnp 2>/dev/null | grep -q '"dockerd"'; then
    bad "dockerd listens on a TCP port: the Docker API must never be reachable over the network ($(ss -H -ltnp | awk '/"dockerd"/{print $4}' | tr '\n' ' '))"
  else
    ok "Docker API is only on the local unix socket"
  fi
  sock="$(stat -c '%a %U:%G' /var/run/docker.sock 2>/dev/null)"
  [[ "$sock" == 660* ]] && ok "Docker socket permissions: $sock" || warn "Docker socket permissions are '$sock' (expected 660 root:docker)"
  grep -q '"max-size"' /etc/docker/daemon.json 2>/dev/null && ok "Container log rotation configured" || warn "No log rotation in /etc/docker/daemon.json"

  for c in privatecloud-app privatecloud-worker privatecloud-tasks privatecloud-scheduler privatecloud-caddy privatecloud-platform-db privatecloud-apps-db privatecloud-redis; do
    state="$(docker inspect --format '{{.State.Status}}{{if .State.Health}}/{{.State.Health.Status}}{{end}} restarts={{.RestartCount}}' "$c" 2>/dev/null)"
    case "$state" in
      running/healthy*|running\ *) ok "$c: $state" ;;
      "") bad "$c does not exist" ;;
      *) bad "$c: $state" ;;
    esac
  done

  # Docker-published ports bypass ufw, so only Caddy may publish any, and only 80/443.
  while read -r name ports; do
    [[ -z "$ports" ]] && continue
    if [[ "$name" == "privatecloud-caddy" ]]; then
      extra="$(tr ',' '\n' <<<"$ports" | grep -- '->' | grep -vE ':(80|443)->(80|443)/(tcp|udp)' || true)"
      [[ -z "$extra" ]] && ok "Caddy publishes only 80/443" || bad "Caddy publishes unexpected ports: $extra"
    elif grep -q -- '->' <<<"$ports"; then
      if grep -qE '(0\.0\.0\.0|\[::\]|:::)[^,]*->' <<<"$ports"; then
        bad "$name publishes ports to the internet (bypasses the firewall): $ports"
      else
        warn "$name publishes ports on a local address: $ports"
      fi
    fi
  done < <(docker ps --format '{{.Names}} {{.Ports}}')
fi

# ------------------------------------------------------------------ network
section "Network exposure"
mapfile -t public_listeners < <(ss -H -ltnu 2>/dev/null | awk '{print $1, $5}' | grep -vE ' (127\.[0-9.]+|\[::1\]|\[?::ffff:127\.[0-9.]+\]?):' | grep -vE ' [^ ]*%lo:')
for port in 5432 6379 2375 2376 2019 8000 9000; do
  if printf '%s\n' "${public_listeners[@]}" | grep -qE ":${port}\$"; then
    bad "Port $port is listening on a public address (PostgreSQL/Redis/Docker/Caddy admin/API must stay internal)"
  fi
done
others="$(printf '%s\n' "${public_listeners[@]}" | awk '{print $2}' | sed -E 's/.*:([0-9]+)$/\1/' | sort -un | grep -vxE '22|80|443|53|68|546' | tr '\n' ' ')"
[[ -z "$others" ]] && ok "Only SSH, HTTP and HTTPS listen on public addresses" || warn "Other ports listen on public addresses: $others(check they are intended)"

if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ok "ufw is active"
  ufw status verbose | grep -q 'deny (incoming)' && ok "ufw denies incoming traffic by default" || bad "ufw does not deny incoming traffic by default"
  for p in 80/tcp 443/tcp; do ufw status | grep -qE "^$p\s+ALLOW" && ok "ufw allows $p" || warn "ufw has no rule for $p"; done
else
  warn "ufw is not active: make sure a firewall (e.g. the Vultr firewall) only allows SSH, 80 and 443"
fi

# ------------------------------------------------------------- application
section "Application"
if docker inspect privatecloud-app >/dev/null 2>&1; then
  out="$(in_app php artisan privatecloud:check-config 2>&1)" && ok "Production configuration check passed" || bad "Configuration check failed: $(tr '\n' ' ' <<<"$out")"
  health="$(in_app php artisan privatecloud:health --plain 2>/dev/null || true)"
  if [[ -n "$health" ]]; then
    while IFS='|' read -r name status detail; do
      case "$status" in
        ok) ok "$name" ;;
        down) bad "$name: $detail" ;;
        *) warn "$name: $status $detail" ;;
      esac
    done <<<"$health"
  else
    bad "Could not read service health (docker exec privatecloud-app php artisan privatecloud:health)"
  fi
  in_app php artisan privatecloud:admin --check-exists >/dev/null 2>&1 && ok "An administrator account exists" || bad "No administrator account: docker compose exec app php artisan privatecloud:admin"
fi

# ---------------------------------------------------------------- web/HTTPS
section "Web and HTTPS"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H 'Host: unknown-host.invalid' http://127.0.0.1/ || true)"
[[ "$code" == "404" ]] && ok "Unknown hostnames get 404" || warn "Unknown hostname returned HTTP $code (expected 404)"
if [[ -n "$DOMAIN" ]]; then
  ip="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
  dns="$(dig +short A "$DOMAIN" @1.1.1.1 2>/dev/null | tail -1)"
  [[ -n "$ip" && "$dns" == "$ip" ]] && ok "DNS: $DOMAIN -> $dns" || warn "DNS: $DOMAIN -> '${dns:-nothing}', this server is '${ip:-unknown}'"
  if curl -fsS --max-time 15 -o /dev/null "https://$DOMAIN/up" 2>/dev/null; then
    ok "https://$DOMAIN serves a valid, trusted certificate"
    hsts="$(curl -sI --max-time 10 "https://$DOMAIN/" | grep -i '^strict-transport-security' || true)"
    [[ -n "$hsts" ]] && ok "HSTS header present" || warn "No HSTS header on the dashboard"
    redirect="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 10 "http://$DOMAIN/")"
    [[ "$redirect" == 30[18]\ https://* ]] && ok "HTTP redirects to HTTPS" || warn "http://$DOMAIN/ answered '$redirect' (expected a redirect to https)"
  else
    warn "https://$DOMAIN is not reachable with a trusted certificate yet (DNS not pointing here, or Let's Encrypt still pending: docker logs privatecloud-caddy | grep -i acme)"
  fi
fi

# ---------------------------------------------------------- recovery/backups
section "Recovery and backups"
systemctl is-enabled privatecloud.service >/dev/null 2>&1 && ok "systemd unit enabled (starts after reboot)" || bad "privatecloud.service is not enabled"
systemctl is-enabled docker >/dev/null 2>&1 && ok "Docker starts at boot" || bad "Docker is not enabled at boot"
[[ -f /etc/cron.d/privatecloud ]] && ok "Nightly platform backup scheduled" || warn "No /etc/cron.d/privatecloud: platform database is not backed up automatically"
if [[ -n "$DATA_DIR" && -d "$DATA_DIR/backups/platform" ]]; then
  latest="$(ls -1t "$DATA_DIR/backups/platform"/platform-*.dump 2>/dev/null | head -1)"
  [[ -n "$latest" ]] && ok "Latest platform backup: $(basename "$latest")" || warn "No platform backup yet (run: sudo $ROOT/scripts/backup-platform.sh)"
fi
warn "Backups are stored on this server only. Copy $DATA_DIR/backups and .env OFF the server (docs/backups.md)."
if [[ -n "$DATA_DIR" ]]; then
  free_gb="$(df -BG --output=avail "$DATA_DIR" | tail -1 | tr -dc '0-9')"
  (( free_gb >= 10 )) && ok "Free disk: ${free_gb} GB" || warn "Only ${free_gb} GB free disk"
fi
[[ -n "$(swapon --show --noheadings 2>/dev/null)" ]] && ok "Swap is enabled" || warn "No swap: builds on small servers may run out of memory"

printf '\n%s passed, %s warnings, %s failed\n' "$PASS" "$WARN" "$FAIL"
(( FAIL == 0 ))
