<?php

namespace App\Services\Deployment;

use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use Illuminate\Support\Facades\Log;

/**
 * Each project gets its own bridge network. Only the platform components that
 * must reach the application join it:
 *  - Caddy (to proxy traffic),
 *  - the deployment worker (to run HTTP health checks),
 *  - the applications PostgreSQL server, only when the project has a database
 *    (with the alias "postgres").
 * Projects therefore cannot reach each other's containers directly.
 */
class NetworkManager
{
    public function __construct(private readonly DockerClient $docker) {}

    public function prepare(Project $project): void
    {
        $network = $project->networkName();
        $this->docker->ensureNetwork($network, [
            'privatecloud.managed' => 'true',
            'privatecloud.project' => (string) $project->id,
        ]);

        $this->attach($network, (string) config('privatecloud.docker.caddy_container'));
        $this->attach($network, (string) config('privatecloud.docker.worker_container'));

        if ($project->databases()->exists()) {
            $this->attach($network, (string) config('privatecloud.docker.apps_db_container'), [(string) config('privatecloud.apps_db.app_host_alias')]);
        }
    }

    public function attachDatabase(Project $project): void
    {
        $network = $project->networkName();
        $this->docker->ensureNetwork($network, ['privatecloud.managed' => 'true', 'privatecloud.project' => (string) $project->id]);
        $this->attach($network, (string) config('privatecloud.docker.apps_db_container'), [(string) config('privatecloud.apps_db.app_host_alias')]);
    }

    public function detachDatabase(Project $project): void
    {
        try {
            $this->docker->disconnectNetwork($project->networkName(), (string) config('privatecloud.docker.apps_db_container'));
        } catch (DockerException $e) {
            Log::warning('Could not detach database from project network', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }

    public function remove(Project $project): void
    {
        $network = $project->networkName();
        foreach (['caddy_container', 'worker_container', 'apps_db_container'] as $key) {
            try {
                $this->docker->disconnectNetwork($network, (string) config('privatecloud.docker.'.$key));
            } catch (DockerException) {
                // not connected
            }
        }
        $this->docker->removeNetwork($network);
    }

    /** @param list<string> $aliases */
    private function attach(string $network, string $container, array $aliases = []): void
    {
        if ($container === '' || $this->docker->inspectContainer($container) === null) {
            return; // component not running as a container (e.g. local development)
        }
        if (in_array($network, $this->docker->containerNetworks($container), true)) {
            return;
        }
        $this->docker->connectNetwork($network, $container, $aliases);
    }
}
