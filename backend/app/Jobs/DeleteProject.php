<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Models\Project;
use App\Services\Projects\ProjectDeleter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class DeleteProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $operationId, public readonly int $projectId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Same key as deployments: deletion waits for a running deployment to finish.
        return [(new WithoutOverlapping('project:'.$this->projectId))->shared()->releaseAfter(10)->expireAfter(1200)];
    }

    public function handle(ProjectDeleter $deleter): void
    {
        $operation = Operation::query()->find($this->operationId);
        $project = Project::query()->find($this->projectId);
        // A deletion marked failed (e.g. interrupted by a worker restart) is only resumed when requested again.
        if ($operation && $project && in_array($operation->status->value, ['queued', 'running'], true)) {
            $deleter->run($operation, $project);
        }
    }
}
