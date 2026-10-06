<?php

namespace App\Services\Backups;

use App\Enums\JobStatus;
use App\Jobs\RestoreBackup;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectDatabase;
use App\Models\User;
use App\Models\Volume;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class BackupService
{
    public function __construct(
        private readonly BackupStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function backupDatabase(ProjectDatabase $database, ?User $user, string $trigger = 'manual', bool $dispatch = true): Backup
    {
        if ($database->status !== 'ready') {
            throw new DomainException('The database is not ready.');
        }
        $this->guardNotRunning(fn ($q) => $q->where('project_database_id', $database->id));

        $backup = Backup::query()->create([
            'project_id' => $database->project_id,
            'project_database_id' => $database->id,
            'type' => Backup::TYPE_DATABASE,
            'trigger' => $trigger,
            'status' => JobStatus::Queued,
            'label' => 'Database '.$database->name,
            'storage' => $this->storage->name(),
            'initiated_by' => $user?->id,
        ]);
        if ($dispatch) {
            RunBackup::dispatch($backup->id);
        }

        return $backup;
    }

    public function backupVolume(Volume $volume, ?User $user, string $trigger = 'manual', bool $dispatch = true): Backup
    {
        $this->guardNotRunning(fn ($q) => $q->where('volume_id', $volume->id));

        $backup = Backup::query()->create([
            'project_id' => $volume->project_id,
            'volume_id' => $volume->id,
            'type' => Backup::TYPE_VOLUME,
            'trigger' => $trigger,
            'status' => JobStatus::Queued,
            'label' => 'Volume '.($volume->project?->name ? $volume->project->name.' / ' : '').$volume->name,
            'storage' => $this->storage->name(),
            'initiated_by' => $user?->id,
        ]);
        if ($dispatch) {
            RunBackup::dispatch($backup->id);
        }

        return $backup;
    }

    /** @return list<Backup> */
    public function backupProject(Project $project, ?User $user, string $trigger = 'manual'): array
    {
        $backups = [];
        foreach ($project->databases()->where('status', 'ready')->get() as $database) {
            $backups[] = $this->backupDatabase($database, $user, $trigger);
        }
        foreach ($project->volumes as $volume) {
            $backups[] = $this->backupVolume($volume, $user, $trigger);
        }

        return $backups;
    }

    public function restore(Backup $backup, User $user): Operation
    {
        if ($backup->status !== JobStatus::Success || ! $backup->path) {
            throw new DomainException('Only completed backups can be restored.');
        }
        if ($backup->type === Backup::TYPE_DATABASE && $backup->database === null) {
            throw new DomainException('The database this backup belongs to no longer exists. Create a database first, then restore from the command line (see docs/backups.md).');
        }
        if ($backup->type === Backup::TYPE_VOLUME && $backup->volume === null) {
            throw new DomainException('The volume this backup belongs to no longer exists.');
        }
        // One restore at a time per database/volume, whichever backup it comes from.
        $runningTargets = Operation::query()->where('type', 'backup.restore')->whereIn('status', ['queued', 'running'])
            ->where('target_type', $backup->getMorphClass())->pluck('target_id');
        $running = $runningTargets->isNotEmpty() && Backup::query()->whereIn('id', $runningTargets)
            ->where(fn ($q) => $backup->type === Backup::TYPE_DATABASE
                ? $q->where('project_database_id', $backup->project_database_id)
                : $q->where('volume_id', $backup->volume_id))
            ->exists();
        if ($running) {
            throw new DomainException('A restore of this '.($backup->type === Backup::TYPE_DATABASE ? 'database' : 'volume').' is already in progress.');
        }

        $operation = Operation::query()->create([
            'type' => 'backup.restore',
            'status' => JobStatus::Queued,
            'project_id' => $backup->project_id,
            'target_type' => $backup->getMorphClass(),
            'target_id' => $backup->id,
            'message' => 'Waiting to start',
            'initiated_by' => $user->id,
        ]);
        RestoreBackup::dispatch($operation->id);
        $this->audit->log('backup.restore_started', $backup, metadata: ['type' => $backup->type], label: $backup->label);

        return $operation;
    }

    public function delete(Backup $backup): void
    {
        if (in_array($backup->status, [JobStatus::Queued, JobStatus::Running], true)) {
            throw new DomainException('This backup is still running.');
        }
        if ($backup->path) {
            $this->storage->delete($backup->path);
        }
        $this->audit->log('backup.deleted', $backup, label: $backup->label);
        $backup->delete();
    }

    /** Remove the oldest scheduled backups beyond the project's retention count. */
    public function applyRetention(Project $project): int
    {
        $removed = 0;
        $keep = max(1, (int) $project->backup_retention);
        $groups = Backup::query()->where('project_id', $project->id)->where('trigger', 'scheduled')->where('status', JobStatus::Success->value)
            ->orderByDesc('created_at')->get()
            ->groupBy(fn (Backup $b) => $b->type.':'.($b->project_database_id ?? $b->volume_id));
        foreach ($groups as $backups) {
            foreach ($backups->slice($keep) as $old) {
                $this->delete($old);
                $removed++;
            }
        }

        return $removed;
    }

    /** @param callable(Builder): mixed $scope */
    private function guardNotRunning(callable $scope): void
    {
        $query = Backup::query()->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value]);
        $scope($query);
        if ($query->exists()) {
            throw new DomainException('A backup of this resource is already in progress.');
        }
    }
}
