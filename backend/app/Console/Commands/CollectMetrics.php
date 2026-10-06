<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MetricsCollector;
use Illuminate\Console\Command;

class CollectMetrics extends Command
{
    protected $signature = 'privatecloud:metrics {--prune : Delete metrics older than the retention period}';

    protected $description = 'Record server and project resource usage';

    public function handle(MetricsCollector $collector): int
    {
        if ($this->option('prune')) {
            $this->info('Pruned '.$collector->prune().' metric rows.');

            return self::SUCCESS;
        }
        $collector->collect();

        return self::SUCCESS;
    }
}
