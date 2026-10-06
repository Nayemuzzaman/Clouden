# Security

PrivateCloud manages a whole server, so its control plane is powerful by design. This
page describes what is protected, how, what is **not** protected, and what you should do
as the administrator.

## Exposure

| What | Reachable from the internet? |
| --- | --- |
| Caddy on 80/443 (dashboard + your domains) | Yes |
| SSH | Yes (every port sshd listens on is allowed by the installer) |
| PrivateCloud API container (port 8000) | No — only through Caddy, on the internal network |
| Platform PostgreSQL, application PostgreSQL, Redis | No — internal Docker networks only, no published ports |
| Docker API | No — local unix socket only, mounted into the control-plane containers |
| Caddy admin API | No — `localhost` inside the Caddy container |
| Application containers | Only through Caddy, for the domains you add |

The installer enables `ufw` with *deny incoming* by default and allows SSH, 80/tcp,
443/tcp and 443/udp. **Docker-published ports bypass `ufw`** (Docker writes its own
iptables rules), so the firewall is not what keeps PostgreSQL or Redis private: they
simply publish no ports. PrivateCloud publishes only Caddy's 80/443, and application
containers never publish ports. `scripts/validate-install.sh` fails if any other
container publishes a port, if the Docker daemon listens on TCP, or if PostgreSQL,
Redis, the API or the Caddy admin port listens on a public address.

If you also use the Vultr firewall, allow the same ports (SSH, 80, 443).

## The Docker socket is a privileged trust boundary

The control plane builds images, starts containers, attaches networks and reloads Caddy
through the Docker socket (`/var/run/docker.sock`). **Access to that socket is equivalent
to root on the server**: anyone who can talk to it can start a privileged container that
mounts the host's filesystem. This is inherent to a Docker-based platform (Railway- or
Coolify-style tools have the same property) and cannot be fully removed without
redesigning the system around a separate privileged agent.

What PrivateCloud does to keep this boundary small:

- The socket is mounted **only** into the four control-plane containers (`app`, `worker`,
  `tasks`, `scheduler`) — never into Caddy, the databases, Redis, helper containers or
  any application container.
- The Docker API is never published on a network port; the validation script checks
  this.
- The control plane runs as an unprivileged user (uid 33) with **all Linux capabilities
  dropped** and `no-new-privileges`. Its only privilege is membership in the Docker
  socket's group. `privatecloud:check-config` refuses to start it as root in production.
- Every Docker call uses the Engine HTTP API with JSON bodies or the docker CLI with a
  fixed argument list (see *Command and injection safety*). User input never becomes a
  shell string, an extra CLI flag or a container option such as `Privileged`, host
  mounts, `CapAdd` or host networking: application containers are always created with
  memory/CPU/PID limits, `no-new-privileges`, a reduced capability set, named volumes
  only and their own network.
- The dashboard requires authentication for every management endpoint; secrets and
  destructive actions additionally require a recent password confirmation.

What this means for you: **an attacker who gains code execution in the control plane
(e.g. through a vulnerability in PrivateCloud, Laravel or PHP) or who takes over the
administrator account controls the server.** Treat the administrator password, the `.env`
file and the server's SSH access with the same care as the root password. Keep
PrivateCloud and Ubuntu updated.

## Authentication and sessions

- Single administrator account, created on the server with
  `php artisan privatecloud:admin` (the password is read from a prompt or stdin, never a
  command-line argument). There is no registration endpoint. Placeholder addresses
  (`example.com`, `localhost`, `.test` …) are refused in production, and an existing
  placeholder account (e.g. from a development database) stops the control plane from
  starting until it is removed.
- Passwords: bcrypt via Laravel's `hashed` cast; minimum 12 characters with letters and
  numbers. Login spends the same hashing time for unknown emails (no account probing by
  timing).
- Sessions: HttpOnly, `Secure`, `SameSite=Strict` cookies, encrypted session payloads,
  stored in the platform database, 8 hours idle lifetime. *Remember me* lasts at most
  14 days (`PC_REMEMBER_DAYS`).
- Changing the password in the dashboard signs out every other session and invalidates
  *remember me* cookies. Resetting it on the command line
  (`privatecloud:admin --reset`) signs out every session.
- CSRF: Laravel's double-submit `XSRF-TOKEN` on every state-changing request. The API is
  same-origin only: no CORS headers are ever sent, so other websites cannot read its
  responses.
