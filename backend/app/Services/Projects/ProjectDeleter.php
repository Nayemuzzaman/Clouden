<?php

namespace App\Services\Projects;

use App\Models\Backup;
use App\Models\Operation;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Backups\BackupStorage;
use App\Services\Databases\DatabaseService;
use App\Services\Deployment\NetworkManager;
use App\Services\Docker\DockerClient;
use App\Services\Routing\CaddyConfigurator;
use App\Services\Source\GitHubClient;
use Throwable;

/** Removes a project's runtime resources. Runs in a queued job holding the project's lock. */
class ProjectDeleter
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly CaddyConfigurator $caddy,
        private readonly NetworkManager $networks,
        private readonly DatabaseService $databases,
        private readonly BackupStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function run(Operation $operation, Project $project): void
    {
        $options = $operation->meta['options'] ?? [];
        $operation->markRunning('Removing routing');
        $warnings = [];

        try {
            // 1. Stop routing traffic (the project is excluded because deleting_at is set).
            $this->caddy->sync();

            // 2. Containers
            $operation->progress('Stopping containers');
            foreach ($this->docker->listContainers(['label' => ['privatecloud.project='.$project->id]]) as $container) {
                $this->docker->removeContainer((string) $container['Id'], force: true);
            }

            // 3. Images built for this project
            $operation->progress('Removing images');
            if ($project->source_type !== Project::SOURCE_IMAGE) {
                foreach ($project->deployments()->whereNotNull('image_tag')->pluck('image_tag')->unique() as $tag) {
                    try {
                        $this->docker->removeImage((string) $tag);
                    } catch (Throwable) {
                        // already removed
                    }
                }
            }

            // 4. Network
            try {
                $this->networks->remove($project);
            } catch (Throwable $e) {
                $warnings[] = 'Network: '.$e->getMessage();
            }

            // 5. GitHub webhook
            $repository = $project->repository;
            if ($repository?->webhook_id && $repository->full_name) {
                try {
                    GitHubClient::forConnection()->deleteWebhook($repository->full_name, (int) $repository->webhook_id);
                } catch (Throwable $e) {
                    // GitHub being unreachable must not block deleting the project; the
                    // orphaned webhook only receives 404s from now on.
                    $warnings[] = 'GitHub webhook was not removed ('.$e->getMessage().'); delete it in the repository settings';
                }
            }

            // 6. Optional: databases
            if (! empty($options['delete_databases'])) {
                $operation->progress('Deleting databases');
                foreach ($project->databases as $database) {
                    $this->databases->delete($database);
                }
            } else {
                $project->databases()->update(['project_id' => null]); // keep as standalone databases
            }

            // 7. Optional: volumes
            if (! empty($options['delete_volumes'])) {
                $operation->progress('Deleting volumes');
                foreach ($project->volumes as $volume) {
                    try {
                        $this->docker->removeVolume($volume->docker_name);
                    } catch (Throwable $e) {
                        $warnings[] = "Volume {$volume->name}: ".$e->getMessage();
                    }
                }
            }

            // 8. Optional: backups
            if (! empty($options['delete_backups'])) {
                $operation->progress('Deleting backups');
                foreach (Backup::query()->where('project_id', $project->id)->get() as $backup) {
                    if ($backup->path) {
                        $this->storage->delete($backup->path);
                    }
                    $backup->delete();
                }
            }

            $this->audit->log('project.deleted', $project, metadata: $options, userId: $operation->initiated_by);
            $operation->update(['target_type' => null, 'target_id' => null]);
            $project->delete();
            $operation->markSucceeded($warnings === [] ? 'Project deleted' : 'Project deleted with warnings: '.implode('; ', $warnings));
        } catch (Throwable $e) {
            $operation->markFailed('Deletion stopped: '.$e->getMessage().' You can retry the deletion.');
            $project->update(['deleting_at' => null, 'status' => $project->current_deployment_id ? 'crashed' : 'failed']);
            $this->audit->log('project.deleted', $project, 'failure', ['error' => $e->getMessage()], userId: $operation->initiated_by);
        }
    }
}
