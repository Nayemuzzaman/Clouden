<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Backups\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RunScheduledBackups extends Command
{
    protected $signature = 'privatecloud:scheduled-backups';

    protected $description = 'Start scheduled project backups that are due and apply retention';

    public function handle(BackupService $backups): int
    {
        $projects = Project::query()->whereNull('deleting_at')->where('backup_schedule', '!=', 'off')->get();
        foreach ($projects as $project) {
            if (! $this->isDue($project)) {
                continue;
            }
            try {
                $created = $backups->backupProject($project, null, 'scheduled');
                $project->update(['last_scheduled_backup_at' => now()]);
                $this->info("{$project->slug}: started ".count($created).' backup(s)');
            } catch (Throwable $e) {
                $this->error("{$project->slug}: ".$e->getMessage());
            }
            $backups->applyRetention($project);
        }

        return self::SUCCESS;
    }

    private function isDue(Project $project): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', $project->backup_time ?: '03:00'));
        $todayAt = now()->setTime($hour, $minute);
        if (now()->lt($todayAt)) {
            return false;
        }
        $last = $project->last_scheduled_backup_at;
        if ($project->backup_schedule === 'weekly') {
            return $last === null || $last->lt($todayAt->copy()->subDays(6));
        }

        return $last === null || $last->lt($todayAt);
    }
}