- Rate limits: login 5/min per email+IP and 20/min per IP; API 600/min; sensitive actions
  (reveals, SQL, downloads) 20/min; webhooks 60/min per IP.
- **Client IP addresses** (rate limits, audit log): Caddy is the edge and does not trust
  `X-Forwarded-For` from clients; the API trusts only Caddy. If you put a CDN or proxy in
  front of the server, add its address ranges as `trusted_proxies` in
  `infrastructure/caddy/Caddyfile`. Limitation: Docker's userland proxy hands IPv6
  connections to Caddy from the bridge gateway, so all IPv6 clients share one rate-limit
  bucket (they cannot spoof an address, but heavy IPv6 abuse can slow IPv6 logins).
- **Re-authentication for secrets**: revealing secret environment values, database
  passwords and webhook secrets, downloading backups, resetting database credentials,
  restoring backups and connecting GitHub require the password to have been entered in
  the last 15 minutes (`PC_PASSWORD_CONFIRM_MINUTES`).
- MFA is not implemented in V1. The `users` table reserves `mfa_secret` and
  `mfa_enabled_at`, and login is a single controller method so a second factor can be
  added without schema changes.

## Production configuration guard

Every control-plane container runs `php artisan privatecloud:check-config` before it
starts and **refuses to start** when the configuration is unsafe for production:
`APP_ENV` other than `production`, `APP_DEBUG=true`, a missing/short `APP_KEY`, insecure
session cookies, `PC_AUTO_HTTPS=off`, `PC_ALLOW_INSECURE_GIT=true`, database passwords
shorter than 16 characters, a placeholder or local dashboard domain, a non-https
`APP_URL`, running as root, or a development placeholder account. Only
`docker-compose.dev.yml` sets `PC_DEV_MODE=true`, which turns these errors into warnings
— so a development `.env` (created by `scripts/dev-setup.sh`) can never silently become a
production configuration. The installer also refuses to continue with such a `.env`.

## Secrets

- Environment variables, database passwords, the GitHub token, webhook secrets and the
  (future) MFA secret are encrypted at rest with Laravel's encrypter (AES-256-CBC + MAC,
  key = `APP_KEY`). Models never serialize them (`$hidden`).
- Secret values are **never** returned by list endpoints; they are revealed one at a
  time after password confirmation, and each reveal is audited.
- Secret values are redacted (`[secret]`) from build, deployment and application logs
  shown in the dashboard, and from stored deployment failure reasons.
- The container details endpoint returns an allow-list of fields — never Docker's
  `Config.Env`.
- The audit log never stores values; metadata keys such as `password`, `token`, `value`,
  `secret` are replaced with `[redacted]`.
- Subprocesses (git, docker, pg_dump) start with an **empty environment** plus explicit
  variables, so `APP_KEY` and database passwords never leak into them. Passwords for
  `pg_dump`/`pg_restore` go through `PGPASSWORD`, never argv. Docker build arguments are
  passed by name with the value in the environment; names that would change how the
  docker CLI runs (`LD_*`, `DOCKER_*`, `BUILDKIT_*`, `*_PROXY`, `PATH` …) are refused.
- `.env` is created by the installer as `root:root 600` and is excluded from git and from
  the Docker build context. The installer and the scripts never print secret values.
- **Back up `.env` off the server.** Without `APP_KEY` the encrypted data in the platform
  database cannot be decrypted. `scripts/backup-platform.sh` keeps root-only copies next
  to the platform dump — when you copy backups off the server, store them encrypted.

## GitHub credentials

- V1 uses a fine-grained **personal access token** (see [github.md](github.md) for the
  minimum permissions). It is encrypted at rest, used only by the server, and never sent
  to the browser (the Settings page shows only the account name, scopes and status).
- The token is verified with `GET /user` before it is saved. If GitHub later rejects it
  (expired or revoked), the time is recorded, the administrator is notified once and
  Settings shows the problem; deployments fail at *Fetching source* with a clear message
  and the live version keeps running.
- Repository names (`owner/name`), branches and commit SHAs are validated against strict
  patterns before they reach the GitHub API or git.
- A GitHub App (short-lived installation tokens, per-repository permissions) is the
  planned replacement; the client is isolated in `app/Services/Source/GitHubClient.php`.

## Command and injection safety

