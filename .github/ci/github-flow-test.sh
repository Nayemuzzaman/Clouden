#!/usr/bin/env bash
#
# End-to-end test of the GitHub production branch → live workflow, against
# REAL components:
#
#   developer `git push` (real git, real commits)
#     → local GitHub simulation (.github/ci/fake-github: real bare repositories,
#       GitHub-compatible API, codeload archives, signed push webhooks)
#     → PrivateCloud production stack (docker-compose.yml, uid 33, all
#       capabilities dropped, configuration guard)
#     → real `docker build`, real containers, real health checks
#     → real Caddy routing over HTTPS (Caddy's internal CA, no Let's Encrypt)
#
# The GitHub side is a SIMULATION: nothing here talks to github.com, Vultr or
# Let's Encrypt. Scenarios: the acceptance flow for a public and a private
# repository, zero-downtime request loops, broken builds, failed health checks,
# rollback and the rollback hold, duplicate/forged/out-of-order deliveries,
# other branches and tags, rapid pushes, manual/auto races, revoked and
# under-privileged tokens, rate limits, visibility changes, a deleted branch,
# database and volume persistence, credential leakage, and recovery after
# worker and control-plane restarts.
#
# Requires: the image privatecloud/app:local, Docker, curl, git, python3,
# openssl. Uses the fixed privatecloud-* container names: never run it on a
# machine with a real installation, and stop a local development stack first.
#
#   docker build -t privatecloud/app:local -f docker/app/Dockerfile .
#   .github/ci/github-flow-test.sh
#
# Environment: E2E_DATA_DIR (default $RUNNER_TEMP or /tmp), E2E_KEEP=1 to leave
# the stack running afterwards for inspection.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT=pce2e
WORK="$(mktemp -d "${TMPDIR:-/tmp}/pc-e2e.XXXXXX")"
DATA_DIR="${E2E_DATA_DIR:-${RUNNER_TEMP:-/tmp}/privatecloud-e2e-data}"
STACK="$WORK/stack"
JAR="$WORK/cookies"
API="https://localhost:8443/api/v1"
FAKE="http://127.0.0.1:18080"
DASHBOARD_DOMAIN="cloud.privatecloud-e2e.dev"
ADMIN_EMAIL="e2e-admin@privatecloud-e2e.dev"
ADMIN_PASSWORD="e2e-$(openssl rand -hex 12)-Pw1"
E2E_DEVELOPER_PASSWORD="dev-$(openssl rand -hex 12)"
export E2E_DEVELOPER_PASSWORD
TOKEN="github_pat_11E2E$(openssl rand -hex 24)"          # the fine-grained token PrivateCloud uses
APP_SECRET="app-secret-$(openssl rand -hex 12)"          # an application secret variable
RUN_ID="$(openssl rand -hex 6)"                          # makes build steps unique to this run (no build-cache hits)
RESULTS="$WORK/results"
: > "$RESULTS"

