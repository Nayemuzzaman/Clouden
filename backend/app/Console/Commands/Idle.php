<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Enums\JobStatus;
use App\Models\Backup;
use App\Models\Deployment;
use App\Models\Operation;
use Illuminate\Console\Command;

/**
 * Exit code 0 when no deployment, backup, restore or deletion is running, 1
 * otherwise. scripts/update.sh waits for this before restarting the workers,
 * so an update never interrupts a build or a restore.
 */
class Idle extends Command
{
    protected $signature = 'privatecloud:idle';

    protected $description = 'Report whether background work (deployments, backups, restores) is running';

    public function handle(): int
    {
        $busy = [
            'deployments' => Deployment::query()->whereIn('status', array_values(array_diff(DeploymentStatus::activeValues(), [DeploymentStatus::Queued->value])))->count(),
            'backups' => Backup::query()->where('status', JobStatus::Running->value)->count(),
            'restores/deletions' => Operation::query()->where('status', JobStatus::Running->value)->count(),
        ];
        $busy = array_filter($busy);
        if ($busy === []) {
            $this->line('idle');

            return self::SUCCESS;
        }
        $this->line('running: '.implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($busy), $busy)));

        return self::FAILURE;
    }
}
