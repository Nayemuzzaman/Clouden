#!/bin/sh
# Entrypoint for all PrivateCloud control-plane roles:
#   app        API + dashboard (runs migrations first)
#   worker     deployment queue (one deployment at a time, joins project networks for health checks)
#   tasks      backups, restores, domain checks, deletions
#   scheduler  periodic jobs (metrics, reconciliation, scheduled backups)
#   <other>    executed as-is (e.g. "php artisan privatecloud:admin")
set -e

cd /app

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is not set. Run scripts/install.sh (or set APP_KEY in .env)." >&2
  exit 1
fi

if [ -n "${PC_DATA_DIR:-}" ]; then
  mkdir -p "$PC_DATA_DIR/caddy/sites" "$PC_DATA_DIR/caddy/logs" "$PC_DATA_DIR/backups" "$PC_DATA_DIR/builds" 2>/dev/null || true
fi

wait_for_database() {
  i=0
  until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:5432).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0);} catch (Throwable $e) { exit(1);}'; do
    i=$((i+1))
    if [ "$i" -gt 60 ]; then echo "Database is not reachable" >&2; exit 1; fi
    sleep 2
  done
}

prepare() {
  php artisan config:cache >/dev/null
  php artisan route:cache >/dev/null
  php artisan event:cache >/dev/null
}

role="${1:-app}"
case "$role" in
  app)
    wait_for_database
    php artisan migrate --force --isolated
    prepare
    exec frankenphp run --config /etc/frankenphp/Caddyfile
    ;;
  worker)
    wait_for_database
    prepare
    exec php artisan queue:work --queue=deployments --sleep=2 --timeout="$(( ${PC_BUILD_TIMEOUT:-1800} + 900 ))" --memory=512 --max-time=86400
    ;;
  tasks)
    wait_for_database
    prepare
    exec php artisan queue:work --queue=default --sleep=2 --timeout="$(( ${PC_BACKUP_TIMEOUT:-3600} * 2 + 300 ))" --memory=512 --max-time=86400
    ;;
  scheduler)
    wait_for_database
    prepare
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
