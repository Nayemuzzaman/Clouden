# PrivateCloud

**Your own Railway/Vercel-style platform on a single VPS.** Install it once on a fresh
Ubuntu server, then deploy applications from GitHub, give them domains with automatic
HTTPS, create PostgreSQL databases, manage environment variables, read logs, take
backups and watch server health — from a web dashboard, without SSH.

![Project overview](docs/images/project-overview.png)

```
Write code → git push → Deploy Latest → live (after a successful build and health check)
```

## Features

- **Projects** from a GitHub repository (public or private), any public git URL, or a
  Docker image. The Dockerfile is the deployment contract.
- **Safe deployments**: build → start next to the live version → health check → switch
  traffic → retire the old container. A failed build or health check never touches
  production. Live progress, full build logs, and a plain-language explanation of what
  failed ("Step: `npm run build` — Error: Module not found").
- **Rollback** to any recent successful deployment (images are retained), **redeploy**
  with new settings, **auto deploy** on `git push` via signed GitHub webhooks.
- **Domains & HTTPS**: Let's Encrypt certificates through Caddy, DNS checks with
  step-by-step instructions, certificate status.
- **PostgreSQL**: one isolated database and user per application, credentials injected as
  environment variables, a Supabase-style **table browser** (browse, filter, sort, insert,
  edit, delete rows; create tables; add/edit columns) and a **SQL editor** with
  destructive-query confirmation and history.
- **Environment variables** encrypted at rest; secrets masked and revealed only after
  re-entering your password; `.env` import.
- **Logs**: application output and web access logs with live tail, search, level filter
  and download.
- **Backups**: database dumps and volume archives, verified and checksummed before they
  count as completed; scheduled backups with retention; restore with an automatic safety
  backup first.
- **Monitoring**: CPU, RAM, disk, load, network, uptime, per-application usage and limits,
  service health, history charts, configurable warnings.
- **Containers**: start, stop, restart; memory/CPU/process limits per application.
- Audit log, in-dashboard notifications, ⌘K command palette, light and dark mode,
  responsive layout.

| | |
| --- | --- |
| ![Failed deployment explained](docs/images/deployment-failed.png) | ![Table browser](docs/images/table-browser.png) |
| ![Dashboard](docs/images/dashboard.png) | ![Dark mode](docs/images/environment-dark.png) |

## How it works

```
Browser → Caddy (HTTPS) → Laravel API + React dashboard → Docker · PostgreSQL · Caddy · GitHub
                       ↘ your applications (one container + network per project)
```

Everything runs in Docker on your server: the control plane (Laravel 13 / PHP 8.4 API
with the React dashboard, queue workers, scheduler), PostgreSQL for PrivateCloud's own
data, a separate PostgreSQL server for your applications, Redis and Caddy. Only Caddy is
exposed (ports 80/443). Details: [docs/architecture.md](docs/architecture.md).

## Requirements

- A fresh **Ubuntu 24.04 LTS** server (tested target: Vultr Cloud Compute). 1 vCPU / 2 GB
  RAM works for small apps; 2 vCPU / 4 GB is comfortable. 25 GB+ disk.
- A domain for the dashboard, e.g. `cloud.example.com`, with an **A record** pointing to
  the server.
- Ports 22, 80 and 443 reachable.

## Installation

1. **Create the server** (Vultr: *Deploy → Cloud Compute → Ubuntu 24.04*), and add an
   `A` record `cloud.example.com → <server IP>` at your DNS provider.
2. **Clone and install** as root:

   ```bash
   ssh root@<server-ip>
   git clone https://github.com/<you>/privatecloud.git /opt/privatecloud
   cd /opt/privatecloud
   ./scripts/install.sh --domain cloud.example.com --email you@example.com
   ```

   The installer:
   - refuses anything but Ubuntu 24.04 (x86_64/arm64, systemd), Docker older than 25,
     less than 5 GB free disk, ports 80/443 already in use, placeholder domains/emails;
   - installs Docker CE (log rotation, live-restore), enables `ufw` (deny incoming; SSH on
     every port sshd uses, 80, 443), adds 2 GB swap on servers with < 4 GB RAM;
   - generates `.env` with random secrets (root, mode 600; never printed). An existing
     `.env` is **never overwritten** — it is validated, and a development `.env` is
     refused;
   - builds and starts the stack (API, two queue workers, scheduler, Caddy, two
     PostgreSQL servers, Redis), installs a systemd unit and a nightly platform backup;
   - asks for the administrator password (or `--admin-password-file FILE` for unattended
     installs) and passes it on stdin;
   - finishes with `scripts/validate-install.sh`.

   It is safe to run again. `./scripts/install.sh --help` lists the options.

