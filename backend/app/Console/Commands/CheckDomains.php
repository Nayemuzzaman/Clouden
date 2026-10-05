<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomain;
use App\Models\Domain;
use Illuminate\Console\Command;

class CheckDomains extends Command
{
    protected $signature = 'privatecloud:check-domains {--all : Also re-check domains with an active certificate}';

    protected $description = 'Check DNS and HTTPS certificate status of domains';

    public function handle(): int
    {
        $query = Domain::query();
        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->where('cert_status', '!=', 'active')->orWhere('cert_expires_at', '<', now()->addDays(14)));
        }
        $query->each(fn (Domain $domain) => CheckDomain::dispatch($domain->id));

        return self::SUCCESS;
    }
}
