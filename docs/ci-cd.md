# CI/CD

Two GitHub Actions workflows live in `.github/workflows/`.

## CI — every pull request and every push to `main`

[`ci.yml`](../.github/workflows/ci.yml) runs four jobs. The first three run in parallel;
the smoke test runs when they pass.

| Job | What it checks |
| --- | --- |
| **Backend** | Pint (code style), Larastan level 5, and the full PHPUnit suite — unit, feature, and integration tests against a real PostgreSQL 17 service (database isolation, refusal to adopt existing roles, real `pg_dump`/`pg_restore` round trip). |
| **Frontend** | oxlint, strict TypeScript, Vitest + Testing Library, production build. |
| **Scripts and installer** | ShellCheck on every script; `scripts/install.sh` guard rails in an Ubuntu 24.04 container ([`.github/ci/installer-guards.sh`](../.github/ci/installer-guards.sh): placeholder domain/email, invalid input, development `.env` refused and left untouched, non-root, unsupported Ubuntu); all compose files validate. |
| **Production-mode smoke test** | Builds the control-plane image and starts `docker-compose.yml` exactly as on a server (uid 33, all capabilities dropped, configuration guard), with the dashboard on `https://localhost` from Caddy's internal CA so nothing contacts Let's Encrypt ([`.github/ci/smoke-test.sh`](../.github/ci/smoke-test.sh)). |

The smoke test verifies, against real Docker:

1. control-plane hardening (uid, capabilities, `no-new-privileges`), only Caddy publishes
   ports, the production configuration check, service health;
2. a placeholder administrator is refused, a real one is created via stdin;
3. HSTS, CSP, no CORS headers, `Secure`/`HttpOnly`/`SameSite=Strict` session cookie,
   unknown hostnames get 404, spoofed `X-Forwarded-For` cannot bypass the login limit;
4. a real deployment: `examples/simple-node-app` is **cloned from the branch under test on
   GitHub**, built with its Dockerfile, started, health-checked and
   reachable; it has a database, a volume and a secret variable whose value never appears
   in API responses or logs;
5. the deployment worker is killed mid-deployment: the deployment is marked interrupted,
   the live version keeps answering, and the next deployment succeeds;
6. database and volume backups complete and are verified;
7. the whole control plane is re-created (like a reboot or update): services are healthy
   and the application is reachable again.

To require CI before merging: *Settings → Branches → Add branch protection rule* for
`main` → *Require status checks to pass* → select the four CI jobs.

Run the same checks locally:

```bash
cd backend && php artisan test && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm test && npm run build
shellcheck -S warning scripts/*.sh docker/app/entrypoint.sh .github/ci/*.sh
docker run --rm -v "$PWD:/src:ro" ubuntu:24.04 bash /src/.github/ci/installer-guards.sh
# smoke test: needs the image and NO running PrivateCloud stack on this machine
docker build -t privatecloud/app:local -f docker/app/Dockerfile .
REPO_URL=https://github.com/Nayemuzzaman/Clouden.git BRANCH=main .github/ci/smoke-test.sh
docker compose -p pcsmoke -f docker-compose.yml -f .github/ci/compose.smoke.yml down -v && rm .env
```

## CD — updating your PrivateCloud server

[`deploy.yml`](../.github/workflows/deploy.yml) updates an **existing** PrivateCloud
server (installed with `scripts/install.sh`) by running
[`scripts/update.sh`](../scripts/update.sh) over SSH. That script refuses local changes,
waits until no deployment/backup/restore is running, takes a verified platform backup,
builds the new version while the old one keeps running, restarts the control plane and
fails with rollback instructions if it is not healthy. Your applications keep running.

This workflow updates **PrivateCloud itself**. Your applications are deployed by
PrivateCloud (Deploy Latest, or auto deploy on `git push` via webhooks), not by this
workflow.

It runs:

- **manually**: *Actions → Deploy → Run workflow*, type `deploy`;
- **automatically** after CI succeeds on a push to `main`, only if the repository variable
  `AUTO_DEPLOY` is `true`. Leave it off until the [first server
  test](first-server-test.md) has passed.

The server updates to the latest commit of the branch its clone tracks (normally `main`),
whatever branch the workflow was started from.

### One-time setup

1. **A deploy user on the server** that may run only the update script as root:

   ```bash
   sudo adduser --disabled-password --gecos "" deploy
   sudo install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
   ssh-keygen -t ed25519 -N "" -C github-actions-deploy -f deploy_key   # on your workstation
   # put deploy_key.pub into /home/deploy/.ssh/authorized_keys (owner deploy, mode 600)
   echo 'deploy ALL=(root) NOPASSWD: /opt/privatecloud/scripts/update.sh' | sudo tee /etc/sudoers.d/privatecloud-deploy
   sudo chmod 440 /etc/sudoers.d/privatecloud-deploy && sudo visudo -c
   ```

2. **The server's host key**, so the workflow never trusts an unknown host. From a machine
   you trust: `ssh-keyscan -t ed25519 <server-ip>` and compare the fingerprint with the one
   shown on the server (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`).

3. **GitHub → Settings → Environments → New environment `production`.** Optionally add
   *Required reviewers* so every deployment waits for your approval, and restrict it to
   the `main` branch. Add to the environment (or repository):

   | Kind | Name | Value |
   | --- | --- | --- |
   | Secret | `DEPLOY_HOST` | server IP or hostname |
   | Secret | `DEPLOY_USER` | `deploy` |
   | Secret | `DEPLOY_SSH_KEY` | the private key `deploy_key` |
   | Secret | `DEPLOY_KNOWN_HOSTS` | the `ssh-keyscan` line from step 2 |
   | Variable | `DASHBOARD_URL` | `https://cloud.example.com` (checked after the update) |
   | Variable | `DEPLOY_PATH` | optional, default `/opt/privatecloud` |
   | Variable | `AUTO_DEPLOY` | optional, `true` to deploy after every green CI on `main` |

If a secret is missing, the workflow stops with a message naming it. The private key is
written to the runner only for the duration of the job and removed afterwards; nothing
from the server's `.env` ever leaves the server.
