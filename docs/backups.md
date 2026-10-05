# Backups

## What is backed up

| Kind | How | File |
| --- | --- | --- |
| Application database | `pg_dump --format=custom` (compressed) | `backups/databases/<db>/<timestamp>-<id>.dump` |
| Volume (persistent files) | `tar -czf` in a short-lived helper container with the volume mounted read-only and no network | `backups/volumes/<project>/<volume>/<timestamp>-<id>.tar.gz` |
| PrivateCloud itself | `scripts/backup-platform.sh`: platform database dump + copy of `.env` | `backups/platform/` |

Paths are relative to the data directory (default `/var/lib/privatecloud`).

## A backup is "Completed" only after it is verified

A backup moves `queued → running → completed` or `failed`. It is marked completed only
after:

1. the tool exited successfully,
2. the file exists and is not empty,
3. the file is readable as an archive (`pg_restore --list` for dumps, `tar -tzf` for
   archives),
4. its SHA-256 checksum and size were recorded.

A failed backup deletes its partial file and sends a notification.

Volume backups are taken while the application runs. For applications that write
constantly (e.g. an embedded SQLite database), stop the application before backing up
or use a PostgreSQL database instead.

## Back up now

- A whole project: *Project → Backups → Backup Now* (its database and every volume).
- One database: *Databases → (database) → Backup Now*.

## Scheduled backups

*Project → Settings → Scheduled backups*: off, daily or weekly at a time (server time
zone), keeping the last *N* scheduled backups per source (default 7). Manual and
pre-restore backups are not removed automatically.

For the platform itself, add a cron entry on the server:

```
15 3 * * *  /opt/privatecloud/scripts/backup-platform.sh >> /var/log/privatecloud-backup.log 2>&1
```

## Restore

*Restore* on a completed backup asks you to type the database or volume name and to
confirm your password. Then:

1. The backup file's checksum is verified. A corrupted file is never restored.
2. A **safety backup** of the current data is taken. If it fails, nothing is restored.
3. Database: open connections are closed and `pg_restore --clean --if-exists
   --single-transaction --exit-on-error` runs — either the whole restore succeeds or the
   database is left unchanged. Restored objects are owned by the application's role.
4. Volume: the application is stopped, the volume emptied and the archive extracted, and
   the application started again.

Progress is shown in the dashboard. The safety backup appears in the list as
"safety backup before restore" if you need to undo.

## Downloading

*Download* (password confirmation required) streams the file. Database dumps can be
restored anywhere with `pg_restore`:

```bash
pg_restore --clean --if-exists --no-owner -d "postgresql://user:pass@host/db" backup.dump
```

## Keep copies off the server

**Backups stored only on the same server do not protect you if the server is lost**
(deleted instance, failed disk, compromised account). Copy them elsewhere.

With [rclone](https://rclone.org) to any S3-compatible storage (Vultr Object Storage,
Backblaze B2, Cloudflare R2, AWS S3):

```bash
sudo apt install rclone
rclone config            # create a remote named "offsite"
# nightly sync after the backups have run
30 4 * * *  rclone sync /var/lib/privatecloud/backups offsite:my-bucket/privatecloud
```

Or with rsync to another machine:

```bash
30 4 * * *  rsync -az --delete /var/lib/privatecloud/backups/ backup@other-host:/backups/privatecloud/
```

Built-in off-server storage is planned: backups are written through a `BackupStorage`
interface (`backend/app/Services/Backups/BackupStorage.php`) so an S3-compatible
implementation can be added and selected with `PC_BACKUP_STORAGE` without changing the
backup logic.

## Disaster recovery (new server)

1. Install PrivateCloud on the new server with the same dashboard domain, but **before
   starting it**, copy your old `.env` into place (it contains `APP_KEY`).
2. Start the stack (`sudo ./scripts/install.sh …` keeps an existing `.env`).
3. Restore the platform database:
   ```bash
   docker exec -i privatecloud-platform-db pg_restore --clean --if-exists -U privatecloud -d privatecloud < platform-<timestamp>.dump
   ```
4. Copy the `backups/` directory back into the data directory.
5. Re-create the application databases (empty) from the restored records:
   ```bash
   docker compose exec app php artisan privatecloud:reprovision-databases
   ```
   Then restore each database's backup from the Backups page.
6. Redeploy each project (*Deploy Latest*). DNS can stay the same if you keep the IP
   (reserved IP) or update the A records to the new server.