# ------------------------------------------------------------------- helpers
compose() { docker compose -p "$PROJECT" --project-directory "$STACK" -f "$STACK/docker-compose.yml" -f "$STACK/compose.github-e2e.yml" "$@"; }
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '    \033[32m✓\033[0m %s\n' "$*"; }
info() { printf '      %s\n' "$*"; }
die() { printf '    \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
pass() { echo "PASS|$1" >> "$RESULTS"; ok "$1"; }
failed() { echo "FAIL|$1" >> "$RESULTS"; printf '    \033[31m✗ %s\033[0m\n' "$1"; }
check() { local name="$1"; shift; if "$@"; then pass "$name"; else failed "$name"; fi; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
xsrf() { python3 -c "import urllib.parse; [print(urllib.parse.unquote(l.split()[-1])) for l in open('$JAR') if 'XSRF-TOKEN' in l]" | tail -1; }
api() { # METHOD PATH [JSON] → body; status in $WORK/status
  curl -sk -b "$JAR" -c "$JAR" -X "$1" -H "X-XSRF-TOKEN: $(xsrf)" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -o "$WORK/body" -w '%{http_code}' ${3:+-d "$3"} "$API$2" > "$WORK/status" || true
  cat "$WORK/body"
}
status() { cat "$WORK/status"; }
fake() { curl -sf -X "$1" -H 'Content-Type: application/json' ${3:+-d "$3"} "$FAKE$2"; }
site() { curl -sk --max-time 10 --resolve "$1:8443:127.0.0.1" "https://$1:8443${2:-/}" || true; }

# The developer's machine: real git working copies pushing to the simulated GitHub.
dev_git() { git -C "$WORK/dev/$1" -c user.name="E2E Developer" -c user.email="dev@privatecloud-e2e.dev" "${@:2}"; }
write_app() { # repo version mode(ok|broken|unhealthy|slow)
  local dir="$WORK/dev/$1" version="$2" mode="${3:-ok}"
  mkdir -p "$dir/site"
  printf 'version %s\n' "$version" > "$dir/site/index.html"
  printf '%s\n' "$version" > "$dir/site/version.txt"
  printf 'ok\n' > "$dir/site/health"
  cat > "$dir/start.sh" <<'SH'
#!/bin/sh
set -e
mkdir -p /data
echo "$(cat /www/version.txt)" >> /data/history.log
cp /data/history.log /www/history.txt
printf '%s\n' "${APP_GREETING:-unset}" > /www/greeting.txt
SH
  [[ "$mode" == unhealthy ]] && echo 'rm -f /www/health' >> "$dir/start.sh"
  echo 'exec httpd -f -p "${PORT:-3000}" -h /www' >> "$dir/start.sh"
  { echo 'FROM busybox:1.36'
    [[ "$mode" == broken ]] && echo 'RUN echo "simulated build error" && false'
    [[ "$mode" == slow ]] && echo "RUN echo 'slow build step for $version (run $RUN_ID)' && sleep 30"
    echo 'COPY site/ /www/'
    echo 'COPY start.sh /start.sh'
    echo 'CMD ["sh", "/start.sh"]'; } > "$dir/Dockerfile"
}
commit() { # repo version message [mode] → sha
  write_app "$1" "$2" "${4:-ok}"
  dev_git "$1" add -A >/dev/null
  dev_git "$1" commit -qm "$3" >/dev/null
  dev_git "$1" rev-parse HEAD
}
push() { dev_git "$1" push -q origin "${2:-main}" 2>&1 | grep -v '^remote:' || true; }

deployments_json() { api GET "/projects/$1/deployments?per_page=100"; }
# Wait for the newest deployment of a commit to finish; prints its status.
wait_sha() { # slug sha [timeout]
  local deadline=$((SECONDS + ${3:-300})) s
  while (( SECONDS < deadline )); do
    s="$(deployments_json "$1" | python3 -c "
import json,sys
ds=[d for d in json.load(sys.stdin)['data'] if (d.get('commit') or {}).get('sha')=='$2']
print(ds[0]['status'] if ds else 'none')")"
    case "$s" in success|failed|cancelled|superseded) echo "$s"; return ;; esac
    sleep 2
  done
  echo "timeout($s)"
}
wait_idle() { # slug: until no deployment is active
  local deadline=$((SECONDS + ${2:-300}))
  while (( SECONDS < deadline )); do
    [[ "$(deployments_json "$1" | json "sum(1 for x in d['data'] if x['is_active'])")" == 0 ]] && return 0
    sleep 2
  done
  return 1
}
count_for_sha() { deployments_json "$1" | json "sum(1 for x in d['data'] if (x.get('commit') or {}).get('sha')=='$2')"; }
deployment_id_for() { deployments_json "$1" | json "[x['id'] for x in d['data'] if (x.get('commit') or {}).get('sha')=='$2' and x['status']=='$3'][0]"; }
project_field() { api GET "/projects/$1" | json "d['data']$2"; }
live_sha() { project_field "$1" "['current_deployment']['commit']['sha']"; }
sync_state() { project_field "$1" "['sync']['state']"; }

# Request loop against the live site while something happens; zero failures expected.
load_start() { # domain
  rm -f "$WORK/load.codes"; touch "$WORK/load.on"
  ( while [[ -f "$WORK/load.on" ]]; do
      curl -sk -o /dev/null -w '%{http_code}\n' --max-time 5 --resolve "$1:8443:127.0.0.1" "https://$1:8443/" >> "$WORK/load.codes" 2>/dev/null || true
    done ) &
  LOAD_PID=$!
}
load_stop() { # → "total failures"
  rm -f "$WORK/load.on"; wait "$LOAD_PID" 2>/dev/null || true
  python3 -c "
codes=[l.strip() for l in open('$WORK/load.codes') if l.strip()]
bad=[c for c in codes if c!='200']
print(len(codes), len(bad), ','.join(sorted(set(bad))))"
}
load_ok() { # name "total failures kinds"
  local total failures kinds
  read -r total failures kinds <<<"$2"
  info "$1: $total requests, $failures failed ${kinds:+($kinds)}"
  [[ "$total" -ge 100 && "$failures" -eq 0 ]]
}

cleanup() {
  local code=$?
  rm -f "$WORK/load.on"
  if [[ "${E2E_KEEP:-0}" != 1 ]]; then
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    docker ps -aq --filter label=privatecloud.managed=true --filter "label=privatecloud.instance=${INSTANCE_ID:-none}" | xargs -r docker rm -f >/dev/null 2>&1 || true
    docker images -q --filter label=privatecloud.managed=true --filter "label=privatecloud.instance=${INSTANCE_ID:-none}" | xargs -r docker rmi -f >/dev/null 2>&1 || true
    docker network ls -q --filter label=privatecloud.managed=true --filter "label=privatecloud.instance=${INSTANCE_ID:-none}" | xargs -r docker network rm >/dev/null 2>&1 || true
    docker volume ls -q --filter label=privatecloud.managed=true --filter "label=privatecloud.instance=${INSTANCE_ID:-none}" | xargs -r docker volume rm >/dev/null 2>&1 || true
    rm -rf "$WORK"
    ${SUDO:-} rm -rf "$DATA_DIR" 2>/dev/null || true
  else
    echo "Stack left running (E2E_KEEP=1). Work directory: $WORK"
  fi
  exit "$code"
}
trap cleanup EXIT

# ---------------------------------------------------------------- the stack
step "Preparing a production stack with a local GitHub simulation"
if docker ps -a --format '{{.Names}}' | grep -qE '^privatecloud-(app|worker|tasks|scheduler|caddy|platform-db|apps-db|redis)$'; then
  die "privatecloud-* containers are running (a local installation?). Stop them first; this test uses the same names."
fi
docker image inspect privatecloud/app:local >/dev/null 2>&1 || die "build privatecloud/app:local first"
docker build -q -t privatecloud/fake-github:e2e "$ROOT/.github/ci/fake-github" >/dev/null
docker pull -q busybox:1.36 >/dev/null
mkdir -p "$STACK/infrastructure/caddy" "$WORK/dev"
cp "$ROOT/docker-compose.yml" "$ROOT/.github/ci/compose.github-e2e.yml" "$STACK/"
cp "$ROOT/infrastructure/caddy/Caddyfile" "$STACK/infrastructure/caddy/"
SUDO=""
if [[ $EUID -ne 0 ]] && command -v sudo >/dev/null && sudo -n true 2>/dev/null; then SUDO=sudo; fi
$SUDO rm -rf "$DATA_DIR"
for d in "" caddy caddy/sites caddy/logs backups builds; do
  if [[ -n "$SUDO" || $EUID -eq 0 ]]; then $SUDO install -d -o 33 -g 33 -m 0755 "$DATA_DIR/$d"; else mkdir -p "$DATA_DIR/$d"; fi
done
DOCKER_GID="${DOCKER_GID:-$(stat -c %g /var/run/docker.sock 2>/dev/null || echo 0)}"
umask 077
cat > "$STACK/.env" <<ENV
PC_NAME=PrivateCloud
PC_DASHBOARD_DOMAIN=${DASHBOARD_DOMAIN}
APP_URL=https://${DASHBOARD_DOMAIN}
PC_ACME_EMAIL=e2e@privatecloud-e2e.dev
PC_PUBLIC_IPV4=203.0.113.10
PC_HOST_HOSTNAME=e2e-runner
PC_DATA_DIR=${DATA_DIR}
APP_NAME=PrivateCloud
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
LOG_LEVEL=info
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict
SESSION_ENCRYPT=true
DB_PASSWORD=$(openssl rand -hex 24)
PC_APPS_DB_ADMIN_PASSWORD=$(openssl rand -hex 24)
PC_UID=33
PC_GID=33
DOCKER_GID=${DOCKER_GID}
PC_AUTO_HTTPS=on
PC_GITHUB_API_URL=http://fake-github:8080/api
PC_DRAIN_SECONDS=5
ENV
umask 022
compose up -d --no-build >/dev/null 2>&1 || { compose up -d --no-build; die "stack did not start"; }
for _ in $(seq 1 90); do
  [[ "$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null)" == healthy ]] && break
  sleep 2
done
[[ "$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null)" == healthy ]] || { docker logs --tail 50 privatecloud-app; die "API not healthy"; }
docker exec privatecloud-app php artisan privatecloud:check-config >/dev/null || die "production configuration check failed"
INSTANCE_ID="$(docker exec privatecloud-app php artisan tinker --execute 'echo app(App\Services\Instance::class)->id();' 2>/dev/null | tail -1)"
ok "production stack healthy (uid 33, no capabilities), GitHub simulation at $FAKE"

printf '%s' "$ADMIN_PASSWORD" | docker exec -i privatecloud-app php artisan privatecloud:admin --email="$ADMIN_EMAIL" --password-stdin >/dev/null
curl -sk -c "$JAR" -o /dev/null "$API/auth/csrf"
[[ "$(api POST /auth/login "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}" | json "d['user']['email']")" == "$ADMIN_EMAIL" ]] || die "login failed"
api POST /auth/confirm-password "{\"password\":\"$ADMIN_PASSWORD\"}" >/dev/null

