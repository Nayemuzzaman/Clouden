<?php

namespace App\Services\Deployment;

use App\Models\Container;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Environment\EnvironmentService;

/** Creates application containers with resource limits and hardening applied. */
class ContainerLauncher
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly EnvironmentService $environment,
    ) {}

    public static function containerName(Project $project, Deployment $deployment): string
    {
        return config('privatecloud.docker.prefix').'-'.$project->slug.'-'.$deployment->number;
    }

    public static function volumeName(Project $project, string $volume): string
    {
        return config('privatecloud.docker.prefix').'-vol-'.$project->slug.'-'.$volume;
    }

    public function launch(Project $project, Deployment $deployment, string $image): string
    {
        $name = self::containerName($project, $deployment);

        // A container with this name can only exist from an interrupted attempt of this same deployment.
        if ($this->docker->inspectContainer($name) !== null) {
            $this->docker->removeContainer($name, force: true);
        }

        $mounts = [];
        foreach ($project->volumes as $volume) {
            $this->docker->ensureVolume($volume->docker_name, ['privatecloud.managed' => 'true', 'privatecloud.project' => (string) $project->id]);
            $mounts[] = ['Type' => 'volume', 'Source' => $volume->docker_name, 'Target' => $volume->mount_path];
        }

        $env = [];
        foreach ($this->environment->compile($project) as $key => $value) {
            $env[] = $key.'='.$value;
        }

        $memory = $project->memory_limit_mb * 1024 * 1024;
        $id = $this->docker->createContainer($name, [
            'Image' => $image,
            'Env' => $env,
            'Labels' => [
                'privatecloud.managed' => 'true',
                'privatecloud.project' => (string) $project->id,
                'privatecloud.project_slug' => $project->slug,
                'privatecloud.deployment' => (string) $deployment->id,
            ],
            'ExposedPorts' => [$project->port.'/tcp' => (object) []],
            'HostConfig' => [
                'Memory' => $memory,
                'MemorySwap' => $memory, // no swap beyond the memory limit
                'NanoCpus' => (int) round($project->cpu_limit * 1_000_000_000),
                'PidsLimit' => (int) config('privatecloud.deploy.pids_limit'),
                'RestartPolicy' => ['Name' => 'unless-stopped'],
                'NetworkMode' => $project->networkName(),
                'Mounts' => $mounts,
                'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '10m', 'max-file' => '3']],
                'SecurityOpt' => ['no-new-privileges:true'],
                'CapDrop' => ['NET_RAW', 'MKNOD', 'AUDIT_WRITE'],
            ],
        ]);

        Container::query()->create([
            'server_id' => $project->server_id,
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'docker_id' => $id,
            'name' => $name,
            'image' => $image,
            'role' => Container::ROLE_CANDIDATE,
            'state' => 'created',
        ]);

        $this->docker->startContainer($id);

        return $name;
    }

    public function remove(string $nameOrId): void
    {
        $this->docker->removeContainer($nameOrId, force: true);
        Container::query()->where('name', $nameOrId)->orWhere('docker_id', $nameOrId)->update(['state' => 'removed', 'removed_at' => now()]);
    }

    /** Gracefully stop and remove a container that no longer serves traffic. */
    public function retire(string $nameOrId): void
    {
        $this->docker->stopContainer($nameOrId, 15);
        $this->remove($nameOrId);
        Container::query()->where('name', $nameOrId)->update(['role' => Container::ROLE_RETIRED]);
    }
}
