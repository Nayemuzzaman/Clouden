# Development

## Run the whole platform locally

Requirements: Docker Desktop (or Docker Engine) with Compose, ~4 GB free RAM.

```bash
./scripts/dev-setup.sh                      # creates .env (plain HTTP, local data dir)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec app php artisan privatecloud:admin
```

The development `.env` (plain HTTP, debug mode, root user) would be refused by the
production configuration check; `docker-compose.dev.yml` sets `PC_DEV_MODE=true` so the
findings are printed as `[dev]` warnings instead. Never use the dev compose file or the
dev `.env` on a server; the installer refuses such a `.env`. Development accounts such as
`admin@example.com` only exist in your local Docker volumes and are refused in
production.

Open <http://localhost:8088>. Projects can use `*.localhost` domains, e.g.
`shop.localhost` → `curl -H 'Host: shop.localhost' http://localhost:8088/`.
In development mode HTTPS is off (`PC_AUTO_HTTPS=off`) and plain-`http://` git URLs and
private addresses are allowed (`PC_ALLOW_INSECURE_GIT=true`) so a local git server can be
used.

### Frontend with hot reload

```bash
cd frontend
npm install
npm run dev        # http://localhost:5173, proxies /api to http://localhost:8080
```

The dev compose file publishes the API container on `127.0.0.1:8080`.

### Backend on the host

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve --port=8080
```

## Tests and static analysis

```bash
# Backend: unit + feature tests (SQLite in memory, Docker/GitHub/Caddy faked)
cd backend && php artisan test

# Backend integration tests against a real PostgreSQL server (provisioning isolation,
# table browser, SQL editor, real pg_dump/pg_restore round trip)
docker run -d --name privatecloud-test-pg -e POSTGRES_PASSWORD=testadminpw -p 127.0.0.1:15499:5432 postgres:17-alpine
PC_TEST_APPS_DB_HOST=127.0.0.1 PC_TEST_APPS_DB_PORT=15499 PC_TEST_APPS_DB_PASSWORD=testadminpw php artisan test
# (needs pg_dump/pg_restore 17 on the host)

./vendor/bin/pint --test          # code style
./vendor/bin/phpstan analyse      # Larastan level 5

# Frontend
cd frontend
npm test                          # Vitest + Testing Library
npm run typecheck                 # TypeScript strict
npm run lint                      # oxlint
npm run build
```

## End-to-end test with real Docker

The deployment engine was verified end to end with the dev stack and a local git server
serving `examples/simple-node-app`:

```bash
# Bare repository served over HTTP on the platform network
git -C examples/simple-node-app init -q && git -C examples/simple-node-app add . && git -C examples/simple-node-app commit -qm init
git clone -q --bare examples/simple-node-app /tmp/gitfixture/app.git && git -C /tmp/gitfixture/app.git update-server-info
docker run -d --name gitfixture --network privatecloud-platform -v /tmp/gitfixture:/srv:ro caddy:2-alpine caddy file-server --root /srv --listen :8000
```

Then create a project with source *Git URL* `http://gitfixture:8000/app.git`, domain
`shop.localhost`, health check `/health`, and deploy. Scenarios covered manually:
first deploy, update with a request loop running (0 failed requests), failed health check
(`FAIL_HEALTHCHECK=1`), failed build (broken Dockerfile), rollback, logs, restart, table
editing confirmed with `psql`, database and volume backup + restore, signed webhook
(valid, forged, duplicate), simultaneous deploy requests, image-source project, project
deletion.

## Production-mode test on your machine

The production compose file can be exercised locally without Let's Encrypt by serving
the dashboard on `https://localhost` with Caddy's internal CA. In a copy of the
repository (container names are fixed, so stop the dev stack first with
`docker compose -f docker-compose.yml -f docker-compose.dev.yml down`), create a
production `.env` (as `scripts/install.sh` would; `PC_DASHBOARD_DOMAIN` must be a real
looking name, `DOCKER_GID` the socket's group — `0` on Docker Desktop) and an override:

```yaml
# e2e.override.yml
services:
  caddy:
    environment:
      PC_DASHBOARD_ADDRESS: "localhost"
    ports: !override
      - "127.0.0.1:8443:443"
      - "127.0.0.1:8081:80"
```

```bash
docker compose -p pcprod -f docker-compose.yml -f e2e.override.yml up -d --build
printf '%s' 'a-long-password-123' | docker exec -i privatecloud-app php artisan privatecloud:admin --email=you@your-domain.dev --password-stdin
curl -k https://localhost:8443/up
```

Do not add project domains in this mode (they would request real certificates).

## Conventions

- Controllers stay thin; behaviour lives in `app/Services`.
- Never build shell strings. Use `CommandRunner` with an argument list, or the Docker API.
- Anything that changes infrastructure is idempotent and safe to re-run.
- New user-facing errors explain what happened and what to do.
- Commits follow `type: summary` (`feat`, `fix`, `test`, `docs`, `refactor`, `chore`).
