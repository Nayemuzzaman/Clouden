#!/usr/bin/env bash
# Back up PrivateCloud's own state: the platform database (projects, deployments,
# encrypted secrets, audit log) and the .env file (which contains APP_KEY, needed
# to decrypt those secrets). Application databases and volumes are backed up from
# the dashboard instead.
#
# Run it from cron, e.g.:  15 3 * * *  /opt/privatecloud/scripts/backup-platform.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="$(grep -E '^PC_DATA_DIR=' "$ROOT/.env" | cut -d= -f2-)"
DEST="${DATA_DIR}/backups/platform"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
KEEP="${KEEP:-14}"

mkdir -p "$DEST"
docker exec privatecloud-platform-db pg_dump -U privatecloud -d privatecloud --format=custom > "$DEST/platform-${STAMP}.dump"
docker exec -i privatecloud-platform-db pg_restore --list < "$DEST/platform-${STAMP}.dump" >/dev/null
install -m 600 "$ROOT/.env" "$DEST/env-${STAMP}"
chmod 600 "$DEST/platform-${STAMP}.dump"

# Keep the newest $KEEP backups of each kind.
ls -1t "$DEST"/platform-*.dump 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
ls -1t "$DEST"/env-* 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
echo "Platform backup written to $DEST/platform-${STAMP}.dump (verified)"
