#!/usr/bin/env bash
#
# Back up PrivateCloud's own state: the platform database (projects,
# deployments, encrypted secrets, audit log) and the .env file (which contains
# APP_KEY, needed to decrypt those secrets). Application databases and volumes
# are backed up from the dashboard instead.
#
# Files go to <PC_DATA_DIR>/backups/platform (root only). The dump is written
# to a temporary file, verified with pg_restore --list, and only then renamed;
# a failed run never leaves a file that looks like a valid backup.
#
# The installer schedules this nightly in /etc/cron.d/privatecloud.
# KEEP=<n> (default 14) sets how many backups of each kind are kept.
#
# The .env copies contain every secret: when you copy backups off the server,
# store them encrypted.
set -euo pipefail
umask 077
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ -f "$ROOT/.env" ]] || { echo "$ROOT/.env not found." >&2; exit 1; }

DATA_DIR="$(grep -E '^PC_DATA_DIR=' "$ROOT/.env" | tail -1 | cut -d= -f2- | tr -d '"'"'")"
[[ "$DATA_DIR" == /* ]] || { echo "PC_DATA_DIR in .env must be an absolute path." >&2; exit 1; }
DEST="${DATA_DIR}/backups/platform"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
KEEP="${KEEP:-14}"
[[ "$KEEP" =~ ^[0-9]+$ && "$KEEP" -ge 1 ]] || { echo "KEEP must be a positive number." >&2; exit 1; }

install -d -m 0700 -o root -g root "$DEST"
free_mb="$(df -BM --output=avail "$DEST" | tail -1 | tr -dc '0-9')"
(( free_mb >= 512 )) || { echo "Only ${free_mb} MB free in $DEST; not starting the backup." >&2; exit 1; }

tmp="$DEST/.platform-${STAMP}.dump.partial"
trap 'rm -f "$tmp"' EXIT
docker exec privatecloud-platform-db pg_dump -U privatecloud -d privatecloud --format=custom > "$tmp"
[[ -s "$tmp" ]] || { echo "pg_dump produced an empty file." >&2; exit 1; }
docker exec -i privatecloud-platform-db pg_restore --list < "$tmp" >/dev/null
mv "$tmp" "$DEST/platform-${STAMP}.dump"
trap - EXIT
install -m 600 -o root -g root "$ROOT/.env" "$DEST/env-${STAMP}"

# Keep the newest $KEEP backups of each kind.
find "$DEST" -maxdepth 1 -name 'platform-*.dump' -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP + 1)) | cut -d' ' -f2- | xargs -r rm -f
find "$DEST" -maxdepth 1 -name 'env-*' -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP + 1)) | cut -d' ' -f2- | xargs -r rm -f
echo "$(date -u +%FT%TZ) platform backup written to $DEST/platform-${STAMP}.dump ($(du -h "$DEST/platform-${STAMP}.dump" | cut -f1), verified)"