step "GitHub fixtures (simulated): repositories, tokens, developer working copies"
fake POST /_control/repos '{"full_name":"acme/public-app","private":false}' >/dev/null
fake POST /_control/repos '{"full_name":"acme/private-app","private":true}' >/dev/null
fake POST /_control/tokens "{\"token\":\"$TOKEN\"}" >/dev/null
for repo in public-app private-app; do
  mkdir -p "$WORK/dev/$repo"
  git -C "$WORK/dev/$repo" init -q -b main
  dev_git "$repo" remote add origin "http://developer:${E2E_DEVELOPER_PASSWORD}@127.0.0.1:18080/git/acme/$repo.git"
  commit "$repo" A "Version A" >/dev/null
  push "$repo"
done
anon_private="$(curl -s -o /dev/null -w '%{http_code}' "$FAKE/api/repos/acme/private-app")"
check "private fixture requires authentication (anonymous API → $anon_private)" test "$anon_private" = 404
ok "acme/public-app and acme/private-app at version A"

step "Connect GitHub"
api POST /settings/github '{"token":"github_pat_11E2E0this0token0was0never0issued"}' >/dev/null
check "invalid token refused ($(status))" test "$(status)" = 422
api POST /settings/github "{\"token\":\"$TOKEN\"}" >/dev/null
check "valid token accepted ($(status))" test "$(status)" = 200
settings="$(api GET /settings)"
check "token never returned to the browser" bash -c "! grep -q '$TOKEN' <<<'$settings'"

