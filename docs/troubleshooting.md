# Troubleshooting

Most problems are explained in the dashboard (failed deployments show the failing step,
domains show DNS and certificate state, *Server* shows the health of every service).
This page covers the rest. Commands run on the server from the installation directory
(e.g. `/opt/privatecloud`).

## Useful commands

```bash
docker compose ps                                  # state of the control-plane services
docker compose logs -f app worker tasks scheduler  # control-plane logs
docker compose logs -f caddy                       # web server / certificate logs
docker ps --filter label=privatecloud.managed=true # application containers
sudo systemctl restart privatecloud                # restart the whole stack
docker compose exec app php artisan privatecloud:reconcile   # re-check state now
```

## Cannot sign in

- **Forgot the password:**
  `docker compose exec app php artisan privatecloud:admin --email=you@example.com --reset`
- **"Too many requests":** login is limited to 5 attempts per minute; wait a minute.
- **Page reloads back to the login screen:** the dashboard must be opened over HTTPS on
  `PC_DASHBOARD_DOMAIN` (session cookies are `Secure`). Check that the URL matches.

## The dashboard does not load / HTTPS error on the dashboard

1. `dig +short A cloud.example.com` must return the server's IP.
2. Ports 80 and 443 must be open: `sudo ufw status`, and in the Vultr firewall (if used).
3. `docker compose logs caddy | grep -i error` shows certificate (ACME) errors. Let's
   Encrypt rate-limits repeated failures for the same domain: fix DNS first, then
   `docker compose restart caddy`.

## Domain shows "DNS not ready" or "HTTPS failed"

- Create an **A record** for the exact hostname pointing to the server IP shown on the
  Domains page (remove other A/AAAA records for that name, including proxies such as
  Cloudflare's orange cloud — or set Cloudflare SSL mode to *Full (strict)* after the
  certificate is issued).
- DNS changes can take up to an hour. Click **Check now** afterwards.
- `PC_PUBLIC_IPV4` in `.env` must be the server's public IP for the DNS check to be
  accurate.
- The certificate is requested by Caddy automatically once DNS is correct. If the status
  stays *Failed*, the error from Let's Encrypt is shown on the Domains page.

## Deployment failed

| Stage | Typical causes |
| --- | --- |
| Fetching source | Wrong repository/branch, expired GitHub token, private repo without token, no internet. |
| Building | Error in the Dockerfile or the build command (shown as *Step* and *Error*). "No Dockerfile found" — add one (see [deployment.md](deployment.md#starter-dockerfiles)) or fix *Settings → Dockerfile path*. "Not enough free disk space" — *Server → Free up disk space*, delete old backups. |
| Health checking | *Nothing is listening on port N*: the app listens on a different port or on `127.0.0.1` — make it listen on `0.0.0.0:$PORT` or change *Settings → Application port*. *HTTP 404/500*: the health check path is wrong or the app fails — read the container output shown below the error. *Exited during startup*: missing environment variable, failed migration, crash. |
| Routing | The web server rejected the configuration. The previous configuration stays active; check `docker compose logs caddy`. |

In every case the previous version keeps running.

## Application shows "Crashed"

The live container stopped or keeps restarting. *Monitoring → Container* shows the exit
code and whether it was **killed for exceeding the memory limit** — raise *Settings →
Resources → RAM limit* and redeploy. *Logs → Application* shows the last output.

## Deployments stay "Queued"

The deployment worker is not running or is busy with another (possibly long) build.
*Server → Services → Background worker* must be green.

```bash
docker compose ps worker
docker compose logs --tail=100 worker
docker compose restart worker
```

Deployments that do not finish within an hour (`PC_DEPLOY_STALE_AFTER`) are marked failed
automatically; queued deployments older than 12 hours are failed too.

## Server is slow / out of memory

- *Server* shows memory per service; *Containers* shows memory per application.
- Lower RAM limits of projects that do not need them.
- The installer adds 2 GB swap on servers with less than 4 GB RAM; builds need memory.
- Builds run one at a time on purpose.

## Disk is full

- *Server → Free up disk space* removes old build cache and unused images.
- Delete old backups (*Backups*), lower *Images kept for rollback*, lower scheduled
  backup retention.
- `docker system df` shows what uses space.

## Changing configuration

Edit `.env` and run `docker compose up -d` (containers with changed settings are
recreated). Changing the dashboard domain: update `PC_DASHBOARD_DOMAIN` **and** `APP_URL`,
point DNS to the server, then `docker compose up -d`. **Never change `APP_KEY`** — the
encrypted secrets would become unreadable.

## Where things are

| What | Where |
| --- | --- |
| Configuration | `<install dir>/.env` |
| Backups, routing files, build directories | `PC_DATA_DIR` (default `/var/lib/privatecloud`) |
| Generated Caddy site per project | `PC_DATA_DIR/caddy/sites/<project>.caddy` (do not edit) |
| Access logs | `PC_DATA_DIR/caddy/logs/` |
| Platform and application databases, Redis, certificates | Docker volumes `privatecloud_*` |
| Application volumes | Docker volumes `pc-vol-<project>-<name>` |
