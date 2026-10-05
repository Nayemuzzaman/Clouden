#!/usr/bin/env bash
#
# Update PrivateCloud to the latest commit of the current git branch.
#
#   sudo ./scripts/update.sh [--yes]
#
# 1. refuses to run with local code changes
# 2. waits until no deployment, backup or restore is running
# 3. backs up the platform database and .env (verified)
# 4. pulls and builds the new image while the old version keeps running
# 5. restarts the control plane (migrations run on start) and waits for health
#
# Applications keep running throughout: only the control-plane containers restart.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
WAIT_MINUTES="${PC_UPDATE_WAIT_MINUTES:-60}"
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
compose() { docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" "$@"; }

[[ $EUID -eq 0 ]] || fail "Run as root (sudo)."
[[ -f "$ROOT/.env" ]] || fail "$ROOT/.env not found. Install first with scripts/install.sh."
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail "The installation directory has local code changes (git status). Commit, stash or discard them first."

PREVIOUS="$(git rev-parse HEAD)"
echo "==> Current version: $(git log -1 --format='%h %s')"

echo "==> Fetching the latest code"
git fetch --quiet
if [[ "$(git rev-parse HEAD)" == "$(git rev-parse '@{u}')" ]]; then
  echo "Already up to date."
  exit 0
fi
git log --oneline "HEAD..@{u}" | sed 's/^/    /'

echo "==> Waiting until no deployment, backup or restore is running (max ${WAIT_MINUTES} min)"
for ((i = 0; i < WAIT_MINUTES * 6; i++)); do
  if status="$(compose exec -T app php artisan privatecloud:idle 2>/dev/null)"; then
    break
  fi
  (( i % 6 == 0 )) && echo "    ${status:-control plane not answering}"
  sleep 10
done
compose exec -T app php artisan privatecloud:idle >/dev/null 2>&1 || fail "Background work is still running after ${WAIT_MINUTES} minutes; try again later."

echo "==> Backing up the platform database and configuration"
"$ROOT/scripts/backup-platform.sh"

echo "==> Updating the code"
git merge --ff-only --quiet '@{u}'

echo "==> Building (the current version keeps running)"
if ! compose build --pull; then
  git reset --quiet --hard "$PREVIOUS"
  fail "The build failed; the code was reset to $PREVIOUS and nothing was restarted."
fi

echo "==> Restarting the control plane"
compose up -d --remove-orphans

status="starting"
for _ in $(seq 1 90); do
  status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' privatecloud-app 2>/dev/null || echo starting)"
  [[ "$status" == "healthy" ]] && break
  sleep 2
done
if [[ "$status" != "healthy" ]]; then
  docker logs --tail 40 privatecloud-app 2>&1 | sed 's/^/    | /' >&2 || true
  cat >&2 <<EOF

The new version did not become healthy (status: $status). Your applications
are still running. To go back to the previous version:

  cd $ROOT
  git reset --hard $PREVIOUS
  docker compose build && docker compose up -d

If the new version already migrated the database, also restore the platform
backup taken above (docs/backups.md, "Disaster recovery", step 3).
EOF
  exit 1
fi

compose exec -T app php artisan privatecloud:health || echo "Some services report problems (see above)."
echo "==> Updated to $(git log -1 --format='%h %s')"
