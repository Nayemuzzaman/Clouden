<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\Backup;
use App\Services\Backups\BackupRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $backupId)
    {
        $this->timeout = (int) config('privatecloud.backups.timeout') + 300;
    }

    public function handle(BackupRunner $runner): void
    {
        $backup = Backup::query()->find($this->backupId);
        if ($backup) {
            $runner->run($backup);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Backup::query()->whereKey($this->backupId)->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])->update([
            'status' => JobStatus::Failed->value,
            'error' => 'The backup worker stopped unexpectedly'.($exception ? ': '.mb_substr($exception->getMessage(), 0, 500) : '.'),
            'finished_at' => now(),
        ]);
    }
}
