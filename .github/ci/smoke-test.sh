#!/usr/bin/env bash
#
# Production-mode smoke test of the whole stack, used by CI (.github/workflows/ci.yml).
#
# Starts docker-compose.yml (production settings: uid 33, all capabilities
# dropped, configuration guard) with .github/ci/compose.smoke.yml, then checks
# hardening, sign-in, cookies/CORS, and a real deployment of
# examples/simple-node-app cloned from GitHub, including recovery after the
# deployment worker is killed mid-deployment.
#
# Requires: the image privatecloud/app:local already built, Docker, curl,
# python3. Environment: REPO_URL (https clone URL) and BRANCH to deploy from.
# Never run it on a server that hosts a real installation: it writes .env and
# uses the fixed privatecloud-* container names.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
: "${REPO_URL:?REPO_URL must be set}"
: "${BRANCH:?BRANCH must be set}"
PROJECT="pcsmoke"
DATA_DIR="${RUNNER_TEMP:-/tmp}/privatecloud-smoke-data"
JAR="$(mktemp)"
API="https://localhost:8443/api/v1"
ADMIN_EMAIL="ci-admin@privatecloud-ci.dev"
ADMIN_PASSWORD="ci-$(openssl rand -hex 12)-Pw1"

compose() { docker compose -p "$PROJECT" -f docker-compose.yml -f .github/ci/compose.smoke.yml "$@"; }
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '    \033[32m✓\033[0m %s\n' "$*"; }
die() { printf '    \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
xsrf() { python3 -c "import urllib.parse,sys; [print(urllib.parse.unquote(l.split()[-1])) for l in open('$JAR') if 'XSRF-TOKEN' in l]" | tail -1; }
api() { # api METHOD PATH [JSON]
  curl -sk -b "$JAR" -c "$JAR" -X "$1" -H "X-XSRF-TOKEN: $(xsrf)" -H 'Content-Type: application/json' \
    -H 'Accept: application/json' ${3:+-d "$3"} "$API$2"
}
deployment_status() { api GET "/projects/smoke/deployments/$1" | json "d['data']['status']"; }
wait_for_deployment() { # id, timeout seconds → final status
  local id="$1" deadline=$((SECONDS + $2)) status
  while (( SECONDS < deadline )); do
    status="$(deployment_status "$id")"
    case "$status" in success|failed|cancelled) echo "$status"; return ;; esac
    sleep 3
  done
  echo timeout
}

# -------------------------------------------------------------- configuration
step "Writing a production .env (random secrets)"
[[ ! -f .env ]] || die ".env already exists; refusing to overwrite it"
SUDO=""
if [[ $EUID -ne 0 ]] && command -v sudo >/dev/null && sudo -n true 2>/dev/null; then SUDO=sudo; fi
DOCKER_GID="${DOCKER_GID:-$(stat -c %g /var/run/docker.sock 2>/dev/null || echo 0)}"
$SUDO rm -rf "$DATA_DIR"
for d in "" caddy caddy/sites caddy/logs backups builds; do
  if [[ -n "$SUDO" || $EUID -eq 0 ]]; then $SUDO install -d -o 33 -g 33 -m 0755 "$DATA_DIR/$d"; else mkdir -p "$DATA_DIR/$d"; fi
done
umask 077
cat > .env <<ENV
PC_NAME=PrivateCloud
PC_DASHBOARD_DOMAIN=cloud.privatecloud-ci.dev
APP_URL=https://cloud.privatecloud-ci.dev
PC_ACME_EMAIL=ci@privatecloud-ci.dev
PC_PUBLIC_IPV4=203.0.113.10
PC_HOST_HOSTNAME=ci-runner
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
DB_PASSWORD=$(openssl rand -hex 24)
PC_APPS_DB_ADMIN_PASSWORD=$(openssl rand -hex 24)
PC_UID=33
PC_GID=33
DOCKER_GID=${DOCKER_GID}
PC_AUTO_HTTPS=on
ENV
umask 022
ok "data dir $DATA_DIR, docker group $DOCKER_GID"

# ---------------------------------------------------------------------- start
step "Starting the production stack"
compose up -d --no-build
status=starting
for _ in $(seq 1 90); do
  status="$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null || echo starting)"
  [[ "$status" == healthy ]] && break
  sleep 2
done
[[ "$status" == healthy ]] || { docker logs --tail 60 privatecloud-app; die "API not healthy ($status)"; }
ok "API healthy"

