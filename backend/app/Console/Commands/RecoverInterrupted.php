<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Enums\JobStatus;
use App\Enums\ProjectStatus;
use App\Models\Backup;
use App\Models\Deployment;
use App\Models\Operation;
use App\Models\Project;
use App\Services\Backups\BackupStorage;
use App\Services\Docker\DockerClient;
use App\Services\Notifier;
use App\Services\Routing\CaddyConfigurator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs when a queue worker container starts, before it processes jobs.
 *
 * Exactly one worker serves each queue, so anything still marked as running
 * for that queue at start-up was interrupted (container restarted, server
 * rebooted, out-of-memory kill). Instead of waiting for the stale-job timeout,
 * the work is marked failed right away, half-started resources are cleaned up
 * and the per-project lock is released so new deployments can run.
 */
class RecoverInterrupted extends Command
{
    protected $signature = 'privatecloud:recover-interrupted {queue : "deployments" or "default"}';

    protected $description = 'Clean up work interrupted by a worker restart';

    public function handle(DockerClient $docker, BackupStorage $storage, CaddyConfigurator $caddy, Notifier $notifier): int
    {
        return match ($this->argument('queue')) {
            'deployments' => $this->recoverDeployments($docker, $caddy, $notifier),
            'default' => $this->recoverTasks($storage, $notifier),
            default => $this->invalidQueue(),
        };
    }

    private function recoverDeployments(DockerClient $docker, CaddyConfigurator $caddy, Notifier $notifier): int
    {
        // Running stages, plus deployments claimed by the worker (started) that never left "queued".
        $interrupted = Deployment::query()->with('project.currentDeployment')
            ->where(fn ($q) => $q->whereIn('status', array_values(array_diff(DeploymentStatus::activeValues(), [DeploymentStatus::Queued->value])))
                ->orWhere(fn ($q) => $q->where('status', DeploymentStatus::Queued->value)->whereNotNull('started_at')))
            ->get();
        if ($interrupted->isEmpty()) {
            return self::SUCCESS;
        }

        // First make routing match the recorded production deployments, so that a
        // deployment interrupted while switching traffic never leaves Caddy
        // pointing at a container that is about to be removed.
        $routingRestored = true;
        try {
            $caddy->sync();
        } catch (Throwable $e) {
            $routingRestored = false;
            $this->warn('Could not re-sync routing: '.$e->getMessage());
        }

        foreach ($interrupted as $deployment) {
            $stage = $deployment->status->value;
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'failure_stage' => $stage,
                'failure_reason' => 'The deployment was interrupted because the deployment worker restarted (update, reboot or crash). The previous version was not changed. Deploy again.',
                'finished_at' => now(),
            ]);
            $project = $deployment->project;

            $candidate = $deployment->container_name;
            if ($candidate && $project?->currentDeployment?->container_name !== $candidate && $routingRestored) {
                try {
                    $docker->removeContainer($candidate, force: true);
                } catch (Throwable) {
                    // never started or already gone
                }
            }
            File::deleteDirectory(rtrim((string) config('privatecloud.data_dir'), '/').'/builds/deployment-'.$deployment->id);

            if ($project) {
                if ($project->status === ProjectStatus::Deploying) {
                    $project->update(['status' => $project->current_deployment_id ? ProjectStatus::Running : ProjectStatus::Failed]);
                }
                $tasksBusy = Operation::query()->where('project_id', $project->id)
                    ->whereIn('type', ['backup.restore', 'project.delete'])->where('status', JobStatus::Running->value)->exists();
                if (! $tasksBusy) {
                    self::releaseProjectLock($project->id);
                }
                $notifier->notify('deployment.failed', "{$project->name} deployment interrupted", "Deployment #{$deployment->number} was interrupted by a worker restart. The previous version is still live.", 'warning', "/projects/{$project->slug}/deployments/{$deployment->id}");
            }
            $this->line("Marked interrupted deployment #{$deployment->id} as failed.");
        }

        return self::SUCCESS;
    }

    private function recoverTasks(BackupStorage $storage, Notifier $notifier): int
    {
        foreach (Backup::query()->with(['database', 'volume.project'])->where('status', JobStatus::Running->value)->get() as $backup) {
            $this->discardPartialFile($backup, $storage);
            $backup->update(['status' => JobStatus::Failed, 'finished_at' => now(), 'error' => 'The backup was interrupted because the worker restarted. No usable file was kept; run it again.']);
            $this->line("Marked interrupted backup {$backup->uuid} as failed.");
        }

        $operations = Operation::query()->whereIn('type', ['backup.restore', 'project.delete'])->where('status', JobStatus::Running->value)->get();
        foreach ($operations as $operation) {
            if ($operation->type === 'backup.restore') {
                $safety = $operation->meta['safety_backup'] ?? null;
                $operation->markFailed('The restore was interrupted because the worker restarted. The data may be partially restored'
                    .($safety ? "; restore the safety backup {$safety} taken just before, or this backup again." : '; restore again.'));
                $notifier->notify('backup.restore_failed', 'Restore interrupted', 'A restore was interrupted by a worker restart. Open Backups and restore again.', 'error', '/backups');
            } else {
                $operation->markFailed('The deletion was interrupted because the worker restarted. Delete the project again to finish.');
                Project::query()->whereKey($operation->project_id)->whereNotNull('deleting_at')->get()->each(function (Project $project) {
                    $project->update(['deleting_at' => null, 'status' => $project->current_deployment_id ? ProjectStatus::Crashed : ProjectStatus::Failed]);
                });
            }
            if ($operation->project_id !== null) {
                $deploying = Deployment::query()->where('project_id', $operation->project_id)
                    ->whereIn('status', array_values(array_diff(DeploymentStatus::activeValues(), [DeploymentStatus::Queued->value])))->exists();
                if (! $deploying) {
                    self::releaseProjectLock($operation->project_id);
                }
            }
            $this->line("Marked interrupted {$operation->type} operation {$operation->id} as failed.");
        }

        return self::SUCCESS;
    }

    /** Same key as the shared WithoutOverlapping('project:<id>') job middleware. */
    public static function releaseProjectLock(int $projectId): void
    {
        try {
            Cache::lock(Project::lockName($projectId))->forceRelease();
        } catch (Throwable) {
            // cache unavailable: the lock expires on its own
        }
    }

    /** Backup files are named "<timestamp>-<first 8 chars of uuid>.<ext>" in a per-source directory. */
    private function discardPartialFile(Backup $backup, BackupStorage $storage): void
    {
        $directory = match (true) {
            $backup->type === Backup::TYPE_DATABASE && $backup->database !== null => 'databases/'.$backup->database->name,
            $backup->type === Backup::TYPE_VOLUME && $backup->volume?->project !== null => 'volumes/'.$backup->volume->project->slug.'/'.$backup->volume->name,
            default => null,
        };
        if ($directory === null) {
            return;
        }
        try {
            $dir = dirname($storage->stagingPath($directory.'/probe'));
            foreach (File::glob($dir.'/*-'.Str::substr($backup->uuid, 0, 8).'.*') as $partial) {
                File::delete($partial);
            }
        } catch (Throwable) {
            // best effort
        }
    }

    private function invalidQueue(): int
    {
        $this->error('Unknown queue. Use "deployments" or "default".');

        return self::FAILURE;
    }
}
