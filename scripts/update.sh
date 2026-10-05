#!/usr/bin/env bash
# Update PrivateCloud to the latest version of the current git branch.
# Takes a platform backup first, rebuilds the image and restarts the services.
# Migrations run automatically when the API container starts.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }

echo "==> Backing up the platform database and configuration"
"$ROOT/scripts/backup-platform.sh"

echo "==> Fetching the latest code"
git pull --ff-only

echo "==> Building"
docker compose build

echo "==> Restarting services (applications keep running; only the control plane restarts)"
docker compose up -d --remove-orphans

for _ in $(seq 1 90); do
  [[ "$(docker inspect --format '{{.State.Health.Status}}' privatecloud-app 2>/dev/null)" == "healthy" ]] && break
  sleep 2
done
docker compose ps
echo "==> Update complete"