step "Hardening"
for c in privatecloud-app privatecloud-worker privatecloud-tasks privatecloud-scheduler; do
  read -r uid capeff capbnd nnp < <(docker exec "$c" sh -c 'echo "$(id -u) $(awk "/^CapEff/{print \$2}" /proc/self/status) $(awk "/^CapBnd/{print \$2}" /proc/self/status) $(awk "/^NoNewPrivs/{print \$2}" /proc/self/status)"')
  [[ "$uid" == 33 && "$capeff" == 0000000000000000 && "$capbnd" == 0000000000000000 && "$nnp" == 1 ]] \
    || die "$c: uid=$uid CapEff=$capeff CapBnd=$capbnd NoNewPrivs=$nnp"
  ok "$c: uid 33, no capabilities, no-new-privileges"
done
published="$( { docker ps --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}} {{.Ports}}'
  docker ps --filter label=privatecloud.managed=true --format '{{.Names}} {{.Ports}}'; } | grep -- '->' | grep -v '^privatecloud-caddy ' || true)"
[[ -z "$published" ]] || die "containers other than Caddy publish ports: $published"
ok "only Caddy publishes ports"
docker exec privatecloud-app php artisan privatecloud:check-config >/dev/null || die "configuration check failed"
ok "production configuration check passed"
docker exec privatecloud-app php artisan privatecloud:health --plain | sed 's/^/      /'
docker exec privatecloud-app php artisan privatecloud:health >/dev/null || die "a service is down"

step "Administrator"
if printf '%s' "$ADMIN_PASSWORD" | docker exec -i privatecloud-app php artisan privatecloud:admin --email=admin@example.com --password-stdin >/dev/null 2>&1; then
  die "placeholder administrator accepted"
fi
ok "placeholder email refused"
printf '%s' "$ADMIN_PASSWORD" | docker exec -i privatecloud-app php artisan privatecloud:admin --email="$ADMIN_EMAIL" --password-stdin >/dev/null
ok "administrator created (password via stdin)"

# ------------------------------------------------------------------------ web
step "HTTPS, cookies, CORS, routing"
[[ "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/up)" == 200 ]] || die "/up is not 200"
headers="$(curl -sk -c "$JAR" -D - -o /dev/null -H 'Origin: https://evil.example' "$API/auth/csrf")"
grep -qi '^strict-transport-security' <<<"$headers" || die "no HSTS"
grep -qi '^content-security-policy' <<<"$headers" || die "no CSP"
! grep -qi '^access-control-allow' <<<"$headers" || die "CORS headers sent to another origin"
session_cookie="$(grep -i '^set-cookie: privatecloud-session' <<<"$headers")"
for flag in secure httponly 'samesite=strict'; do grep -qi "$flag" <<<"$session_cookie" || die "session cookie lacks $flag"; done
ok "HSTS, CSP, no CORS, session cookie Secure/HttpOnly/SameSite=Strict"
[[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: unknown.invalid' http://127.0.0.1:8081/)" == 404 ]] || die "unknown host is not 404"
ok "unknown hostnames get 404"

step "Login rate limit cannot be bypassed with X-Forwarded-For"
codes=""
for i in 1 2 3 4 5 6; do
  codes+="$(curl -sk -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -H "X-XSRF-TOKEN: $(xsrf)" -H 'Content-Type: application/json' \
    -H 'Accept: application/json' -H "X-Forwarded-For: 198.51.100.$i" -d '{"email":"nobody@privatecloud-ci.dev","password":"wrong"}' "$API/auth/login") "
done
[[ "$codes" == *429* ]] || die "no 429 after 6 failed logins with spoofed addresses: $codes"
ok "$codes"

step "Sign in"
[[ "$(api POST /auth/login "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}" | json "d['user']['email']")" == "$ADMIN_EMAIL" ]] || die "login failed"
ok "signed in"

# ----------------------------------------------------------------- deployment
step "Deploy examples/simple-node-app from $REPO_URL ($BRANCH)"
payload="$(python3 - <<'PY'
import json, os
print(json.dumps({
    "name": "Smoke", "source_type": "git", "repository_url": os.environ["REPO_URL"], "branch": os.environ["BRANCH"],
    "build_context": "examples/simple-node-app", "dockerfile_path": "examples/simple-node-app/Dockerfile",
    "port": 3000, "health_check_path": "/health", "database": True,
    "volumes": [{"name": "data", "mount_path": "/data"}],
    "environment": [{"key": "SMOKE_SECRET_TOKEN", "value": "smoke-secret-value-123", "is_secret": True}],
}))
PY
)"
created="$(api POST /projects "$payload")"
[[ "$(json "d['data']['slug']" <<<"$created")" == smoke ]] || die "project not created: $created"
ok "project created (database, volume, secret variable)"

