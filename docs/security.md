# Security

PrivateCloud manages a whole server, so its control plane is powerful by design. This
page describes what is protected, how, and what you should do as the administrator.

## Exposure

| What | Reachable from the internet? |
| --- | --- |
| Caddy on 80/443 (dashboard + your domains) | Yes |
| SSH (22) | Yes (firewall rule added by the installer) |
| PrivateCloud API container | Only through Caddy |
| Platform PostgreSQL, application PostgreSQL, Redis | No — internal Docker networks only, no published ports |
| Docker API / socket | No — mounted only into the control-plane containers |
| Caddy admin API | No — `localhost` inside the Caddy container |

The installer enables `ufw` with SSH, HTTP and HTTPS only. Note that Docker manages its
own iptables rules for **published** ports and bypasses `ufw` for them; PrivateCloud
publishes only Caddy's 80/443, and application containers never publish ports.

## Authentication and sessions

- Single administrator account, created on the server with
  `php artisan privatecloud:admin` (the password is read from a prompt or stdin, never a
  command-line argument). There is no registration endpoint.
- Passwords: bcrypt via Laravel's `hashed` cast; minimum 12 characters with letters and
  numbers.
- Sessions: HttpOnly, `Secure`, `SameSite=Strict` cookies, encrypted session payloads,
  stored in the platform database. Changing the password signs out other sessions.
- CSRF: Laravel's double-submit `XSRF-TOKEN` on every state-changing request.
- Rate limits: login 5/min per email+IP and 20/min per IP; API 600/min; sensitive actions
  (reveals, SQL, downloads) 20/min; webhooks 60/min per IP.
- **Re-authentication for secrets**: revealing secret environment values, database
  passwords and webhook secrets, downloading backups, resetting database credentials,
  restoring backups and connecting GitHub require the password to have been entered in the
  last 15 minutes (configurable, `PC_PASSWORD_CONFIRM_MINUTES`).
- MFA is not implemented in V1. The `users` table reserves `mfa_secret` and
  `mfa_enabled_at`, and login is a single controller method so a second factor can be
  added without schema changes.

## Secrets

- Environment variables, database passwords, the GitHub token, webhook secrets and the
  (future) MFA secret are encrypted at rest with Laravel's encrypter (AES-256-CBC + MAC,
  key = `APP_KEY`).
- Secret values are **never** returned by list endpoints; they are revealed one at a time.
- Secret values are redacted (`[secret]`) from build, deployment and application logs
  shown in the dashboard.
- The audit log never stores values; metadata keys such as `password`, `token`, `value`,
  `secret` are replaced with `[redacted]`.
- Subprocesses (git, docker, pg_dump) start with an **empty environment** plus explicit
  variables, so `APP_KEY` and database passwords never leak into them. Passwords for
  `pg_dump`/`pg_restore` go through `PGPASSWORD`, never argv. Docker build arguments are
  passed by name with the value in the environment.
- **Back up `.env`.** Without `APP_KEY` the encrypted data in the platform database cannot
  be decrypted. `scripts/backup-platform.sh` stores a copy next to the platform dump.

## Command and injection safety

- No shell is used to run commands. Every external program is called with an argument
  list (Symfony Process). There is no `shell_exec("docker " . $input)` anywhere.
- Docker is driven through its HTTP API with JSON bodies. The CLI is used only for
  BuildKit builds, with fixed flags.
- User input that reaches infrastructure is validated with allow-lists: branch names,
  `owner/repo`, git URLs (https only; no credentials, custom ports or private/loopback
  addresses — SSRF protection), image references, hostnames (strict pattern, IDN →
  punycode, no wildcards/IPs), mount paths (absolute, no `..`, not system directories),
  Dockerfile path and build context (relative, inside the checkout; resolved with
  `realpath` and checked).
- Caddy configuration is generated only from validated hostnames and internal upstream
  names; Caddy validates the result and keeps its previous configuration on error.
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
  `PUBLIC` on every database, including `postgres` and `template1`.
- Containers run with memory/CPU/PID limits, `no-new-privileges`, and without `NET_RAW`,
  `MKNOD` and `AUDIT_WRITE` capabilities. They never get the Docker socket.

Limitations are listed in [architecture.md](architecture.md#networking-and-isolation).
The most important one: **the control plane can do anything on the server** (it has the
Docker socket). Protect the administrator account accordingly.

## Webhooks

GitHub webhooks are authenticated with HMAC-SHA256 (`X-Hub-Signature-256`, constant-time
comparison) using a per-project random secret; unauthenticated payloads are not stored.
Each delivery id is processed once. Pushes for other repositories or branches are
ignored.

## HTTP security headers

API and dashboard responses send a strict Content-Security-Policy (no inline scripts,
`frame-ancestors 'none'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, and
`Cache-Control: no-store` for API responses. The dashboard site sends HSTS.

## Audit log

Recorded actions include logins and failed logins, project creation/update/deletion,
deployments (started/succeeded/failed/rollback), environment changes and reveals, domain
changes, database creation/deletion/credential reset/reveal, destructive SQL, backups,
restores and downloads, GitHub connection, settings changes. Each entry has the time,
user, resource, IP address, user agent and result.

## Recommendations

1. Use SSH keys and disable password login for SSH (`PasswordAuthentication no`).
2. Enable unattended security upgrades: `apt install unattended-upgrades`.
3. Use a long, unique dashboard password; keep the dashboard domain private if you can.
4. Copy backups (and `.env`) off the server regularly — see [backups.md](backups.md).
5. Use a **fine-grained** GitHub token limited to the repositories you deploy.
6. Review the audit log for failed logins.
7. Keep PrivateCloud updated (`sudo ./scripts/update.sh`).
