<?php

namespace App\Enums;

/** Status of background work that is not a deployment (backups, restores, deletions). */
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Success || $this === self::Failed;
    }
}