id="$(api POST /projects/smoke/deployments | json "d['data']['id']")"
result="$(wait_for_deployment "$id" 600)"
if [[ "$result" != success ]]; then
  api GET "/projects/smoke/deployments/$id/logs" | python3 -c "import json,sys; [print('      ', l.get('line') or l.get('message')) for l in json.load(sys.stdin).get('data', [])[-40:]]" || true
  die "deployment #1 finished as $result"
fi
container="$(api GET "/projects/smoke/deployments/$id" | json "d['data']['container_name']")"
docker exec privatecloud-worker php -r "exit(@file_get_contents('http://$container:3000/health') === false ? 1 : 0);" || die "application does not answer"
ok "deployment #1 successful, $container answers /health"
labels="$(docker inspect --format '{{index .Config.Labels "privatecloud.instance"}} {{.HostConfig.SecurityOpt}}' "$container")"
[[ "$labels" == *no-new-privileges* && -n "${labels%% *}" ]] || die "container labels/hardening missing: $labels"
ok "application container labelled with the installation id, no-new-privileges"
env_body="$(api GET /projects/smoke/environment)"
log_body="$(api GET "/projects/smoke/deployments/$id/logs")"
[[ "$env_body" == *SMOKE_SECRET_TOKEN* ]] || die "environment list not returned: $env_body"
[[ "$env_body" != *smoke-secret-value-123* ]] || die "secret value returned by the API"
[[ "$log_body" != *smoke-secret-value-123* ]] || die "secret value in deployment logs"
ok "secret value never returned by the API"

step "Worker killed during a deployment"
id2="$(api POST /projects/smoke/deployments | json "d['data']['id']")"
for _ in $(seq 1 120); do
  s="$(deployment_status "$id2")"
  [[ "$s" == cloning || "$s" == building ]] && break
  sleep 0.5
done
docker kill privatecloud-worker >/dev/null
docker start privatecloud-worker >/dev/null
result="$(wait_for_deployment "$id2" 120)"
[[ "$result" == failed ]] || die "interrupted deployment finished as $result"
[[ "$(api GET "/projects/smoke/deployments/$id2")" == *interrupted* ]] || die "no 'interrupted' failure reason"
docker exec privatecloud-worker php -r "exit(@file_get_contents('http://$container:3000/health') === false ? 1 : 0);" || die "live version stopped answering"
ok "marked interrupted; the live version kept answering"
id3="$(api POST /projects/smoke/deployments | json "d['data']['id']")"
result="$(wait_for_deployment "$id3" 600)"
[[ "$result" == success ]] || die "deployment after recovery finished as $result"
ok "the next deployment succeeded (project lock released)"
container="$(api GET "/projects/smoke/deployments/$id3" | json "d['data']['container_name']")"

step "Backups"
api POST /backups '{"project":"smoke"}' >/dev/null
for _ in $(seq 1 60); do
  states="$(api GET /backups | python3 -c "import json,sys; print(' '.join(sorted(b['status'] for b in json.load(sys.stdin)['data'] if b['trigger'] == 'manual')))")"
  [[ "$states" == "success success" ]] && break
  [[ "$states" == *failed* ]] && die "backup failed: $(api GET /backups)"
  sleep 2
done
[[ "$states" == "success success" ]] || die "backups did not complete: $states"
ok "database and volume backups completed and verified"

step "Control plane re-created (like a reboot or update)"
compose down >/dev/null 2>&1
compose up -d --no-build >/dev/null 2>&1
for _ in $(seq 1 90); do
  [[ "$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null)" == healthy ]] && break
  sleep 2
done
reachable=0
for _ in $(seq 1 60); do # the reconciler re-attaches project networks every minute
  if docker exec privatecloud-worker php -r "exit(@file_get_contents('http://$container:3000/health') === false ? 1 : 0);" 2>/dev/null; then
    reachable=1; break
  fi
  sleep 3
done
(( reachable )) || die "$container is not reachable from the re-created worker (project network not repaired)"
docker exec privatecloud-app php artisan privatecloud:health >/dev/null || die "services unhealthy after re-create"
ok "services healthy and the application reachable again"

printf '\n\033[1;32mSmoke test passed.\033[0m\n'
