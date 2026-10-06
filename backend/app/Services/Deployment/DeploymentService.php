<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creates deployments. All creation goes through one transaction that locks the
 * project row, so concurrent requests (double clicks, duplicate webhooks,
 * deletion) are serialized:
 *  - a project being deleted cannot get new deployments;
 *  - a newer request supersedes deployments that are still waiting in the queue;
 *  - the job itself holds a per-project lock, so only one deployment of a project
 *    runs at a time and later ones wait.
 */
class DeploymentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function deploy(Project $project, ?User $user, string $trigger = 'manual', ?string $commitSha = null): Deployment
    {
        if ($project->source_type !== Project::SOURCE_IMAGE && $project->repository === null) {
            throw new DomainException('Connect a repository before deploying.');
        }

        return $this->create($project, $user, Deployment::TYPE_DEPLOY, $trigger, ['commit_sha' => $commitSha, 'branch' => $project->repository?->branch]);
    }

    public function rollback(Project $project, Deployment $target, ?User $user): Deployment
    {
        if ($target->project_id !== $project->id) {
            throw new DomainException('That deployment belongs to another project.');
        }
        if ($target->status !== DeploymentStatus::Success || ! $target->image_available) {
            throw new DomainException('Only successful deployments whose image is still retained can be rolled back to.');
        }
        if ($project->current_deployment_id === $target->id) {
            throw new DomainException('That deployment is already live.');
        }

        return $this->create($project, $user, Deployment::TYPE_ROLLBACK, 'manual', ['rollback_of_id' => $target->id]);
    }

    /** Recreate the current version with the latest environment variables and settings. */
    public function redeploy(Project $project, ?User $user): Deployment
    {
        $current = $project->currentDeployment;
        if ($current === null) {
            throw new DomainException('There is no live deployment to restart with new settings. Deploy the project first.');
        }

        return $this->create($project, $user, Deployment::TYPE_REDEPLOY, 'manual', ['rollback_of_id' => $current->id]);
    }

    public function cancel(Deployment $deployment): bool
    {
        if (! $deployment->status->isActive()) {
            return false;
        }
        if ($deployment->status === DeploymentStatus::Queued) {
            $updated = Deployment::query()->whereKey($deployment->id)->where('status', DeploymentStatus::Queued->value)
                ->update(['status' => DeploymentStatus::Cancelled->value, 'finished_at' => now(), 'failure_reason' => 'Cancelled by the administrator.']);
            if ($updated) {
                return true;
            }
        }
        // Running: stop at the next safe checkpoint (before the new version receives traffic).
        Cache::put(DeploymentPipeline::cancelKey($deployment), true, now()->addHours(2));

        return true;
    }

    /** @param array<string, mixed> $attributes */
    private function create(Project $project, ?User $user, string $type, string $trigger, array $attributes): Deployment
    {
        $deployment = DB::transaction(function () use ($project, $user, $type, $trigger, $attributes) {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDeleting()) {
                throw new DomainException('The project is being deleted.');
            }

            $number = (int) Deployment::query()->where('project_id', $locked->id)->max('number') + 1;

            Deployment::query()
                ->where('project_id', $locked->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->whereNull('started_at')
                ->update([
                    'status' => DeploymentStatus::Cancelled->value,
                    'finished_at' => now(),
                    'failure_reason' => "Superseded by deployment #{$number}.",
                ]);

            return Deployment::query()->create([
                'project_id' => $locked->id,
                'number' => $number,
                'type' => $type,
                'trigger' => $trigger,
                'status' => DeploymentStatus::Queued,
                'initiated_by' => $user?->id,
                'queued_at' => now(),
                ...$attributes,
            ]);
        });

        RunDeployment::dispatch($deployment->id, $project->id)->afterCommit();

        $this->audit->log('deployment.'.($type === Deployment::TYPE_DEPLOY ? 'started' : $type), $deployment, metadata: [
            'project' => $project->slug, 'number' => $deployment->number, 'trigger' => $trigger,
        ], label: $project->name.' #'.$deployment->number, userId: $user?->id);

        return $deployment;
    }
}