# ------------------------------------------------------------ the flows
create_project() { # repo → slug
  api POST /projects "$(python3 - "$1" "$APP_SECRET" <<'PY'
import json, sys
repo, secret = sys.argv[1], sys.argv[2]
print(json.dumps({
    "name": repo, "source_type": "github", "repository": "acme/" + repo, "branch": "main", "auto_deploy": True,
    "domain": repo + ".privatecloud-e2e.dev", "port": 3000, "health_check_path": "/health",
    "health_check_interval": 1, "health_check_retries": 15, "database": True,
    "volumes": [{"name": "data", "mount_path": "/data"}],
    "environment": [{"key": "APP_GREETING", "value": "hello-" + repo}, {"key": "E2E_SECRET", "value": secret, "is_secret": True}],
}))
PY
)" | json "d['data']['slug']"
}

sql() { # database-id SQL → first column of first row (or error)
  api POST "/databases/$1/sql" "$(python3 -c 'import json,sys; print(json.dumps({"sql": sys.argv[1]}))' "$2")" | python3 -c "
import json,sys
d=json.load(sys.stdin)
r=(d.get('result') or d)
rows=r.get('rows') or []
print(list(rows[0].values())[0] if rows and isinstance(rows[0],dict) else (rows[0][0] if rows else d.get('message','')))"
}

