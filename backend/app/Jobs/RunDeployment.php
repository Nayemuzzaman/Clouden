<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Releases while waiting for the project lock are unlimited; real exceptions fail immediately. */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $deploymentId, public readonly int $projectId)
    {
        $this->onQueue('deployments');
        $this->timeout = (int) config('privatecloud.deploy.build_timeout') + 900;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('project:'.$this->projectId))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(DeploymentPipeline $pipeline): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if (! $deployment) {
            return;
        }
        // Defence in depth behind the per-project lock: never start while another
        // deployment of the project is past "queued" (it may be switching traffic).
        $busy = Deployment::query()->where('project_id', $deployment->project_id)->whereKeyNot($deployment->id)
            ->whereNotNull('started_at')->whereIn('status', DeploymentStatus::activeValues())->exists();
        if ($busy && $deployment->status === DeploymentStatus::Queued) {
            $this->release(10);

            return;
        }
        $pipeline->run($deployment);
    }

    public function failed(?Throwable $exception): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if ($deployment && $deployment->status->isActive()) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'failure_stage' => $deployment->status->value,
                'failure_reason' => 'The deployment worker stopped unexpectedly'.($exception ? ': '.mb_substr($exception->getMessage(), 0, 500) : '.').' The previous version was not changed.',
                'finished_at' => now(),
            ]);
        }
    }
}
