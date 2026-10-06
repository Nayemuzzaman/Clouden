<?php

namespace App\Jobs;

use App\Services\Monitoring\ServiceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Dispatched every minute; proves the queue worker is processing jobs. */
class WorkerHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(ServiceHealth::HEARTBEAT_KEY, now()->timestamp, now()->addHour());
    }
}