acceptance_flow() { # repo
  local repo="$1" slug domain hook a b c d e f db load
  step "[$repo] Create project: repository, production branch main, auto deploy, domain, database, volume"
  slug="$(create_project "$repo")"
  domain="$repo.privatecloud-e2e.dev"
  check "[$repo] project created ($slug)" test -n "$slug"
  hook="$(fake GET /_control/state | json "[h['url'] for h in d['hooks'].get('acme/$repo',{}).values()]")"
  check "[$repo] webhook registered in GitHub through the API" bash -c "grep -q '$DASHBOARD_DOMAIN/api/v1/webhooks/github/' <<<\"$hook\""
  check "[$repo] visibility recorded ($(project_field "$slug" "['repository']['visibility']"))" test "$(project_field "$slug" "['repository']['visibility']")" = "$([[ $repo == private-app ]] && echo private || echo public)"

  step "[$repo] Initial deployment of main (commit A)"
  a="$(dev_git "$repo" rev-parse HEAD)"
  api POST "/projects/$slug/deployments" >/dev/null
  check "[$repo] Deploy Latest pinned the head of main" test "$(json "d['data']['commit']['sha']" < "$WORK/body")" = "$a"
  check "[$repo] A deployed" test "$(wait_sha "$slug" "$a")" = success
  check "[$repo] production serves version A over HTTPS through Caddy" test "$(site "$domain")" = "version A"
  check "[$repo] synced" test "$(sync_state "$slug")" = synced
  check "[$repo] environment applied" test "$(site "$domain" /greeting.txt)" = "hello-$repo"
  db="$(project_field "$slug" "['database']['id']")"
  sql "$db" "create table e2e_rows (version text); insert into e2e_rows values ('A')" >/dev/null
  check "[$repo] database rows written" test "$(sql "$db" "select count(*) as n from e2e_rows")" = 1

  step "[$repo] git push B → webhook → B live (request loop during the switch)"
  load_start "$domain"
  b="$(commit "$repo" B "Version B")"; push "$repo"
  check "[$repo] B deployed automatically" test "$(wait_sha "$slug" "$b")" = success
  sleep 7  # past the drain period of the previous container
  load="$(load_stop)"
  check "[$repo] zero failed requests while switching to B" load_ok "B switch" "$load"
  check "[$repo] B is LIVE" test "$(site "$domain")" = "version B"
  check "[$repo] webhook deployment recorded trigger, repository and delivery" test "$(deployments_json "$slug" | json "[(x['trigger'], x['source']['repository'], bool(x['source']['webhook_delivery_id'])) for x in d['data'] if x['commit']['sha']=='$b'][0]")" = "('webhook', 'acme/$repo', True)"

  step "[$repo] Broken commit C → build fails, B stays live"
  load_start "$domain"
  c="$(commit "$repo" C "Broken C" broken)"; push "$repo"
  check "[$repo] C failed" test "$(wait_sha "$slug" "$c")" = failed
  load="$(load_stop)"
  check "[$repo] production kept answering during the failed deployment" load_ok "broken C" "$load"
  check "[$repo] B still live" test "$(site "$domain")" = "version B"
  check "[$repo] status: deployment failed / out of sync" test "$(sync_state "$slug")" = failed
  check "[$repo] failure explained (stage building)" test "$(deployments_json "$slug" | json "[x['failure']['stage'] for x in d['data'] if x['commit']['sha']=='$c'][0]")" = building

  step "[$repo] Valid commit D → D live"
  d="$(commit "$repo" D "Version D")"; push "$repo"
  check "[$repo] D deployed" test "$(wait_sha "$slug" "$d")" = success
  check "[$repo] D is LIVE" test "$(site "$domain")" = "version D"

  step "[$repo] Rollback to B → B live, main stays D, not redeployed automatically"
  api POST "/projects/$slug/deployments/$(deployment_id_for "$slug" "$b" success)/rollback" >/dev/null
  wait_idle "$slug"
  check "[$repo] B is LIVE after rollback" test "$(site "$domain")" = "version B"
  check "[$repo] status: rolled back / out of sync, main still D" test "$(project_field "$slug" "['sync']['state'] + ' ' + str(d['data']['sync']['rolled_back']) + ' ' + d['data']['sync']['desired']['sha']")" = "out_of_sync True $d"
  local last_push
  last_push="$(fake GET /_control/state | json "[x['id'] for x in d['deliveries'] if x['repo']=='acme/$repo' and x['event']=='push' and x['payload']['after']=='$d'][-1]")"
  fake POST "/_control/redeliver/$last_push" '{"new_id":true}' >/dev/null
  sleep 3
  check "[$repo] GitHub redelivery of D does not undo the rollback" test "$(site "$domain")" = "version B"

  step "[$repo] Push E → auto deploy resumes"
  load_start "$domain"
  e="$(commit "$repo" E "Version E")"; push "$repo"
  check "[$repo] E deployed automatically after the rollback" test "$(wait_sha "$slug" "$e")" = success
  sleep 7
  load="$(load_stop)"
  check "[$repo] zero failed requests while switching to E" load_ok "E switch" "$load"
  check "[$repo] E is LIVE, synced" test "$(site "$domain") $(sync_state "$slug")" = "version E synced"

  step "[$repo] Commit F builds but fails its health check"
  f="$(commit "$repo" F "Unhealthy F" unhealthy)"; push "$repo"
  check "[$repo] F failed at the health check" test "$(wait_sha "$slug" "$f") $(deployments_json "$slug" | json "[x['failure']['stage'] for x in d['data'] if x['commit']['sha']=='$f'][0]")" = "failed health_checking"
  local fid fcontainer
  fid="$(deployment_id_for "$slug" "$f" failed)"
  fcontainer="$(docker ps -aq --filter "label=privatecloud.deployment=$fid")"
  check "[$repo] no failed candidate container left" test -z "$fcontainer"
  check "[$repo] E still live" test "$(site "$domain")" = "version E"

  step "[$repo] Data survives deployments and rollback"
  check "[$repo] database rows still there" test "$(sql "$db" "select string_agg(version, ',') from e2e_rows")" = A
  check "[$repo] one persistent volume across all versions" test "$(site "$domain" /history.txt | tr '\n' ' ')" = "A B D B E "

  step "[$repo] Duplicate, forged, other branches"
  local before_count delivery uuid body sig code
  before_count="$(deployments_json "$slug" | json "len(d['data'])")"
  delivery="$(fake GET /_control/state | json "[x['id'] for x in d['deliveries'] if x['repo']=='acme/$repo' and x['event']=='push'][-1]")"
  fake POST "/_control/redeliver/$delivery" '{}' | json "d['response']" > "$WORK/dup"
  check "[$repo] same delivery twice → duplicate" grep -q duplicate "$WORK/dup"
  uuid="$(project_field "$slug" "['uuid']")"
  body="{\"ref\":\"refs/heads/main\",\"after\":\"$(printf 'f%.0s' $(seq 1 40))\",\"repository\":{\"full_name\":\"acme/$repo\"}}"
  sig="sha256=$(printf '%s' "$body" | openssl dgst -sha256 -hmac wrong-secret | awk '{print $NF}')"
  code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$DASHBOARD_DOMAIN:8443:127.0.0.1" -H 'X-GitHub-Event: push' -H "X-GitHub-Delivery: forged-$repo" -H "X-Hub-Signature-256: $sig" -H 'Content-Type: application/json' -d "$body" "https://$DASHBOARD_DOMAIN:8443/api/v1/webhooks/github/$uuid")"
  check "[$repo] forged signature → $code" test "$code" = 401
  api GET "/audit-logs?per_page=50" > "$WORK/audit.json"
  check "[$repo] forged delivery recorded in the audit log (webhook.rejected)" grep -q 'webhook.rejected' "$WORK/audit.json"
  check "[$repo] no deployment from the forged delivery" test "$(deployments_json "$slug" | json "len(d['data'])")" = "$before_count"
  local br
  for br in develop feature/test release/test; do
    dev_git "$repo" checkout -q -b "$br"; commit "$repo" "$br" "on $br" >/dev/null; push "$repo" "$br"; dev_git "$repo" checkout -q main
  done
  dev_git "$repo" tag v1.0.0; dev_git "$repo" push -q origin v1.0.0 2>/dev/null || true
  sleep 5
  check "[$repo] develop, feature/*, release/* and tags deploy nothing" test "$(deployments_json "$slug" | json "len(d['data'])")" = "$before_count"
  check "[$repo] still serving E" test "$(site "$domain")" = "version E"

  step "[$repo] Rapid pushes G, H, I"
  local g h i states
  g="$(commit "$repo" G "Version G")"; push "$repo"
  h="$(commit "$repo" H "Version H")"; push "$repo"
  i="$(commit "$repo" I "Version I")"; push "$repo"
  wait_idle "$slug"; sleep 3; wait_idle "$slug"
  check "[$repo] final production is the newest commit I" test "$(live_sha "$slug") $(site "$domain")" = "$i version I"
  states="$(deployments_json "$slug" | json "' '.join(x['status'] for x in sorted(d['data'], key=lambda x: x['number']) if x['commit'] and x['commit']['sha'] in ('$g','$h','$i'))")"
  info "G H I: $states"
  deployments_json "$slug" > "$WORK/deployments.json"
  check "[$repo] every retained image is labelled with exactly the commit its deployment recorded" python3 - "$WORK/deployments.json" <<'PY'
import json, subprocess, sys
checked = 0
for x in json.load(open(sys.argv[1]))['data']:
    if x['type'] == 'deploy' and x['image_available'] and x['image_tag']:
        out = subprocess.run(['docker', 'image', 'inspect', '--format', '{{index .Config.Labels "privatecloud.commit"}}', x['image_tag']], capture_output=True, text=True)
        if out.returncode == 0:
            checked += 1
            if out.stdout.strip() != x['commit']['sha']:
                sys.exit(f"{x['image_tag']}: label {out.stdout.strip()} != {x['commit']['sha']}")
sys.exit(0 if checked else 'no image checked')
PY

  step "[$repo] Deploy Latest while a webhook deployment of the same commit is running"
  local j
  j="$(commit "$repo" J "Version J")"; push "$repo"
  sleep 1
  api POST "/projects/$slug/deployments" >/dev/null
  info "Deploy Latest → HTTP $(status) $(json "d.get('meta',{}).get('message') or d.get('code') or d.get('message')" < "$WORK/body" 2>/dev/null || true)"
  wait_idle "$slug"
  check "[$repo] commit J deployed exactly once" test "$(count_for_sha "$slug" "$j")" = 1
  check "[$repo] J live" test "$(site "$domain")" = "version J"
  api POST "/projects/$slug/deployments" >/dev/null
  check "[$repo] Deploy Latest when current → 409 up_to_date" test "$(status) $(json "d.get('code')" < "$WORK/body")" = "409 up_to_date"

  echo "$slug" > "$WORK/slug-$repo"
}

