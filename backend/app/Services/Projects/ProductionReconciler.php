<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Routing\CaddyConfigurator;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Compares, for one project:
 *
 *   desired commit      head of the production branch (last known)
 *   recorded production the deployment the database says is live
 *   running container   what Docker actually runs for it
 *   Caddy route         the upstream in the project's generated site file
 *
 * and reports every mismatch. Only one repair is ever automatic, because it is
 * non-destructive and restores the recorded truth: re-rendering the Caddy route
 * from the database when it does not point at the recorded production container
 * while that container is running (e.g. the worker died between switching
 * traffic and recording the result). It is applied only while holding the
 * project's deployment lock, so it can never race a deployment that is
 * switching traffic. Everything else is reported for the administrator.
 */
class ProductionReconciler
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly CaddyConfigurator $caddy,
    ) {}

    /**
     * @return array{
     *     project: string,
     *     desired_sha: ?string,
     *     production: array{deployment: ?int, sha: ?string, container: ?string},
     *     container: array{exists: bool, running: bool, deployment_label: ?string},
     *     route: array{expected: ?string, actual: ?string, has_domains: bool},
     *     findings: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function inspect(Project $project): array
    {
        $project->loadMissing(['repository', 'currentDeployment', 'domains']);
        $production = $project->currentDeployment;
        $containerName = $production?->container_name;
        $findings = [];

        $container = ['exists' => false, 'running' => false, 'deployment_label' => null];
        if ($containerName) {
            try {
                $info = $this->docker->inspectContainer($containerName);
            } catch (Throwable) {
                $info = null;
                $findings[] = $this->finding('docker_unreachable', 'warning', 'Docker could not be asked about the production container.');
            }
            if ($info !== null) {
                $container = [
                    'exists' => true,
                    'running' => (bool) ($info['State']['Running'] ?? false),
                    'deployment_label' => $info['Config']['Labels']['privatecloud.deployment'] ?? null,
                ];
            }
        }

        $hasDomains = $project->domains->isNotEmpty();
        $expected = $this->caddy->upstreamFor($project);
        $actual = $hasDomains ? $this->caddy->routedUpstream($project) : null;

        if ($production === null) {
            if ($project->deployments()->where('status', DeploymentStatus::Success->value)->exists()) {
                $findings[] = $this->finding('no_production', 'warning', 'Earlier deployments succeeded but none is recorded as production.');
            }
        } else {
            if ($containerName && ! $container['exists']) {
                $findings[] = $this->finding('container_missing', 'error', "The production container {$containerName} does not exist. Redeploy or roll back to restore it.");
            } elseif ($containerName && ! $container['running'] && $project->status !== ProjectStatus::Stopped) {
                $findings[] = $this->finding('container_stopped', 'error', "The production container {$containerName} is not running.");
            }
            if ($container['deployment_label'] !== null && $container['deployment_label'] !== (string) $production->id) {
                $findings[] = $this->finding('container_mismatch', 'error', "The container {$containerName} belongs to deployment {$container['deployment_label']}, not to the recorded production deployment #{$production->number}.");
            }
        }

        if ($hasDomains && $actual !== $expected) {
            $findings[] = $this->finding('route_mismatch', 'error', 'Traffic is routed to '.($actual ?? 'nothing').' but the recorded production is '.($expected ?? 'not running').'.');
        }

        $desired = $project->repository?->latest_commit_sha;
        if ($desired !== null && $production?->commit_sha !== null && $desired !== $production->commit_sha) {
            $findings[] = $this->finding('out_of_sync', 'info', $project->rolled_back_at
                ? 'Production was intentionally rolled back; the branch head is a different commit.'
                : 'The branch head is a different commit than production.');
        }

        return [
            'project' => $project->slug,
            'desired_sha' => $desired,
            'production' => ['deployment' => $production?->id, 'sha' => $production?->commit_sha, 'container' => $containerName],
            'container' => $container,
            'route' => ['expected' => $expected, 'actual' => $actual, 'has_domains' => $hasDomains],
            'findings' => $findings,
        ];
    }

    /**
     * Apply the safe repair (re-render routing from the recorded state). Returns
     * true when routing was re-synced. Skips projects that are deploying.
     */
    public function repairRouting(Project $project): bool
    {
        $report = $this->inspect($project);
        $mismatch = collect($report['findings'])->contains('code', 'route_mismatch');
        if (! $mismatch || ($report['production']['container'] !== null && ! $report['container']['running'])) {
            return false;
        }

        $lock = Cache::lock(Project::lockName($project->id), 120);
        if (! $lock->get()) {
            return false; // a deployment (or deletion/restore) holds the project: it owns routing right now
        }
        try {
            if ($project->deployments()->whereNotNull('started_at')->whereIn('status', DeploymentStatus::activeValues())->exists()) {
                return false;
            }
            $this->caddy->sync();

            return true;
        } finally {
            $lock->release();
        }
    }

    /** @return array{code: string, severity: string, message: string} */
    private function finding(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }
}
