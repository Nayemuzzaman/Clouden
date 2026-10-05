<?php

namespace App\Services\Routing;

use App\Services\Docker\DockerClient;
use Throwable;

/**
 * Reloads Caddy by executing a fixed command inside the Caddy container. Caddy's
 * admin API stays bound to localhost inside that container and is never reachable
 * from application containers or the network.
 */
class DockerCaddyReloader implements CaddyReloader
{
    public function __construct(private readonly DockerClient $docker) {}

    public function reload(): array
    {
        try {
            $result = $this->docker->exec((string) config('privatecloud.docker.caddy_container'), [
                'caddy', 'reload', '--config', (string) config('privatecloud.caddy.caddyfile'), '--adapter', 'caddyfile',
            ], 90);

            return ['success' => $result['exit_code'] === 0, 'output' => trim($result['output'])];
        } catch (Throwable $e) {
            return ['success' => false, 'output' => 'Could not reach the Caddy container: '.$e->getMessage()];
        }
    }
}