acceptance_flow public-app
acceptance_flow private-app
PUBLIC=public-app
PRIVATE=private-app

# --------------------------------------------------------- public specifics
step "[public] Code downloaded without credentials"
fake GET /_control/state > "$WORK/state.json"
check "[public] archives and commits of the public repository fetched anonymously" python3 -c "
import json,sys
s=json.load(open('$WORK/state.json'))
code=[r for r in s['requests'] if r['path'].startswith('/repos/acme/public-app/') and ('/tarball/' in r['path'] or '/commits/' in r['path'])]
sys.exit(0 if code and all(not r['credential'] for r in code) else 1)"

step "[public] Out-of-order webhook deliveries"
fake POST /_control/hold '{"on":true}' >/dev/null
k="$(commit public-app K "Version K")"; push public-app
l="$(commit public-app L "Version L")"; push public-app
sleep 2
fake POST /_control/hold '{"on":false}' >/dev/null
fake POST /_control/release '{"order":"reverse"}' >/dev/null
wait_idle "$PUBLIC"
check "[public] L (newest) live although its delivery came first" test "$(live_sha "$PUBLIC")" = "$l"
check "[public] the late older delivery (K) deployed nothing" test "$(count_for_sha "$PUBLIC" "$k")" = 0

step "[public] Repository made private, then public again"
fake PATCH /_control/repos/acme/public-app '{"private":true}' >/dev/null
m="$(commit public-app M "Version M")"; push public-app
check "[public→private] still deploys with the saved token" test "$(wait_sha "$PUBLIC" "$m")" = success
check "[public→private] recorded as private" test "$(project_field "$PUBLIC" "['repository']['visibility']")" = private
fake PATCH /_control/repos/acme/public-app '{"private":false}' >/dev/null
api POST "/projects/$PUBLIC/refresh-commit" >/dev/null
check "[private→public] recorded as public again" test "$(project_field "$PUBLIC" "['repository']['visibility']")" = public

