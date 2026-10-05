# Architecture

PrivateCloud turns one Ubuntu server into a small application platform. Everything
it runs — the control plane and your applications — runs in Docker containers on
that server.

```
Browser ──HTTPS──▶ Caddy (privatecloud-caddy, ports 80/443)
                     │
                     ├─ cloud.example.com ──▶ privatecloud-app  (Laravel API + React dashboard, FrankenPHP)
                     │                            │
                     │                            ├─ privatecloud-platform-db  (PostgreSQL: PrivateCloud's own data)
                     │                            ├─ privatecloud-redis        (queues, cache, locks)
                     │                            └─ /var/run/docker.sock      (Docker Engine API)
                     │
                     └─ app.example.com ──▶ pc-<project>-<n>  (your application container)
                                                  │  network: pc-net-<project>
                                                  └─ postgres (alias of privatecloud-apps-db, only if the project has a database)

Background:  privatecloud-worker     deployments (one at a time)
             privatecloud-tasks      backups, restores, domain checks, project deletion
             privatecloud-scheduler  metrics, reconciliation, scheduled backups, cleanup
```

The browser only ever talks to the Laravel API. It never reaches Docker, the
PostgreSQL superuser, Caddy's configuration, or GitHub tokens.

## Components

| Component | Image | Role |
| --- | --- | --- |
| `app` | `privatecloud/app` (built from `docker/app/Dockerfile`) | REST API under `/api/v1` and the built React dashboard, served by FrankenPHP on port 8000 (internal only). Runs migrations on start. |
| `worker` | same image | Queue `deployments`: runs the deployment pipeline. Joins every project network so it can health-check new containers. |
| `tasks` | same image | Queue `default`: backups, restores, domain/certificate checks, project deletion, cleanup. |
| `scheduler` | same image | `php artisan schedule:work` — see [Scheduled jobs](#scheduled-jobs). |
| `platform-db` | `postgres:17-alpine` | PrivateCloud's own database. |
| `apps-db` | `postgres:17-alpine` | A **separate** PostgreSQL server for application databases. Its superuser password is only known to the control plane. |
| `redis` | `redis:7-alpine` | Queue backend, cache and distributed locks (AOF persistence on). |
| `caddy` | `caddy:2-alpine` | Edge web server: TLS via Let's Encrypt, dashboard routing, generated per-project sites. |

## Code layout

```
backend/                Laravel 13 (PHP 8.4)
  app/Http/             Controllers (thin), form requests, API resources, middleware
  app/Services/         All behaviour, grouped by concern:
    Docker/             Engine API client (unix socket), helper containers
    Process/            Shell-free command runner with a scrubbed environment
    Source/             GitHub API client, git URL validation, source fetching
    Deployment/         Pipeline, image builds, container launch, health checks, networks, rollback images
    Routing/            Caddyfile rendering and validated reloads
    Domains/            Hostname validation, DNS and certificate checks
    Databases/          PostgreSQL provisioning, table browser, SQL runner
    Backups/            Storage abstraction, backup and restore
    Monitoring/         Host metrics (procfs), container stats, service health, thresholds
    Environment/        Environment variables, .env parsing, log redaction
    Projects/           Project lifecycle, auto deploy, webhooks, deletion
    Audit/              Audit trail
  app/Jobs/             Queued work (deployments, backups, restores, deletion, checks)
  app/Console/Commands  CLI: admin account, reconciler, metrics, scheduled backups, cleanup
frontend/               React 19 + TypeScript + Vite + Tailwind CSS 4
docker/app/             Control-plane image, FrankenPHP config, entrypoint
infrastructure/         Edge Caddyfile, systemd unit
scripts/                install.sh, update.sh, backup-platform.sh, dev-setup.sh
examples/               simple-node-app used for deployment testing
```

## Data model

| Table | Purpose |
| --- | --- |
| `users` | Administrator(s). A `role` column prepares for more users; V1 has one admin. MFA columns are reserved. |
| `servers` | Managed servers. V1 has one row (the local server); every project references it. |
| `projects`, `repositories` | Project settings (build, resources, health check, schedules) and its git source. |
| `deployments`, `deployment_logs` | Every deployment attempt with state, commit, image, timings, failure explanation, and its log lines. |
| `containers` | Containers created by PrivateCloud and their role (candidate, production, retired). |
| `domains` | Hostnames, DNS state and certificate state. |
| `environment_variables` | Encrypted values (Laravel `encrypted` cast, AES-256-CBC with `APP_KEY`). |
| `project_databases` | Application databases on the apps PostgreSQL server; password encrypted. |
| `volumes` | Named Docker volumes and their mount paths. |
| `backups`, `operations` | Backup files with checksum/verification, and long-running operations (restores, deletions). |
| `metrics` | Server and per-project samples (retention configurable, default 3 days). |
| `audit_logs`, `webhook_events`, `notifications`, `settings`, `sql_query_history` | Audit trail, webhook deliveries (unique delivery id), in-app notifications, settings, SQL history. |

## Deployment pipeline

```
queued → cloning → building → starting → health_checking → routing → success
                                                                    ↘ failed / cancelled
```

1. **cloning** – GitHub sources: resolve the branch to a commit with the GitHub API and
   download that exact commit as a tarball (private repos use the stored token).
   Other git URLs: `git clone` with argument lists only.
2. **building** – `docker build` with BuildKit (`--progress=plain`) through the Docker CLI.
   The output is streamed into the deployment log. On failure the log is parsed to name
   the failing Dockerfile step and the most relevant error line.
3. **starting** – A new container `pc-<project>-<n>` is started on the project network
   **next to** the current production container, with memory/CPU/PID limits,
   `no-new-privileges`, dropped capabilities and the project's environment.
4. **health_checking** – HTTP `GET <path>` until a status in the configured range
   (default 200–399) or a "container keeps running" check.
5. **routing** – The project's Caddy site file is rewritten to point at the new container
   and Caddy reloads gracefully (existing connections finish).
6. **success** – Recorded in one transaction. After a short drain period (default 5 s) the
   previous container is stopped and removed; its image is kept for rollback.

Any failure before step 6 removes the new container and leaves production exactly as it
was. Details and guarantees: [deployment.md](deployment.md).

## Concurrency

- Creating a deployment locks the project row; a newer request supersedes deployments
  that are still waiting in the queue.
- Deployment, restore and deletion jobs share a per-project lock
  (`WithoutOverlapping('project:<id>')`), so they never touch the same project at once;
  later jobs wait.
- The `deployments` queue has one worker: builds are serialized across projects. On a
  small VPS this is deliberate — parallel builds compete for the same CPU and RAM.
- Webhook deliveries are de-duplicated by GitHub's delivery id (unique index).
- Deletion marks the project first (`deleting_at`); no new deployments can be created
  after that.

## Networking and isolation

- Each project gets its own bridge network `pc-net-<slug>`. Only Caddy, the deployment
  worker and (if the project has a database) the apps PostgreSQL server join it.
  Projects cannot reach each other's containers directly.
- The platform database, Redis and the API are on `privatecloud-platform`, which
  application containers are not attached to.
- Only Caddy publishes ports (80, 443). Caddy's admin API listens on `localhost` inside
  its container; the control plane reloads Caddy with `docker exec caddy caddy reload`.

**Remaining limitations of a single-server Docker setup** (accepted for V1):

- All application containers share the host kernel. A kernel or Docker escape
  vulnerability in one application would affect the whole server.
- Every project network contains the apps PostgreSQL server. Applications can attempt to
  connect to it, but each role can only connect to its own database (`CONNECT` is revoked
  from `PUBLIC` on every database).
- Applications can reach the internet and anything else the host can reach.
- The control plane has access to the Docker socket, which is root-equivalent on the
  host. It is never exposed to applications or the network.

## Real-time updates

The dashboard polls with TanStack Query: every 1.5–2.5 s while a deployment or backup is
running, 10–30 s otherwise; logs are fetched incrementally (`after_id`, Docker `since`).
Server-sent events or WebSockets would keep a PHP worker busy per open tab; polling is
simpler to operate and cheap at single-admin scale.

## Scheduled jobs

| Every | Job |
| --- | --- |
| minute | worker heartbeat, metrics collection + threshold alerts, reconciler (stale jobs, crashed apps, network repair) |
| 5 minutes | certificate/DNS checks for domains that are not yet active; due scheduled backups |
| hour | prune old metrics |
| day | re-check all certificates; prune failed queue jobs older than 30 days |
| week (Sun 04:30) | cleanup: BuildKit cache older than 7 days, dangling images, stale build directories |

## Decisions

| Decision | Why |
| --- | --- |
| Control plane in containers (not packages on the host) | Reproducible install and upgrades on any Ubuntu version; PHP 8.4 is not in Ubuntu 24.04's repositories. |
| FrankenPHP serves API **and** dashboard | One process, static assets served efficiently; the edge Caddy only proxies. |
| Separate PostgreSQL server for applications | Application credentials never touch the platform database server. |
| Docker Engine HTTP API + Docker CLI only for builds | Structured API calls everywhere; the HTTP build endpoint only offers the legacy builder, which breaks modern Dockerfiles (BuildKit features). |
| GitHub personal access token (not a GitHub App) | A GitHub App needs a public callback during setup and app registration; a fine-grained token works on first boot. The client is isolated so a GitHub App can replace it later. |
| Session cookie + CSRF instead of API tokens | Same-origin SPA; HttpOnly cookies are not readable by scripts. |
| Polling instead of WebSockets | See above. |
| One deployment at a time | Predictable resource use on small servers. |
| Caddy configuration as generated files + reload | Human-readable, survives restarts, and Caddy refuses invalid configuration atomically. |

## Path to multiple servers

Projects, databases and metrics reference a `servers` row. The pieces that talk to a
server are already behind narrow classes (`DockerClient`, `CaddyReloader`,
`HostMetrics`, `BackupStorage`). A multi-node version would add a small agent per server
(or Docker over TLS/SSH), resolve the client per `server_id`, and add a scheduler that
picks a server for new projects. Routing could stay on one edge Caddy or move per server.
None of this is implemented in V1.