- No shell is used to run commands. Every external program is called with an argument
  list (Symfony Process). There is no `shell_exec("docker " . $input)` anywhere. Helper
  containers that need `sh -c` use a fixed script and pass values as positional
  parameters.
- Docker is driven through its HTTP API with JSON bodies. The CLI is used only for
  BuildKit builds, with fixed flags; the build context and Dockerfile are resolved with
  `realpath` and must lie inside the checkout (symlinks pointing outside are refused).
- User input that reaches infrastructure is validated with allow-lists:
  - project slugs (`[a-z0-9-]`, generated by the server) and volume names (`[a-z][a-z0-9-]`);
  - branch names, `owner/repo`, commit SHAs;
  - git URLs: https only, no credentials, no custom ports, no private or loopback
    addresses (SSRF protection). git runs with every transport except https disabled,
    which git also enforces for redirects, and without credential helpers;
  - image references;
  - hostnames: strict pattern, IDN → punycode, no wildcards or IPs;
  - mount paths: absolute, no `..`, not system directories;
  - Dockerfile path and build context: relative, inside the checkout.
- Caddy configuration is generated only from validated hostnames and internal upstream
  names. Caddy validates the new configuration before applying it; if it is rejected the
  previous files are restored, so the configuration on disk is always the last one Caddy
  accepted (and Caddy starts with it after a reboot).
- SQL: the table browser binds every value as a parameter; identifiers (schemas, tables,
  columns, sort keys, filters) are checked against the live catalog and quoted. Column
  types and default expressions come from allow-lists. The SQL editor runs as the
  database's own role, never a superuser, with a statement timeout.
- Backup paths are resolved inside the backup root only (path traversal rejected).
- Helper containers (volume backup/restore) run with `--network none`, a fixed command,
  and file names passed as positional arguments.

## Application isolation

- Per-project Docker networks; projects cannot reach each other directly.
- Per-project PostgreSQL role that owns exactly one database; `CONNECT` revoked from
  `PUBLIC` on every database, including `postgres` and `template1`. A new database never
  takes over an existing role or database of the same name.
- Containers run with memory/CPU/PID limits, `no-new-privileges`, and without `NET_RAW`,
  `MKNOD` and `AUDIT_WRITE` capabilities. They never get the Docker socket.
- Resource names cannot collide between projects (volumes include the project id), and
  every Docker object carries the project id and the installation id: restore, deletion
  and cleanup only ever act on the project's own objects.

Limitations are listed in [architecture.md](architecture.md#networking-and-isolation).

## Webhooks

GitHub webhooks are authenticated with HMAC-SHA256 (`X-Hub-Signature-256`, constant-time
comparison) using a per-project random secret; unauthenticated payloads are not stored.
Each delivery is processed once: the delivery id is unique, and so is the SHA-256 of the
signed body per project — a captured delivery replayed with a new delivery id (which
GitHub does not sign) is rejected as a duplicate. Delivery records are kept for a year.
Pushes for other repositories or branches are ignored.

## HTTP security headers

API and dashboard responses send a strict Content-Security-Policy (no inline scripts,
`frame-ancestors 'none'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, and
`Cache-Control: no-store` for API responses. The dashboard site sends HSTS.

## Audit log

Recorded actions include logins and failed logins, project creation/update/deletion,
deployments (started/succeeded/failed/rollback), environment changes and reveals, domain
changes, database creation/deletion/credential reset/reveal, destructive SQL, backups,
restores and downloads, GitHub connection, settings changes, and command-line password
resets and account deletions. Each entry has the time, user, resource, IP address, user
agent and result. Entries are kept for 365 days (`PC_AUDIT_RETENTION_DAYS`).

## Recommendations

1. Use SSH keys and disable password login for SSH (`PasswordAuthentication no`).
2. Keep Ubuntu's unattended security upgrades enabled (default on Ubuntu 24.04).
3. Use a long, unique dashboard password; keep the dashboard domain private if you can.
4. Copy backups (and `.env`, encrypted) off the server regularly — see
   [backups.md](backups.md).
5. Use a **fine-grained** GitHub token limited to the repositories you deploy, with an
   expiry date.
6. Review the audit log for failed logins.
7. Keep PrivateCloud updated (`sudo ./scripts/update.sh`) and run
   `sudo ./scripts/validate-install.sh` after changes.
