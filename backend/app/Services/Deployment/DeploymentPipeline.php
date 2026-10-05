<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use App\Services\Environment\EnvironmentService;
use App\Services\Environment\LogRedactor;
use App\Services\Instance;
use App\Services\Notifier;
use App\Services\Routing\CaddyConfigurator;
use App\Services\Routing\RoutingException;
use App\Services\Source\SourceException;
use App\Services\Source\SourceFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one deployment:
 *
 *   queued → cloning → building → starting → health_checking → routing → success
 *
 * The current production container is never touched until the new container
 * has passed its health check AND traffic has been switched to it. Any failure
 * before that point leaves production exactly as it was.
 */
class DeploymentPipeline
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly DockerClient $docker,
        private readonly SourceFetcher $fetcher,
        private readonly ImageBuilder $builder,
        private readonly ContainerLauncher $launcher,
        private readonly NetworkManager $networks,
        private readonly HealthChecker $health,
        private readonly CaddyConfigurator $caddy,
        private readonly EnvironmentService $environment,
        private readonly ImageRetention $retention,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly Instance $instance,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? fn (int $s) => sleep($s);
    }

    public static function cancelKey(Deployment $deployment): string
    {
        return 'privatecloud:deployment:'.$deployment->id.':cancel';
    }

    public function run(Deployment $deployment): void
    {
        $deployment->refresh();
        if ($deployment->status !== DeploymentStatus::Queued) {
            return; // cancelled, superseded, or already processed
        }

        /** @var Project $project */
        $project = $deployment->project()->with(['repository', 'volumes', 'currentDeployment'])->firstOrFail();
        $log = new DeploymentLogWriter($deployment, new LogRedactor($this->environment->secretValues($project)));

        if ($project->isDeleting()) {
            $deployment->update(['status' => DeploymentStatus::Cancelled, 'finished_at' => now(), 'failure_reason' => 'The project is being deleted.']);

            return;
        }

        $deployment->update(['started_at' => now()]);
        if ($project->current_deployment_id === null) {
            $project->update(['status' => ProjectStatus::Deploying]);
        }
        $log->system("Deployment #{$deployment->number} started ({$deployment->type}, triggered {$deployment->trigger})");

        $buildDir = null;
        $candidate = null;
        $routed = false;
        $succeeded = false;

        try {
            $image = match (true) {
                in_array($deployment->type, [Deployment::TYPE_ROLLBACK, Deployment::TYPE_REDEPLOY], true) => $this->reuseImage($deployment, $log),
                $project->source_type === Project::SOURCE_IMAGE => $this->pullImage($project, $deployment, $log),
                default => $this->buildFromSource($project, $deployment, $log, $buildDir),
            };

            $this->guardCancelled($deployment);

            // ---- Start the candidate next to the running production container
            $this->transition($deployment, DeploymentStatus::Starting, $log, 'Starting new container (the current version keeps serving traffic)');
            $this->networks->prepare($project);
            $candidate = $this->launcher->launch($project, $deployment, $image);
            $info = $this->docker->inspectContainer($candidate);
            $deployment->update(['container_name' => $candidate, 'container_id' => $info['Id'] ?? null]);
            $log->system("Container {$candidate} started with {$project->memory_limit_mb} MB RAM and {$project->cpu_limit} CPU limit");

            // ---- Health check
            $this->transition($deployment, DeploymentStatus::HealthChecking, $log, 'Running health check');
            $result = $this->health->check($project, $candidate, fn (string $line) => $log->write('health', $line));
            if (! $result['healthy']) {
                $this->captureContainerLogs($candidate, $log);
                throw new DeploymentFailed('health_checking', 'Health check failed: '.$result['reason']);
            }

            $this->guardCancelled($deployment);

            // ---- Switch traffic
            $this->transition($deployment, DeploymentStatus::Routing, $log, 'Switching traffic to the new container');
            $previous = $project->currentDeployment;
            if ($project->domains()->exists()) {
                $this->caddy->sync([$project->id => $candidate.':'.$project->port]);
                $routed = true;
                $log->system('Routing updated: '.$project->domains()->pluck('hostname')->implode(', '));
            } else {
                $log->system('No domain configured yet. The application is running but not publicly reachable — add a domain to publish it.', 'warn');
            }

            DB::transaction(function () use ($project, $deployment, $candidate, $previous) {
                $project->update(['current_deployment_id' => $deployment->id, 'status' => ProjectStatus::Running]);
                $deployment->update(['status' => DeploymentStatus::Success, 'finished_at' => now()]);
                Container::query()->where('name', $candidate)->whereNull('removed_at')->update(['role' => Container::ROLE_PRODUCTION, 'state' => 'running']);
                if ($previous?->container_name) {
                    Container::query()->where('name', $previous->container_name)->update(['role' => Container::ROLE_RETIRED]);
                }
            });
            $candidate = null; // it is production now: never clean it up below
            $succeeded = true;
            $log->system("Deployment #{$deployment->number} is live", 'success');

            // ---- Retire the previous container after a short drain period
            if ($previous?->container_name && $previous->container_name !== $deployment->container_name) {
                ($this->sleeper)((int) config('privatecloud.deploy.drain_seconds'));
                try {
                    $this->launcher->retire($previous->container_name);
                    $log->system("Previous container {$previous->container_name} stopped (its image is kept for rollback)");
                } catch (Throwable $e) {
                    $log->system('Could not stop the previous container: '.$e->getMessage(), 'warn');
                }
            }
            $this->retireStrayContainers($project->fresh(), $log);
            $this->retention->prune($project->fresh(), fn (string $l) => $log->system($l));

            $this->audit->log('deployment.succeeded', $deployment, metadata: ['project' => $project->slug, 'number' => $deployment->number, 'commit' => $deployment->shortSha()], label: $project->name.' #'.$deployment->number, userId: $deployment->initiated_by);
            $this->notifier->notify('deployment.succeeded', "{$project->name} deployed", "Deployment #{$deployment->number}".($deployment->shortSha() ? " ({$deployment->shortSha()})" : '').' is live.', 'success', "/projects/{$project->slug}/deployments/{$deployment->id}");
        } catch (DeploymentCancelled) {
            $deployment->update(['status' => DeploymentStatus::Cancelled, 'finished_at' => now(), 'failure_reason' => 'Cancelled by the administrator.']);
            $log->system('Deployment cancelled', 'warn');
            $this->restoreProjectStatus($project);
        } catch (DeploymentFailed $e) {
            $this->fail($project, $deployment, $e->stage, $e->getMessage(), $e->detail, $log, $routed);
        } catch (SourceException $e) {
            $this->fail($project, $deployment, 'cloning', $e->getMessage(), null, $log, $routed);
        } catch (RoutingException $e) {
            $this->fail($project, $deployment, 'routing', $e->getMessage(), null, $log, $routed);
        } catch (Throwable $e) {
            report($e);
            $this->fail($project, $deployment, $deployment->fresh()->status->value, 'Unexpected error: '.$e->getMessage(), null, $log, $routed);
        } finally {
            if ($candidate !== null) {
                try {
                    $this->launcher->remove($candidate);
                    $log->system("Removed failed container {$candidate}; the previous version is still running");
                } catch (Throwable $e) {
                    $log->system("Could not remove container {$candidate}: ".$e->getMessage(), 'warn');
                }
            }
            if ($buildDir !== null) {
                File::deleteDirectory($buildDir);
            }
            if (! $succeeded) {
                $this->removeUnusedBuiltImage($project, $deployment->fresh(), $log);
            }
            Cache::forget(self::cancelKey($deployment));
            $log->flush();
        }
    }

    /** @param-out string $buildDir */
    private function buildFromSource(Project $project, Deployment $deployment, DeploymentLogWriter $log, ?string &$buildDir): string
    {
        $repository = $project->repository;
        if ($repository === null) {
            throw new DeploymentFailed('cloning', 'This project has no repository configured.');
        }

        $this->ensureDiskSpace();
        $this->transition($deployment, DeploymentStatus::Cloning, $log, 'Fetching source code');
        $buildDir = rtrim((string) config('privatecloud.data_dir'), '/').'/builds/deployment-'.$deployment->id;
        File::deleteDirectory($buildDir);

        $commit = $this->fetcher->fetch($repository, $deployment->commit_sha, $buildDir, fn (string $line) => $log->write('system', $line));
        $deployment->update([
            'commit_sha' => $commit->sha,
            'commit_message' => $commit->title(),
            'commit_author' => $commit->author,
            'branch' => $repository->branch,
        ]);
        $log->system("Commit {$commit->shortSha()}: ".($commit->title() ?? ''));

        $this->guardCancelled($deployment);

        [$context, $dockerfile] = $this->resolveBuildPaths($project, $buildDir);

        $this->transition($deployment, DeploymentStatus::Building, $log, 'Building Docker image');
        $deployment->update(['build_started_at' => now()]);
        $tag = config('privatecloud.docker.prefix').'-'.$project->slug.':'.$deployment->number;

        try {
            $result = $this->builder->build(
                $context,
                $dockerfile,
                $tag,
                [...$this->instance->labels($project), 'privatecloud.deployment' => (string) $deployment->id, 'privatecloud.commit' => $commit->sha],
                $this->environment->buildArgs($project),
                fn (string $line) => $log->write('build', $line),
            );
        } catch (InvalidArgumentException $e) {
            throw new DeploymentFailed('building', $e->getMessage());
        }
        $deployment->update(['build_finished_at' => now()]);

        if (! $result['success']) {
            if ($result['timed_out']) {
                throw new DeploymentFailed('building', 'The build exceeded the time limit of '.config('privatecloud.deploy.build_timeout').' seconds.');
            }
            $failure = BuildLogParser::explain($result['lines']);

            throw new DeploymentFailed('building', $failure->summary, json_encode(['step' => $failure->step, 'excerpt' => $failure->excerpt]) ?: null);
        }

        $image = $this->docker->inspectImage($tag);
        if ($image === null) {
            throw new DeploymentFailed('building', 'The build reported success but the image could not be found.');
        }
        $deployment->update(['image_tag' => $tag, 'image_id' => $image['Id'] ?? null, 'image_available' => true]);
        $log->system('Image built: '.$tag.' ('.substr((string) ($image['Id'] ?? ''), 7, 12).')');

        return $tag;
    }

    private function pullImage(Project $project, Deployment $deployment, DeploymentLogWriter $log): string
    {
        $ref = (string) $project->image;
        [$name, $tag] = ImageReference::split($ref);
        $this->ensureDiskSpace();
        $this->transition($deployment, DeploymentStatus::Building, $log, "Pulling image {$ref}");
        $deployment->update(['build_started_at' => now()]);
        try {
            $this->docker->pullImage($name, $tag, fn (string $line) => $log->write('build', $line));
        } catch (DockerException $e) {
            throw new DeploymentFailed('building', 'Could not pull the image: '.$e->getMessage());
        }
        $deployment->update(['build_finished_at' => now()]);
        $image = $this->docker->inspectImage($ref);
        $deployment->update(['image_tag' => $ref, 'image_id' => $image['Id'] ?? null, 'image_available' => true]);

        return $ref;
    }

    private function reuseImage(Deployment $deployment, DeploymentLogWriter $log): string
    {
        $source = $deployment->rollbackOf;
        if ($source === null || ! $source->image_tag) {
            throw new DeploymentFailed('starting', 'The deployment to roll back to no longer exists.');
        }
        if ($source->status !== DeploymentStatus::Success && $deployment->type === Deployment::TYPE_ROLLBACK) {
            throw new DeploymentFailed('starting', "Deployment #{$source->number} did not succeed, so it cannot be rolled back to.");
        }
        $image = $this->docker->inspectImage($source->image_tag);
        if ($image === null) {
            $source->update(['image_available' => false]);

            throw new DeploymentFailed('starting', "The image of deployment #{$source->number} has been removed (image retention), so it cannot be reused. Deploy the commit again instead.");
        }
        // The tag must still point at the exact image that passed its health check.
        if ($source->image_id && ($image['Id'] ?? null) !== $source->image_id) {
            throw new DeploymentFailed('starting', "The image tagged {$source->image_tag} is no longer the image deployed by #{$source->number}, so it was not used. Deploy the commit again instead.");
        }
        $deployment->update([
            'image_tag' => $source->image_tag,
            'image_id' => $source->image_id,
            'image_available' => true,
            'commit_sha' => $source->commit_sha,
            'commit_message' => $source->commit_message,
            'commit_author' => $source->commit_author,
            'branch' => $source->branch,
        ]);
        $log->system(($deployment->type === Deployment::TYPE_ROLLBACK ? 'Rolling back' : 'Redeploying').
            " to the image of deployment #{$source->number}".($source->shortSha() ? " (commit {$source->shortSha()})" : ''));

        return (string) $source->image_tag;
    }

    /** @return array{0: string, 1: string} absolute context dir and Dockerfile path, both inside $buildDir */
    private function resolveBuildPaths(Project $project, string $buildDir): array
    {
        $root = realpath($buildDir);
        $context = realpath($buildDir.'/'.ltrim($project->build_context ?: '.', '/'));
        if ($root === false || $context === false || ! is_dir($context) || ! str_starts_with($context.'/', $root.'/')) {
            throw new DeploymentFailed('building', "The build context \"{$project->build_context}\" does not exist in the repository.");
        }
        $dockerfilePath = $buildDir.'/'.ltrim($project->dockerfile_path ?: 'Dockerfile', '/');
        $dockerfile = realpath($dockerfilePath);
        if ($dockerfile === false || ! is_file($dockerfile) || ! str_starts_with($dockerfile, $root.'/')) {
            $hint = FrameworkDetector::hintFor($buildDir);

            throw new DeploymentFailed('building', "No Dockerfile found at \"{$project->dockerfile_path}\". PrivateCloud deploys applications that include a Dockerfile.".($hint ? ' '.$hint : ''));
        }

        return [$context, $dockerfile];
    }

    private function ensureDiskSpace(): void
    {
        $dir = (string) config('privatecloud.data_dir');
        File::ensureDirectoryExists($dir);
        $free = @disk_free_space($dir);
        $required = (int) config('privatecloud.deploy.min_free_disk_mb') * 1024 * 1024;
        if ($free !== false && $free < $required) {
            throw new DeploymentFailed('cloning', sprintf(
                'Not enough free disk space to build (%.1f GB free, %.1f GB required). Remove old backups or images and try again.',
                $free / 1073741824,
                $required / 1073741824,
            ));
        }
    }

    private function transition(Deployment $deployment, DeploymentStatus $status, DeploymentLogWriter $log, string $message): void
    {
        $deployment->update(['status' => $status]);
        $log->system($message);
    }

    private function guardCancelled(Deployment $deployment): void
    {
        if (Cache::get(self::cancelKey($deployment))) {
            throw new DeploymentCancelled;
        }
    }

    private function captureContainerLogs(string $container, DeploymentLogWriter $log): void
    {
        try {
            $lines = $this->docker->containerLogs($container, 100, timestamps: false);
            $log->system('Last container output:');
            foreach ($lines as $line) {
                $log->write('container', $line['line'], $line['stream'] === 'stderr' ? 'error' : 'info');
            }
        } catch (Throwable) {
            $log->system('Container logs are not available.', 'warn');
        }
    }

    private function fail(Project $project, Deployment $deployment, string $stage, string $reason, ?string $detail, DeploymentLogWriter $log, bool $routed): void
    {
        if ($routed) {
            // Traffic had been switched but the deployment could not be recorded: point it back.
            try {
                $this->caddy->sync();
            } catch (Throwable $e) {
                $log->system('Could not restore routing: '.$e->getMessage(), 'error');
            }
        }

        // Error messages can echo build output or application output: never store secret values.
        $reason = $log->redact($reason);
        $deployment->update([
            'status' => DeploymentStatus::Failed,
            'failure_stage' => $stage,
            'failure_reason' => mb_substr($reason, 0, 2000),
            'failure_detail' => $detail !== null ? $log->redact($detail) : null,
            'finished_at' => now(),
        ]);
        $log->system('Deployment failed: '.$reason, 'error');
        if ($project->current_deployment_id) {
            $log->system("Deployment #{$project->currentDeployment?->number} is still serving traffic.");
        }

        $this->restoreProjectStatus($project);
        $this->audit->log('deployment.failed', $deployment, 'failure', ['project' => $project->slug, 'number' => $deployment->number, 'stage' => $stage], $project->name.' #'.$deployment->number, $deployment->initiated_by);
        $this->notifier->notify('deployment.failed', "{$project->name} deployment failed", "Deployment #{$deployment->number} failed while ".str_replace('_', ' ', $stage).': '.mb_substr($reason, 0, 200), 'error', "/projects/{$project->slug}/deployments/{$deployment->id}");
    }

    private function restoreProjectStatus(Project $project): void
    {
        $project->refresh();
        if ($project->status === ProjectStatus::Deploying || $project->current_deployment_id === null) {
            $project->update(['status' => $project->current_deployment_id ? ProjectStatus::Running : ProjectStatus::Failed]);
        }
    }

    /**
     * A failed deployment's freshly built image can never be rolled back to, so it
     * is removed right away (Docker refuses if a container still uses it). Images
     * of other deployments (rollback/redeploy reuse them) and pulled images are
     * never touched.
     */
    private function removeUnusedBuiltImage(Project $project, ?Deployment $deployment, DeploymentLogWriter $log): void
    {
        if ($deployment === null || $deployment->type !== Deployment::TYPE_DEPLOY || $project->source_type === Project::SOURCE_IMAGE
            || ! $deployment->image_tag || ! $deployment->image_available) {
            return;
        }
        $expected = config('privatecloud.docker.prefix').'-'.$project->slug.':'.$deployment->number;
        if ($deployment->image_tag !== $expected) {
            return;
        }
        try {
            $this->docker->removeImage($deployment->image_tag);
            $deployment->update(['image_available' => false]);
            $log->system("Removed the image of this failed deployment ({$deployment->image_tag})");
        } catch (Throwable) {
            // In use or already gone; image retention cleans it up later.
        }
    }

    /** Remove leftover containers of this project that are neither production nor in use. */
    private function retireStrayContainers(Project $project, DeploymentLogWriter $log): void
    {
        $current = $project->currentDeployment?->container_name;
        try {
            $containers = $this->docker->listContainers(['label' => ['privatecloud.project='.$project->id]]);
        } catch (Throwable) {
            return;
        }
        foreach ($containers as $container) {
            $name = ltrim((string) ($container['Names'][0] ?? ''), '/');
            if ($name === '' || $name === $current) {
                continue;
            }
            if (($container['Labels']['privatecloud.helper'] ?? null) === 'true' || ! $this->instance->owns($container['Labels'] ?? [])) {
                continue; // helper, or left on this host by another PrivateCloud installation
            }
            try {
                $this->launcher->retire($name);
                $log->system("Removed old container {$name}");
            } catch (Throwable) {
                // best effort
            }
        }
    }
}
