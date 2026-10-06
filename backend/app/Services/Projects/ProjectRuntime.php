<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Docker\DockerClient;
use App\Services\Routing\CaddyConfigurator;
use DomainException;
use Throwable;

/** Start / stop / restart the live container of a project. */
class ProjectRuntime
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly CaddyConfigurator $caddy,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Project $project): void
    {
        $container = $this->container($project);
        $this->docker->startContainer($container);
        $project->update(['status' => ProjectStatus::Running]);
        $this->syncRouting();
        $this->audit->log('project.started', $project);
    }

    public function stop(Project $project): void
    {
        $container = $this->container($project);
        $this->docker->stopContainer($container, 15);
        $project->update(['status' => ProjectStatus::Stopped]);
        $this->syncRouting();
        $this->audit->log('project.stopped', $project);
    }

    public function restart(Project $project): void
    {
        $container = $this->container($project);
        $this->docker->restartContainer($container, 15);
        $project->update(['status' => ProjectStatus::Running]);
        $this->syncRouting();
        $this->audit->log('project.restarted', $project);
    }

    /** @return array<string, mixed>|null */
    public function inspect(Project $project): ?array
    {
        $name = $project->currentDeployment?->container_name;
        if (! $name) {
            return null;
        }
        try {
            $info = $this->docker->inspectContainer($name);
        } catch (Throwable) {
            return null;
        }
        if ($info === null) {
            return null;
        }

        return [
            'id' => substr((string) $info['Id'], 0, 12),
            'name' => $name,
            'image' => $info['Config']['Image'] ?? null,
            'state' => $info['State']['Status'] ?? 'unknown',
            'running' => (bool) ($info['State']['Running'] ?? false),
            'started_at' => $info['State']['StartedAt'] ?? null,
            'created_at' => $info['Created'] ?? null,
            'restart_count' => (int) ($info['RestartCount'] ?? 0),
            'exit_code' => $info['State']['ExitCode'] ?? null,
            'oom_killed' => (bool) ($info['State']['OOMKilled'] ?? false),
            'ports' => array_keys($info['Config']['ExposedPorts'] ?? []),
            'networks' => array_keys($info['NetworkSettings']['Networks'] ?? []),
        ];
    }

    private function container(Project $project): string
    {
        $active = $project->deployments()->whereIn('status', [
            DeploymentStatus::Starting->value, DeploymentStatus::HealthChecking->value, DeploymentStatus::Routing->value,
        ])->exists();
        if ($active) {
            throw new DomainException('A deployment is switching containers right now. Try again in a moment.');
        }
        if ($project->isDeleting()) {
            throw new DomainException('The project is being deleted.');
        }
        $name = $project->currentDeployment?->container_name;
        if (! $name) {
            throw new DomainException('This project has not been deployed yet.');
        }

        return $name;
    }

    private function syncRouting(): void
    {
        try {
            $this->caddy->sync();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
