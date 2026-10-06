<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Domains\DomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $domainId) {}

    public function handle(DomainService $domains): void
    {
        $domain = Domain::query()->find($this->domainId);
        if ($domain) {
            $domains->check($domain);
        }
    }
}