step "[public] Production branch deleted on GitHub"
dev_git public-app push -q origin --delete main 2>/dev/null || true
sleep 4
check "[public] branch deletion keeps production online" test "$(site public-app.privatecloud-e2e.dev)" = "version M"
check "[public] dashboard reports the branch as unavailable" test "$(project_field "$PUBLIC" "['sync']['attention']")" = branch_missing
push public-app
n="$(commit public-app N "Version N")"; push public-app
check "[public] pushing main again recovers" test "$(wait_sha "$PUBLIC" "$n")" = success

# --------------------------------------------------------- private specifics
step "[private] GitHub access problems keep production online"
live_before="$(site private-app.privatecloud-e2e.dev)"
fake PATCH "/_control/tokens/$TOKEN" '{"revoked":true}' >/dev/null
r1="$(commit private-app R1 "Version R1")"; push private-app
check "[private] revoked token: deployment fails safely" test "$(wait_sha "$PRIVATE" "$r1")" = failed
check "[private] revoked token: production unchanged" test "$(site private-app.privatecloud-e2e.dev)" = "$live_before"
check "[private] revoked token: dashboard says authentication error" test "$(project_field "$PRIVATE" "['sync']['attention']")" = auth_failed
requests_before="$(fake GET /_control/state | json "len(d['requests'])")"
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "[private] revoked token: no new GitHub requests while backing off" test "$(fake GET /_control/state | json "len(d['requests'])")" = "$requests_before"
fake PATCH "/_control/tokens/$TOKEN" '{"revoked":false}' >/dev/null
api POST /settings/github/check >/dev/null
check "[private] Check connection clears the error" test "$(status)" = 200
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "[private] deploys again after the token works" test "$(wait_sha "$PRIVATE" "$r1")" = success

fake PATCH "/_control/tokens/$TOKEN" '{"contents":false}' >/dev/null
commit private-app R2 "Version R2" >/dev/null; push private-app
sleep 2; wait_idle "$PRIVATE"
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "[private] missing Contents permission explained" bash -c "grep -q 'Contents: Read' '$WORK/body'"
fake PATCH "/_control/tokens/$TOKEN" '{"contents":true,"repos":["acme/other"]}' >/dev/null
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "[private] repository not authorized for the token → $(json "d.get('code')" < "$WORK/body")" test "$(json "d.get('code')" < "$WORK/body")" = source_not_found
fake PATCH "/_control/tokens/$TOKEN" '{"repos":null}' >/dev/null
check "[private] production unchanged throughout" test "$(site private-app.privatecloud-e2e.dev)" = "version R1"

fake POST /_control/ratelimit '{"seconds":60}' >/dev/null
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "[private] rate limit explained" test "$(json "d.get('code')" < "$WORK/body")" = source_rate_limited
fake POST /_control/ratelimit '{"seconds":0}' >/dev/null
docker exec privatecloud-app php artisan tinker --execute 'App\Services\Source\GitHubClient::clearBackoff();' >/dev/null 2>&1 || true

step "[private] The token never leaves PrivateCloud"
check "[private] token never sent to the archive host (codeload)" test "$(fake GET /_control/state | json "d['codeload_auth_leaks']")" = 0
leaks=""
for c in privatecloud-app privatecloud-worker privatecloud-tasks privatecloud-scheduler privatecloud-caddy; do
  docker logs "$c" 2>&1 | grep -q "$TOKEN" && leaks+=" logs:$c"
done
docker exec privatecloud-app sh -c "grep -rl '$TOKEN' /app/storage/logs 2>/dev/null" | grep -q . && leaks+=" laravel-log"
docker exec privatecloud-platform-db pg_dump -U privatecloud privatecloud | grep -q "$TOKEN" && leaks+=" platform-db(plaintext)"
for img in $(docker images --format '{{.Repository}}:{{.Tag}}' --filter label=privatecloud.managed=true); do
  { docker image inspect "$img"; docker history --no-trunc "$img"; } | grep -q "$TOKEN" && leaks+=" image-metadata:$img"
  docker save "$img" | grep -aq "$TOKEN" && leaks+=" image-layers:$img"
