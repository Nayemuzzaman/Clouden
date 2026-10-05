<?php

namespace App\Services\Monitoring;

use App\Services\Databases\PostgresProvisioner;
use App\Services\Docker\DockerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ServiceHealth
{
    public const HEARTBEAT_KEY = 'privatecloud:worker-heartbeat';

    public function __construct(
        private readonly DockerClient $docker,
        private readonly PostgresProvisioner $provisioner,
    ) {}

    /** @return list<array{key: string, name: string, status: string, detail: ?string}> */
    public function check(bool $fresh = false): array
    {
        $run = fn () => [
            $this->docker(),
            $this->platformDatabase(),
            $this->appsDatabase(),
            $this->caddy(),
            $this->redis(),
            $this->worker(),
        ];
        if ($fresh) {
            return $run();
        }

        return Cache::remember('privatecloud:service-health', 15, $run);
    }

    private function docker(): array
    {
        try {
            if (! $this->docker->ping()) {
                return $this->status('docker', 'Docker', 'down', 'The Docker daemon is not responding.');
            }
            $info = $this->docker->info();

            return $this->status('docker', 'Docker', 'ok', 'Version '.($info['ServerVersion'] ?? '?').', '.($info['ContainersRunning'] ?? 0).' containers running');
        } catch (Throwable $e) {
            return $this->status('docker', 'Docker', 'down', 'The Docker daemon is not reachable.');
        }
    }

    private function platformDatabase(): array
    {
        try {
            DB::select('select 1');

            return $this->status('platform_db', 'Platform database', 'ok', null);
        } catch (Throwable) {
            return $this->status('platform_db', 'Platform database', 'down', 'Cannot query the platform database.');
        }
    }

    private function appsDatabase(): array
    {
        return $this->provisioner->ping()
            ? $this->status('postgres', 'PostgreSQL (applications)', 'ok', null)
            : $this->status('postgres', 'PostgreSQL (applications)', 'down', 'The application database server is not reachable.');
    }

    private function caddy(): array
    {
        try {
            $info = $this->docker->inspectContainer((string) config('privatecloud.docker.caddy_container'));
            if ($info === null) {
                return $this->status('caddy', 'Caddy (web server)', 'down', 'The Caddy container does not exist.');
            }
            $running = (bool) ($info['State']['Running'] ?? false);
            if (! $running) {
                return $this->status('caddy', 'Caddy (web server)', 'down', 'The Caddy container is not running.');
            }
            // A running container is not enough: Caddy must also answer HTTP.
            try {
                Http::timeout(3)->connectTimeout(2)->withoutRedirecting()->get('http://'.config('privatecloud.caddy.tls_host').'/');
            } catch (Throwable) {
                return $this->status('caddy', 'Caddy (web server)', 'down', 'The Caddy container is running but does not answer HTTP requests.');
            }

            return $this->status('caddy', 'Caddy (web server)', 'ok', 'Serving HTTP/HTTPS');
        } catch (Throwable) {
            return $this->status('caddy', 'Caddy (web server)', 'unknown', 'Cannot inspect Caddy because Docker is not reachable.');
        }
    }

    private function redis(): array
    {
        if (config('queue.default') !== 'redis' && config('cache.default') !== 'redis') {
            return $this->status('redis', 'Redis (queue)', 'ok', 'Not used in this configuration');
        }
        try {
            Redis::connection()->ping();

            return $this->status('redis', 'Redis (queue)', 'ok', null);
        } catch (Throwable) {
            return $this->status('redis', 'Redis (queue)', 'down', 'Redis is not reachable. Deployments and backups cannot be queued.');
        }
    }

    private function worker(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if (! $beat) {
            return $this->status('worker', 'Background worker', 'unknown', 'No heartbeat received yet.');
        }
        $age = now()->timestamp - (int) $beat;
        if ($age > 180) {
            return $this->status('worker', 'Background worker', 'down', 'Background jobs have not been processed for '.round($age / 60).' minutes. Deployments and backups will wait in the queue.');
        }

        return $this->status('worker', 'Background worker', 'ok', 'Last heartbeat '.$age.'s ago');
    }

    private function status(string $key, string $name, string $status, ?string $detail): array
    {
        return ['key' => $key, 'name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
