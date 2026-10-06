<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Source\CommitInfo;
use App\Services\Source\SourceFetcher;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creates deployments. Manual "Deploy Latest", GitHub webhooks, rollbacks and
 * redeploys all come through here and run through the same DeploymentPipeline.
 *
 * Creation happens in one transaction that locks the project row, so concurrent
 * requests (double clicks, a push arriving while the administrator clicks Deploy
 * Latest, duplicate webhooks, deletion) are serialized:
 *  - every source deployment is tied to an exact commit SHA when it is created;
 *  - a request for a commit that is already being deployed reuses that deployment;
 *  - at most ONE deployment waits per project: a newer request supersedes a waiting
 *    one that has not started (a started deployment is never skipped);
 *  - the job holds a per-project lock, so one deployment runs at a time.
 */
class DeploymentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SourceFetcher $fetcher,
    ) {}

    /**
     * "Deploy Latest": read the head of the production branch now and deploy
     * exactly that commit. Throws AlreadyLive when production already runs it
     * (unless $force), and SourceException when GitHub cannot be read.
     */
    public function deployLatest(Project $project, ?User $user, bool $force = false): QueuedDeployment
    {
        if ($project->isDeleting()) {
            throw new DomainException('The project is being deleted.');
        }
        if ($project->source_type === Project::SOURCE_IMAGE) {
            return $this->create($project, $user, Deployment::TYPE_DEPLOY, 'manual', []);
        }
        $repository = $project->repository ?? throw new DomainException('Connect a repository before deploying.');
        $commit = $this->fetcher->latestCommit($repository);

        $production = $project->currentDeployment;
        if (! $force && $production !== null && $production->commit_sha === $commit->sha) {
            throw new AlreadyLive($production, $commit);
        }

        return $this->deployCommit($project, $user, 'manual', $commit);
    }

    /** Deploy one exact commit of the production branch (manual SHA, webhook push, retry). */
    public function deployCommit(Project $project, ?User $user, string $trigger, CommitInfo $commit, ?string $deliveryId = null): QueuedDeployment
    {
        $repository = $project->repository ?? throw new DomainException('Connect a repository before deploying.');

        return $this->create($project, $user, Deployment::TYPE_DEPLOY, $trigger, [
            'commit_sha' => $commit->sha,
            'commit_message' => $commit->title(),
            'commit_author' => $commit->author,
            'commit_committed_at' => $commit->committedAt,
            'branch' => $repository->branch,
            'repository' => $repository->displayName(),
            'source_visibility' => $repository->visibility,
            'webhook_delivery_id' => $deliveryId,
        ]);
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

        return $this->create($project, $user, Deployment::TYPE_ROLLBACK, 'manual', [
            'rollback_of_id' => $target->id, 'branch' => $target->branch, 'repository' => $target->repository, 'source_visibility' => $target->source_visibility,
        ])->deployment;
    }

    /** Recreate the current version with the latest environment variables and settings. */
    public function redeploy(Project $project, ?User $user): Deployment
    {
        $current = $project->currentDeployment;
        if ($current === null) {
            throw new DomainException('There is no live deployment to restart with new settings. Deploy the project first.');
        }

        return $this->create($project, $user, Deployment::TYPE_REDEPLOY, 'manual', [
            'rollback_of_id' => $current->id, 'branch' => $current->branch, 'repository' => $current->repository, 'source_visibility' => $current->source_visibility,
        ])->deployment;
    }

    public function cancel(Deployment $deployment): bool
    {
        if (! $deployment->status->isActive()) {
            return false;
        }
        if ($deployment->status === DeploymentStatus::Queued) {
            $updated = Deployment::query()->whereKey($deployment->id)->where('status', DeploymentStatus::Queued->value)->whereNull('started_at')
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
    private function create(Project $project, ?User $user, string $type, string $trigger, array $attributes): QueuedDeployment
    {
        $result = DB::transaction(function () use ($project, $user, $type, $trigger, $attributes) {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDeleting()) {
                throw new DomainException('The project is being deleted.');
            }

            $sha = $attributes['commit_sha'] ?? null;
            if ($type === Deployment::TYPE_DEPLOY && $sha !== null) {
                $inProgress = Deployment::query()->where('project_id', $locked->id)->where('type', Deployment::TYPE_DEPLOY)
                    ->where('commit_sha', $sha)->whereIn('status', DeploymentStatus::activeValues())->orderByDesc('number')->first();
                if ($inProgress !== null) {
                    return new QueuedDeployment($inProgress, reused: true);
                }
            }

            $number = (int) Deployment::query()->where('project_id', $locked->id)->max('number') + 1;
            $label = "deployment #{$number}".($sha ? ' (commit '.substr($sha, 0, 7).')' : '');
            Deployment::query()
                ->where('project_id', $locked->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->whereNull('started_at')
                ->update([
                    'status' => DeploymentStatus::Superseded->value,
                    'finished_at' => now(),
                    'failure_reason' => "Superseded by {$label} before it started.",
                ]);

            return new QueuedDeployment(Deployment::query()->create([
                'project_id' => $locked->id,
                'number' => $number,
                'type' => $type,
                'trigger' => $trigger,
                'status' => DeploymentStatus::Queued,
                'initiated_by' => $user?->id,
                'queued_at' => now(),
                ...$attributes,
            ]));
        });

        if ($result->reused) {
            return $result;
        }
        $deployment = $result->deployment;
        RunDeployment::dispatch($deployment->id, $project->id)->afterCommit();

        $this->audit->log('deployment.'.($type === Deployment::TYPE_DEPLOY ? 'started' : $type), $deployment, metadata: [
            'project' => $project->slug, 'number' => $deployment->number, 'trigger' => $trigger, 'commit' => $deployment->shortSha(),
        ], label: $project->name.' #'.$deployment->number, userId: $user?->id);

        return $result;
    }
}
