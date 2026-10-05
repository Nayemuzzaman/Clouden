#!/usr/bin/env bash
# Create a local development .env and data directory. Safe to re-run: an
# existing .env is never overwritten.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

if [[ -f .env ]]; then
  echo ".env already exists — leaving it unchanged."
else
  rand() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }
  cat > .env <<ENV
PC_NAME=PrivateCloud (dev)
PC_DASHBOARD_DOMAIN=localhost
APP_URL=http://localhost:8088
PC_PUBLIC_IPV4=127.0.0.1
PC_HOST_HOSTNAME=$(hostname)
PC_DATA_DIR=${ROOT}/.data
APP_NAME=PrivateCloud
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:$(openssl rand -base64 32)
LOG_LEVEL=debug
SESSION_LIFETIME=480
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
DB_PASSWORD=$(rand 32)
PC_APPS_DB_ADMIN_PASSWORD=$(rand 32)
PC_UID=0
PC_GID=0
DOCKER_GID=0
PC_AUTO_HTTPS=off
PC_HTTP_PORT=8088
PC_PUBLIC_URL=http://localhost:8088
ENV
  echo "Created .env for local development."
fi

mkdir -p .data/caddy/sites .data/caddy/logs .data/backups .data/builds
echo
echo "Next:"
echo "  docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build"
echo "  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app php artisan privatecloud:admin"
echo "  open http://localhost:8088   (or run the Vite dev server: cd frontend && npm run dev)"
