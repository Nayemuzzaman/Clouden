<?php

/*
|--------------------------------------------------------------------------
| PrivateCloud platform configuration
|--------------------------------------------------------------------------
|
| Every value here can be overridden with an environment variable. Defaults
| are chosen for a single small VPS (1-4 vCPU, 2-8 GB RAM). See
| docs/architecture.md for the reasoning behind each default.
|
*/

return [

    // Product name shown in the UI and notifications. Keep it configurable so the
    // platform can be renamed without code changes.
    'name' => env('PC_NAME', 'PrivateCloud'),

    // Hostname of the dashboard itself (e.g. cloud.example.com). Used to refuse that
    // hostname as a project domain and to build webhook URLs.
    'dashboard_domain' => env('PC_DASHBOARD_DOMAIN'),

    // Public URL GitHub should use to reach webhooks. Defaults to https://<dashboard_domain>.
    'public_url' => env('PC_PUBLIC_URL'),

    // Public IP addresses of this server. Used for DNS checks. When empty the
    // platform tries to detect them (see ServerIdentity).
    'public_ipv4' => env('PC_PUBLIC_IPV4'),
    'public_ipv6' => env('PC_PUBLIC_IPV6'),

    // Absolute path of the data directory. It must be the SAME path inside the
    // platform containers and on the host, because helper containers (volume
    // backups) bind-mount sub-directories of it through the Docker daemon.
    'data_dir' => env('PC_DATA_DIR', storage_path('privatecloud')),

    'docker' => [
        'socket' => env('PC_DOCKER_SOCKET', '/var/run/docker.sock'),
        'api_version' => env('PC_DOCKER_API_VERSION', 'v1.44'),
        'binary' => env('PC_DOCKER_BINARY', 'docker'),
        // Platform containers that the backend needs to talk to.
        'caddy_container' => env('PC_CADDY_CONTAINER', 'privatecloud-caddy'),
        'worker_container' => env('PC_WORKER_CONTAINER', 'privatecloud-worker'),
        'apps_db_container' => env('PC_APPS_DB_CONTAINER', 'privatecloud-apps-db'),
        // Small image used for volume backup/restore helper containers.
        'helper_image' => env('PC_HELPER_IMAGE', 'alpine:3.20'),
        // Prefix for all Docker objects created for projects.
        'prefix' => env('PC_DOCKER_PREFIX', 'pc'),
    ],

    'deploy' => [
        'build_timeout' => (int) env('PC_BUILD_TIMEOUT', 1800),
        'clone_timeout' => (int) env('PC_CLONE_TIMEOUT', 300),
        // Minimum free disk space required before starting a build.
        'min_free_disk_mb' => (int) env('PC_MIN_FREE_DISK_MB', 2048),
        // Seconds to keep the previous container running after traffic was
        // switched, so in-flight requests can finish.
        'drain_seconds' => (int) env('PC_DRAIN_SECONDS', 5),
        // How long a "container" health check must observe the container running.
        'container_stable_seconds' => (int) env('PC_CONTAINER_STABLE_SECONDS', 5),
        // Deployments stuck in an active state longer than this are marked failed.
        'stale_after_seconds' => (int) env('PC_DEPLOY_STALE_AFTER', 3600),
        // Allow http:// and private-network git URLs. Only for local development.
        'allow_insecure_git' => (bool) env('PC_ALLOW_INSECURE_GIT', false),
        'pids_limit' => (int) env('PC_CONTAINER_PIDS_LIMIT', 512),
    ],

    'caddy' => [
        // Directory (shared with the Caddy container) where generated site files live.
        'sites_dir' => env('PC_CADDY_SITES_DIR', env('PC_DATA_DIR', storage_path('privatecloud')).'/caddy/sites'),
        // Path of the same directory as seen from inside the Caddy container.
        'caddyfile' => env('PC_CADDYFILE', '/etc/caddy/Caddyfile'),
        // Access logs written by Caddy (shared volume).
        'logs_dir' => env('PC_CADDY_LOGS_DIR', env('PC_DATA_DIR', storage_path('privatecloud')).'/caddy/logs'),
        'logs_dir_in_caddy' => env('PC_CADDY_LOGS_DIR_IN_CADDY', '/var/log/caddy'),
        // "on": automatic HTTPS via Let's Encrypt. "off": plain HTTP (local development).
        'auto_https' => env('PC_AUTO_HTTPS', 'on'),
        'tls_host' => env('PC_CADDY_TLS_HOST', 'privatecloud-caddy'),
    ],

    'apps_db' => [
        // Host name of the PostgreSQL server that hosts application databases, as seen
        // from the platform and from application containers.
        'host' => env('PC_APPS_DB_HOST', 'privatecloud-apps-db'),
        'app_host_alias' => env('PC_APPS_DB_ALIAS', 'postgres'),
        'port' => (int) env('PC_APPS_DB_PORT', 5432),
        'admin_username' => env('PC_APPS_DB_ADMIN_USER', 'postgres'),
        'admin_password' => env('PC_APPS_DB_ADMIN_PASSWORD'),
        'admin_database' => env('PC_APPS_DB_ADMIN_DATABASE', 'postgres'),
        'statement_timeout_ms' => (int) env('PC_SQL_STATEMENT_TIMEOUT_MS', 30000),
        'sql_max_rows' => (int) env('PC_SQL_MAX_ROWS', 1000),
    ],

    'github' => [
        'api_url' => env('PC_GITHUB_API_URL', 'https://api.github.com'),
        'timeout' => (int) env('PC_GITHUB_TIMEOUT', 15),
    ],

    'monitoring' => [
        // Path of the host's /proc. Inside the platform container the host /proc is
        // bind-mounted read-only at /host/proc.
        'proc_path' => env('PC_HOST_PROC', '/proc'),
        // The control plane runs in a container, so the host name must be passed in.
        'hostname' => env('PC_HOST_HOSTNAME'),
        // Path whose filesystem is reported as "server disk".
        'disk_path' => env('PC_HOST_DISK_PATH', env('PC_DATA_DIR', storage_path('privatecloud'))),
        // Days of metric history to keep. 30s samples * 3 days ~= 8.6k rows/series.
        'retention_days' => (int) env('PC_METRICS_RETENTION_DAYS', 3),
        // Default warning thresholds (percent). Editable in Settings.
        'thresholds' => [
            'cpu' => (int) env('PC_THRESHOLD_CPU', 90),
            'memory' => (int) env('PC_THRESHOLD_MEMORY', 90),
            'disk' => (int) env('PC_THRESHOLD_DISK', 85),
        ],
        // Do not repeat the same resource warning more often than this.
        'alert_cooldown_minutes' => (int) env('PC_ALERT_COOLDOWN_MINUTES', 60),
    ],

    'backups' => [
        'storage' => env('PC_BACKUP_STORAGE', 'local'),
        'local_path' => env('PC_BACKUP_PATH', env('PC_DATA_DIR', storage_path('privatecloud')).'/backups'),
        'timeout' => (int) env('PC_BACKUP_TIMEOUT', 3600),
        // Free disk space that must remain after a backup (the backup's expected
        // size, i.e. the current database/volume size, is added on top).
        'min_free_disk_mb' => (int) env('PC_BACKUP_MIN_FREE_DISK_MB', 1024),
    ],

    'retention' => [
        // Build logs of old deployments, read notifications, finished operations and
        // SQL history older than this are deleted daily. Webhook deliveries are
        // kept as long as the audit log (they protect against replays).
        'history_days' => (int) env('PC_HISTORY_RETENTION_DAYS', 90),
        'audit_days' => (int) env('PC_AUDIT_RETENTION_DAYS', 365),
    ],

    'logs' => [
        'max_lines' => (int) env('PC_LOG_MAX_LINES', 2000),
        'download_max_lines' => (int) env('PC_LOG_DOWNLOAD_MAX_LINES', 20000),
    ],

    'security' => [
        // Minutes a password confirmation stays valid for revealing secrets.
        'password_confirmation_minutes' => (int) env('PC_PASSWORD_CONFIRM_MINUTES', 15),
        // "Remember me" keeps the administrator signed in for at most this many days
        // (Laravel's default would be about 400 days).
        'remember_days' => (int) env('PC_REMEMBER_DAYS', 14),
        // Set only by docker-compose.dev.yml. Turns production configuration errors
        // (debug mode, plain HTTP, insecure git URLs, root user) into warnings.
        'dev_mode' => (bool) env('PC_DEV_MODE', false),
    ],
];
