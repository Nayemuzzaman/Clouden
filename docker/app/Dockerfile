# syntax=docker/dockerfile:1
#
# PrivateCloud control plane image: Laravel API + built dashboard served by
# FrankenPHP. The same image runs the API, the queue workers and the scheduler.

# ---- Dashboard (React) -------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

# ---- PHP dependencies --------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- Docker CLI + BuildKit (buildx) for image builds -------------------------
FROM docker:29-cli AS dockercli

# ---- Runtime -----------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip opcache bcmath

# git for cloning, PostgreSQL 17 client tools (must match the apps database
# server version) for pg_dump/pg_restore, tar for archives.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg git tar gzip; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-17; \
    apt-get purge -y gnupg; apt-get autoremove -y; \
    rm -rf /var/lib/apt/lists/*

COPY --from=dockercli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=dockercli /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-privatecloud.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/entrypoint.sh /usr/local/bin/privatecloud-entrypoint

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=frontend /src/dist/ /app/public/

# The base image gives frankenphp the cap_net_bind_service file capability (for
# ports < 1024). It listens on 8000 here, and the containers run with all
# capabilities dropped, under which the kernel refuses to execute a binary that
# carries file capabilities: remove them.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends --reinstall libcap2-bin; \
    setcap -r /usr/local/bin/frankenphp || true; \
    test -z "$(getcap /usr/local/bin/frankenphp)"; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    chmod +x /usr/local/bin/privatecloud-entrypoint; \
    rm -f /app/.env /app/database/*.sqlite; \
    mkdir -p /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache /home/privatecloud/.docker; \
    chown -R www-data:www-data /app/storage /app/bootstrap/cache /home/privatecloud; \
    mkdir -p /data/caddy /config/caddy && chown -R www-data:www-data /data /config

ENV HOME=/home/privatecloud \
    DOCKER_CONFIG=/home/privatecloud/.docker \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    PC_HOST_PROC=/host/proc \
    LOG_CHANNEL=stderr

EXPOSE 8000
ENTRYPOINT ["privatecloud-entrypoint"]
CMD ["app"]