3. **Back up `/opt/privatecloud/.env` somewhere safe, off the server** (password manager
   or encrypted storage). It contains `APP_KEY`, which decrypts all stored secrets.
4. **Validate** at any time: `sudo ./scripts/validate-install.sh` (configuration, exposed
   ports, firewall, services, HTTPS certificate, backups).

## First login

Open `https://cloud.example.com` and sign in with the administrator account. There is no
public sign-up. Forgot the password?
`docker compose exec app php artisan privatecloud:admin --email=you@example.com --reset`

## Create a project

*Dashboard → New Project*:

1. **Source** — GitHub repository + branch, a git URL, or a Docker image.
2. **Domain** (optional) and **Create a PostgreSQL database** (optional).
3. **Environment variables** — add them or paste a `.env` file.
4. **Resources** — RAM limit, CPU limit, application port, health check path.

Click **Create Project**, then **Deploy now**. The dashboard follows the real state:
*Queued → Fetching source → Building → Starting → Health checking → Routing →
Successful*.

Your application needs a Dockerfile and must listen on `0.0.0.0:$PORT`. Starter
Dockerfiles for Node.js, Next.js, React, Laravel and Python:
[docs/deployment.md](docs/deployment.md#starter-dockerfiles).

## Connect GitHub

*Settings → GitHub* → paste a fine-grained personal access token with *Contents: Read*
and *Metadata: Read* (and *Webhooks: Read and write* for automatic webhooks). Required for
private repositories. See [docs/github.md](docs/github.md).

## Deploy, roll back, auto deploy

- **Deploy Latest** builds the newest commit of the branch. The overview shows the latest
  commit and the commit in production.
- **Rollback** (Deployments) puts a previous successful version back live, health-checked
  first.
- **Redeploy** applies changed environment variables, volumes or limits.
- **Auto deploy** (*Settings*) deploys every push to the branch.

## Domains

*Project → Domains → Add Domain*. Point an A record at the server (the page shows the
exact record). HTTPS is issued automatically once DNS is correct; the status shows *DNS
not ready*, *HTTPS pending*, *HTTPS active* or the Let's Encrypt error.

## Databases

*Project → Database → Create PostgreSQL Database* (or *Databases → Create Database*).
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DATABASE_URL` are
added to the project's environment. *Open tables & SQL* gives you the table browser and
SQL editor. Databases are reachable only from inside the server.

## Environment variables

*Project → Environment*. Values are encrypted at rest; variables whose names look like
secrets are masked automatically. After changes, click **Redeploy** to apply them.

## Backups and restore

- **Backup Now** on a project or database; **Scheduled backups** in project settings.
- **Restore** asks you to type the database/volume name and confirm your password, takes a
  safety backup of the current data, then restores (databases restore in one transaction).
- Backups are stored on the server under `/var/lib/privatecloud/backups`. **Copy them off
  the server** — see [docs/backups.md](docs/backups.md) for rclone/rsync examples and
  disaster recovery, and back up PrivateCloud itself with `scripts/backup-platform.sh`.

## Updating PrivateCloud

```bash
cd /opt/privatecloud
sudo ./scripts/update.sh
```

The script refuses to run with local code changes, waits until no deployment, backup or
restore is running, takes a verified platform backup, builds the new version while the
old one keeps running, restarts the control plane (migrations run on start) and waits for
it to be healthy. If the build fails, nothing is restarted; if the new version is not
healthy, it stops with exact rollback instructions. Your applications keep running
throughout.

## Configuration

Settings live in `.env` (see [.env.example](.env.example)); defaults and descriptions are in
[backend/config/privatecloud.php](backend/config/privatecloud.php). Common ones:

| Variable | Default | Meaning |
| --- | --- | --- |
| `PC_DASHBOARD_DOMAIN` | — | Dashboard hostname |
| `PC_PUBLIC_IPV4` | detected | Server IP used for DNS checks |
| `PC_DATA_DIR` | `/var/lib/privatecloud` | Backups, routing files, builds |
| `PC_BUILD_TIMEOUT` | `1800` | Seconds before a build is aborted |
| `PC_MIN_FREE_DISK_MB` | `2048` | Free disk required to start a build |
| `PC_THRESHOLD_CPU` / `_MEMORY` / `_DISK` | `90` / `90` / `85` | Warning thresholds (%), also editable in Settings |
| `PC_METRICS_RETENTION_DAYS` | `3` | Metric history kept |
| `PC_PASSWORD_CONFIRM_MINUTES` | `15` | How long a password confirmation unlocks secrets |
| `PC_REMEMBER_DAYS` | `14` | Maximum lifetime of "remember me" |
| `PC_BACKUP_MIN_FREE_DISK_MB` | `1024` | Free disk that must remain after a backup |
| `PC_HISTORY_RETENTION_DAYS` / `PC_AUDIT_RETENTION_DAYS` | `90` / `365` | History pruning |

The control plane refuses to start with an unsafe production configuration (debug mode,
plain HTTP, insecure cookies, weak passwords, root user, placeholder domain or account);
the reason is in `docker logs privatecloud-app`.

## Troubleshooting

See [docs/troubleshooting.md](docs/troubleshooting.md) — sign-in, DNS/HTTPS, failed
deployments by stage, crashed apps, stuck queues, memory and disk.

## Security recommendations

Use SSH keys only, keep unattended upgrades on, use a strong unique password, keep backups
and `.env` off the server, use a fine-grained GitHub token, and update regularly.

**The Docker socket is a privileged trust boundary**: the control plane needs it to run
your applications, and access to it is equivalent to root on the server. It is never
exposed to applications or the network, and the control plane runs unprivileged with all
capabilities dropped — but whoever controls the administrator account or the control
plane controls the server. Details, and how PrivateCloud protects secrets, sessions,
commands and SQL: [docs/security.md](docs/security.md).

## Development

Run the full stack locally with Docker Compose, run the test suites, and the end-to-end
procedure: [docs/development.md](docs/development.md).

## Project status

**V1 release candidate — not yet validated on a real server.** Treat the first
installation as a test (see [docs/first-server-test.md](docs/first-server-test.md)) and
do not move important applications to it until that test passes.

Verified automatically and locally:

- Backend: 158 automated tests (feature, unit, and integration against a real
  PostgreSQL 17 server, including a real `pg_dump`/`pg_restore` round trip and database
  isolation), Larastan level 5 with no errors, Pint. Frontend: 23 component/page tests,
  strict TypeScript. Scripts: ShellCheck; installer guard rails and the fresh-install
  path exercised in an Ubuntu 24.04 container with system tools stubbed.
- End to end with real Docker (Docker Desktop on macOS):
  - development stack: deploy, update under load with zero failed requests, failed
    build and failed health check keeping production running, rollback, logs, restart,
    table editing confirmed with `psql`, database and volume backup/restore, signed
    webhooks (valid, forged, duplicate), concurrent deploy requests, image-source
    projects, project deletion, browser-driven create → deploy;
  - **production mode** (production compose file, uid 33, all capabilities dropped,
    HTTPS via Caddy's internal CA on localhost): configuration guard, placeholder admin
    refused, Secure/HttpOnly/SameSite=Strict cookies, no CORS headers, `X-Forwarded-For`
    spoofing does not bypass login rate limits, unknown hosts get 404, git deployment,
    worker killed mid-deployment then recovered and unlocked, backups, full control-plane
    re-create with project networks repaired.

**Requires a real server** (not yet tested): `scripts/install.sh` end to end on Vultr,
`ufw` + Docker interaction, Let's Encrypt issuance and renewal, public DNS checks, the
GitHub API and webhooks against github.com (covered by tests with recorded responses),
reboot recovery, `update.sh` against a real remote.

**Known limitations (V1)**: one administrator; no MFA; one server and one installation
per Docker host; builds run one at a time and BuildKit builds have no memory limit (swap
and OOM priorities protect the databases); private repositories only from GitHub (personal
access token; GitHub App planned); public Docker images only; backups stored locally
(off-site copies via rclone/rsync; S3 storage planned); volume backups are taken while
the app runs; applications share the host kernel; IPv6 clients share one rate-limit
bucket (see [security](docs/security.md)).

**Not in V1 by design**: Kubernetes, teams/RBAC, billing, other cloud providers, multiple
git providers, CDN, autoscaling, serverless. The data model already references servers so
multi-node management can be added later ([architecture](docs/architecture.md#path-to-multiple-servers)).
