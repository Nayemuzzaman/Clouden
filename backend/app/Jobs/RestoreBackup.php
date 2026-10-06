<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\Operation;
use App\Services\Backups\BackupRestorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RestoreBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $operationId)
    {
        $this->timeout = (int) config('privatecloud.backups.timeout') * 2 + 300;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $projectId = Operation::query()->whereKey($this->operationId)->value('project_id');

        return $projectId
            ? [(new WithoutOverlapping('project:'.$projectId))->shared()->releaseAfter(10)->expireAfter($this->timeout + 120)]
            : [];
    }

    public function handle(BackupRestorer $restorer): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation && $operation->status === JobStatus::Queued) {
            $restorer->run($operation);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed',
            'error' => 'The restore worker stopped unexpectedly'.($exception ? ': '.mb_substr($exception->getMessage(), 0, 500) : '.'),
            'finished_at' => now(),
        ]);
    }
}