done
for ctr in $(docker ps -aq --filter label=privatecloud.managed=true); do
  docker inspect "$ctr" | grep -q "$TOKEN" && leaks+=" container:$ctr"
done
for path in "/projects/$PRIVATE" "/projects/$PRIVATE/deployments" "/projects/$PRIVATE/webhook" "/projects/$PRIVATE/production" /settings /audit-logs /github/repositories; do
  api GET "$path" | grep -q "$TOKEN" && leaks+=" api:$path"
done
for id in $(deployments_json "$PRIVATE" | json "' '.join(str(x['id']) for x in d['data'])"); do
  api GET "/projects/$PRIVATE/deployments/$id/logs" | grep -qE "$TOKEN|$APP_SECRET" && leaks+=" deployment-log:$id"
done
check "[private] token absent from logs, database, images, layers, containers, API and build logs${leaks:+ (found:$leaks)}" test -z "$leaks"
check "[private] build directories removed after deployments" test -z "$(ls -A "$DATA_DIR/builds" 2>/dev/null)"

# --------------------------------------------------------- restart recovery
step "Restart recovery"
docker restart privatecloud-worker >/dev/null
sleep 5
s1="$(commit private-app S1 "Version S1")"; push private-app
check "worker restarted: GitHub link intact, next push deploys" test "$(wait_sha "$PRIVATE" "$s1")" = success

# S2 has a 30 s build step, so the worker is killed while it is really building.
s2="$(commit private-app S2 "Version S2" slow)"; push private-app
st=""
for _ in $(seq 1 120); do
  st="$(deployments_json "$PRIVATE" | json "''.join(x['status'] for x in d['data'] if x['commit']['sha']=='$s2')")"
  [[ "$st" == building ]] && break
  sleep 0.5
done
check "worker killed mid-deployment: caught S2 while building ($st)" test "$st" = building
sleep 3
docker kill privatecloud-worker >/dev/null; docker start privatecloud-worker >/dev/null
check "worker killed mid-deployment: marked failed" test "$(wait_sha "$PRIVATE" "$s2" 120)" = failed
check "worker killed mid-deployment: S1 still live" test "$(site private-app.privatecloud-e2e.dev)" = "version S1"
api POST "/projects/$PRIVATE/deployments" >/dev/null
check "worker killed mid-deployment: lock released, S2 deploys" test "$(wait_sha "$PRIVATE" "$s2")" = success

# Like a reboot or an update of PrivateCloud: every control-plane container is
# removed and re-created (volumes kept). The GitHub simulation keeps running.
PLATFORM="app worker tasks scheduler caddy platform-db apps-db redis"
compose stop $PLATFORM >/dev/null 2>&1
compose rm -f $PLATFORM >/dev/null 2>&1
compose up -d --no-build $PLATFORM >/dev/null 2>&1
for _ in $(seq 1 90); do
  [[ "$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null)" == healthy ]] && break
  sleep 2
done
curl -sk -c "$JAR" -o /dev/null "$API/auth/csrf"
api POST /auth/login "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}" >/dev/null
link="$(project_field "$PRIVATE" "['repository']['full_name'] + ' ' + d['data']['repository']['branch'] + ' ' + str(d['data']['auto_deploy']) + ' ' + str(d['data']['repository']['webhook_installed'])")"
check "control plane re-created: repository link, branch, auto deploy and webhook kept ($link)" test "$link" = "acme/private-app main True True"
reachable=0
for _ in $(seq 1 40); do [[ "$(site private-app.privatecloud-e2e.dev)" == "version S2" ]] && { reachable=1; break; }; sleep 3; done
check "control plane re-created: production reachable through Caddy" test "$reachable" = 1
s3="$(commit private-app S3 "Version S3")"; push private-app
check "control plane re-created: next push deploys" test "$(wait_sha "$PRIVATE" "$s3")" = success
info "Docker daemon restart: not exercised here (would disrupt the host's Docker); see docs/first-server-test.md"

step "Production reconciliation"
check "production-status: branch heads, recorded production, containers and routes agree" docker exec privatecloud-app php artisan privatecloud:production-status

# ------------------------------------------------------------------ summary
step "Summary (GitHub simulated locally; Docker, git, Caddy and PostgreSQL real)"
passes="$(grep -c '^PASS' "$RESULTS" || true)"; failures="$(grep -c '^FAIL' "$RESULTS" || true)"
grep '^FAIL' "$RESULTS" | sed 's/^FAIL|/    FAILED: /' || true
printf '\n    %s passed, %s failed\n' "$passes" "$failures"
[[ "$failures" == 0 ]] || exit 1
printf '\n\033[1;32mGitHub production branch → live: all end-to-end checks passed.\033[0m\n'
