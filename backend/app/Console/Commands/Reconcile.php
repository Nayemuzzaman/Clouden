<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Enums\JobStatus;
use App\Enums\ProjectStatus;
use App\Models\Backup;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\NetworkManager;
use App\Services\Docker\DockerClient;
use App\Services\Notifier;
use App\Services\Projects\ProductionReconciler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings recorded state back in line with reality:
 *  - deployments/backups stuck in an active state (worker died) are marked failed;
 *  - projects whose live container stopped unexpectedly are marked crashed (and back);
 *  - platform containers that were recreated are re-attached to project networks;
 *  - a Caddy route that does not point at the recorded production container is
 *    re-rendered from the database (see ProductionReconciler; never while the
 *    project is deploying).
 */
class Reconcile extends Command
{
    protected $signature = 'privatecloud:reconcile';

    protected $description = 'Detect stale jobs and crashed applications';

    public function handle(DockerClient $docker, Notifier $notifier, NetworkManager $networks, ProductionReconciler $production): int
    {
        $staleAfter = (int) config('privatecloud.deploy.stale_after_seconds');

        Deployment::query()->whereIn('status', array_diff(DeploymentStatus::activeValues(), ['queued']))
            ->where('started_at', '<', now()->subSeconds($staleAfter))
            ->each(fn (Deployment $d) => $d->update([
                'status' => DeploymentStatus::Failed, 'failure_stage' => $d->status->value, 'finished_at' => now(),
                'failure_reason' => 'The deployment did not finish in time; the worker probably stopped. The previous version was not changed.',
            ]));
        Deployment::query()->where('status', 'queued')->where('queued_at', '<', now()->subHours(12))
            ->update(['status' => 'failed', 'finished_at' => now(), 'failure_reason' => 'The deployment waited in the queue for more than 12 hours. Is the background worker running?']);

        Backup::query()->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
            ->where('created_at', '<', now()->subSeconds((int) config('privatecloud.backups.timeout') * 2 + 600))
            ->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'The backup did not finish in time; the worker probably stopped.']);

        try {
            if (! $docker->ping()) {
                return self::SUCCESS;
            }
        } catch (Throwable) {
            return self::SUCCESS;
        }

        try {
            if (($repaired = $networks->repair(Project::query()->whereNull('deleting_at')->get())) > 0) {
                $this->info("Re-attached platform containers to {$repaired} project network(s).");
            }
        } catch (Throwable $e) {
            report($e);
        }

        foreach (Project::query()->whereNull('deleting_at')->whereNotNull('current_deployment_id')->whereHas('domains')->get() as $project) {
            try {
                if ($production->repairRouting($project)) {
                    $this->warn("Routing of {$project->slug} did not match the recorded production deployment and was re-synced.");
                    $notifier->notify('routing.repaired', "{$project->name}: routing repaired", 'Traffic was not routed to the recorded production version and has been switched back to it.', 'warning', "/projects/{$project->slug}");
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $projects = Project::query()->with('currentDeployment')->whereNull('deleting_at')
            ->whereIn('status', [ProjectStatus::Running->value, ProjectStatus::Crashed->value])->get();
        foreach ($projects as $project) {
            $container = $project->currentDeployment?->container_name;
            if (! $container) {
                continue;
            }
            $info = $docker->inspectContainer($container);
            $running = $info !== null && ($info['State']['Running'] ?? false) && ! ($info['State']['Restarting'] ?? false);

            if (! $running && $project->status === ProjectStatus::Running) {
                $project->update(['status' => ProjectStatus::Crashed]);
                $reason = $info === null ? 'its container is missing' : (($info['State']['OOMKilled'] ?? false) ? 'it ran out of memory' : 'it exited with code '.($info['State']['ExitCode'] ?? '?'));
                $notifier->notify('project.crashed', "{$project->name} is down", "The application stopped because {$reason}. Docker restarts it automatically when possible.", 'error', "/projects/{$project->slug}");
            } elseif ($running && $project->status === ProjectStatus::Crashed) {
                $project->update(['status' => ProjectStatus::Running]);
            }
        }

        return self::SUCCESS;
    }
}
