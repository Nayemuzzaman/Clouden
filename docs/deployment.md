# Deploying applications

PrivateCloud deploys **any application that has a Dockerfile**. The Dockerfile is the
contract: if `docker build` works on your machine, it works on PrivateCloud.

## The contract

Your container must:

1. **Listen on `0.0.0.0`** (not `127.0.0.1`) on the port configured in the project
   (default `3000`). PrivateCloud also sets the `PORT` environment variable to that value.
2. **Answer the health check** — by default `GET /` must return a status from 200 to 399.
   A dedicated endpoint such as `/health` that checks the app's dependencies is better;
   set it under *Settings → Health check*.
3. **Write persistent data only to volumes.** Everything else in the container is replaced
   on each deployment. Add volumes under *Storage* (for example `/app/storage`).
4. **Log to stdout/stderr.** That is what *Logs → Application* shows.
5. **Exit cleanly on `SIGTERM`.** Old containers get 15 seconds to stop.

## What happens when you click *Deploy Latest*

| Stage | What happens | If it fails |
| --- | --- | --- |
| Queued | The deployment waits for the worker and for any other job on the same project. A newer deploy request replaces one that has not started yet. | — |
| Fetching source | The branch is resolved to a commit and that exact commit is downloaded. | "Repository not found", "token rejected", "GitHub could not be reached" … |
| Building | `docker build` with BuildKit. Output is streamed live. Before building, at least 2 GB of free disk is required (configurable). | The failing step and error line are shown, e.g. *Step: `npm run build` — Error: Module not found*. |
| Starting | A new container starts **next to** the live one, with the project's limits and environment. | Removed (with the image this deployment built); live version untouched. |
| Health checking | HTTP request (or "container keeps running") with retries. If the process exits or restarts, the check stops immediately. | The container's last 100 log lines are attached; the container is removed. |
| Routing | Caddy is pointed at the new container and reloaded gracefully. If Caddy rejects the configuration, the previous configuration is restored. | Live version untouched. |
| Successful | The previous container is stopped after a 5 second drain period. Its image is kept for rollback. | — |

If the deployment worker is restarted or killed during any stage (server update, reboot,
out of memory), the deployment is marked *failed — interrupted* as soon as the worker is
back, the half-started container is removed and the live version is untouched. Deploy
again.

**About zero downtime.** The previous version keeps serving until the new one is healthy
and routed, and Caddy's reload lets in-flight requests finish. In testing, 346 requests
made during a switch all succeeded. PrivateCloud does **not** guarantee zero downtime:
long-running requests (over ~5 s), WebSockets and in-memory sessions on the old container
are cut when it stops, and database migrations that are incompatible with the old version
can break it before the switch. Prefer backwards-compatible migrations.

## Rollback

*Deployments → Rollback* on any successful deployment whose image is still retained
creates a new deployment of type **rollback** that reuses that image (no rebuild), health
checks it and switches traffic. The image must still be the exact one (same image id)
that passed its health check; if the tag was overwritten, the rollback is refused. History is never rewritten. By default the images of the
last 5 successful deployments are kept (*Settings → Resources → Images kept for
rollback*). Rollback does not roll back your **database** — restore a backup for that.

## Redeploy

Environment variables, volumes and resource limits are applied when a container is
created. After changing them, use **Redeploy** (shown as a banner) to start the current
version again with the new settings, using the same safe pipeline.

## Environment variables at build time

Variables are passed to the running container. Tools like Vite or Next.js need some
values **at build time**: mark those with **B** on the Environment page and declare them
in your Dockerfile with `ARG NAME`. Values are handed to Docker through the process
environment (never on the command line), but anything you `ARG` can end up in image
metadata — do not mark secrets for build time.

Names that would change how the build tooling itself runs cannot be used at build time:
`PATH`, `HOME`, `LD_*`, `DOCKER_*`, `BUILDKIT_*`, `BUILDX_*`, `GIT_*`, `SSL_*`,
`*_PROXY`, `GODEBUG` and similar. They remain available to the running application.

## Resource limits

Each container gets a memory limit (no extra swap), a CPU limit (in cores), and a limit
of 512 processes. A container that exceeds its memory is killed by the kernel and
restarted by Docker; the project shows as *Crashed* until it is running again, and the
container details show *killed for exceeding the memory limit*.

## Starter Dockerfiles

These are starting points; adjust versions and commands to your project.

**Node.js (Express, Fastify, …)**

```dockerfile
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev
COPY . .
ENV NODE_ENV=production
USER node
EXPOSE 3000
CMD ["node", "server.js"]
```

**Next.js** (with `output: "standalone"` in `next.config.js`)

```dockerfile
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production HOSTNAME=0.0.0.0
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public
USER node
EXPOSE 3000
CMD ["node", "server.js"]
```

**React / Vite static site** (set the project port to `80`)

```dockerfile
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
# Single-page app: send unknown paths to index.html
RUN printf 'server { listen 80; root /usr/share/nginx/html; location / { try_files $uri /index.html; } }' > /etc/nginx/conf.d/default.conf
EXPOSE 80
```

**Laravel** (FrankenPHP; set port `8000`, health check `/up`, add a volume at
`/app/storage` and create a PostgreSQL database for the project)

```dockerfile
FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_pgsql intl zip opcache pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache
ENV SERVER_NAME=:8000
EXPOSE 8000
# Run migrations, then start the server.
CMD ["sh", "-c", "php artisan migrate --force && php artisan config:cache && frankenphp run --config /etc/frankenphp/Caddyfile"]
```

Set `APP_KEY`, `APP_ENV=production`, `APP_URL` and `LOG_CHANNEL=stderr` in the
environment. `DB_*` variables are added automatically when the project has a database.

**Python (FastAPI / Flask with gunicorn)**

```dockerfile
FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt gunicorn
COPY . .
USER nobody
EXPOSE 3000
CMD ["sh", "-c", "gunicorn -b 0.0.0.0:${PORT:-3000} app:app"]
```

When a deployment fails because no Dockerfile exists, PrivateCloud inspects the
repository (`package.json`, `composer.json`, `requirements.txt`, …) and tells you which
of these templates fits. Automatic Dockerfile generation is intentionally not done in V1.

## Deploying an existing image

Choose **Docker image** as the source (for example `nginx:alpine` or
`ghcr.io/owner/app:1.4`). PrivateCloud pulls the image on each deploy. Only public images
are supported in V1.

## Example application

[`examples/simple-node-app`](../examples/simple-node-app) is a dependency-free app used to
test the platform. It has a health endpoint and switches to simulate failures
(`FAIL_HEALTHCHECK=1`, `CRASH_ON_START=1`).
