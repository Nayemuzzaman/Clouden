<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\JobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeleteProject;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\Identifier;
use App\Services\Deployment\ContainerLauncher;
use App\Services\Domains\DomainService;
use App\Services\Environment\EnvironmentService;
use App\Services\Source\SourceException;
use App\Services\Source\SourceFetcher;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProjectService
{
    public function __construct(
        private readonly EnvironmentService $environment,
        private readonly DomainService $domains,
        private readonly DatabaseService $databases,
        private readonly SourceFetcher $fetcher,
        private readonly AutoDeployService $autoDeploy,
        private readonly RepositoryConnection $connection,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see StoreProjectRequest)
     * @return array{project: Project, warnings: list<string>}
     */
    public function create(array $data, User $user): array
    {
        $warnings = [];

        // GitHub: the repository must be readable and the production branch must exist
        // before anything is created (a refusal is a validation error, an outage a warning).
        $check = null;
        if (($data['source_type'] ?? null) === Project::SOURCE_GITHUB) {
            $check = $this->connection->check(Project::SOURCE_GITHUB, (string) $data['repository'], (string) ($data['branch'] ?? 'main'));
            if ($check['warning']) {
                $warnings[] = $check['warning'];
            }
        }

        $project = DB::transaction(function () use ($data, $check) {
            $project = Project::query()->create([
                'server_id' => Server::local()->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'status' => ProjectStatus::Created,
                'source_type' => $data['source_type'],
                'image' => $data['image'] ?? null,
                'dockerfile_path' => $data['dockerfile_path'] ?? 'Dockerfile',
                'build_context' => $data['build_context'] ?? '.',
                'port' => $data['port'] ?? 3000,
                'memory_limit_mb' => $data['memory_limit_mb'] ?? 512,
                'cpu_limit' => $data['cpu_limit'] ?? 1,
                'health_check_type' => $data['health_check_type'] ?? 'http',
                'health_check_path' => $data['health_check_path'] ?? '/',
                'webhook_secret' => Str::random(40),
            ]);

            if ($project->source_type !== Project::SOURCE_IMAGE) {
                $project->repository()->create([
                    'provider' => $project->source_type,
                    'full_name' => $data['repository'] ?? null,
                    'url' => $project->source_type === Project::SOURCE_GITHUB
                        ? 'https://github.com/'.$data['repository'].'.git'
                        : $data['repository_url'],
                    'branch' => $data['branch'] ?? 'main',
                    'visibility' => $check['visibility'] ?? null,
                    'latest_commit_sha' => $check['commit']?->sha,
                    'latest_commit_message' => $check['commit']?->title(),
                    'latest_commit_author' => $check['commit']?->author,
                    'latest_commit_at' => $check['commit']?->committedAt,
                    'last_checked_at' => $check['commit'] ? now() : null,
                    'access_status' => $check['commit'] ? Repository::ACCESS_OK : null,
                ]);
            }

            foreach ($data['environment'] ?? [] as $variable) {
                $this->environment->set($project, $variable['key'], (string) ($variable['value'] ?? ''), $variable['is_secret'] ?? null);
            }

            foreach ($data['volumes'] ?? [] as $volume) {
                $project->volumes()->create([
                    'name' => $volume['name'],
                    'mount_path' => $volume['mount_path'],
                    'docker_name' => ContainerLauncher::volumeName($project, $volume['name']),
                ]);
            }

            return $project;
        });

        $this->audit->log('project.created', $project, metadata: ['source' => $project->source_type]);

        if ($project->repository && $check === null) {
            try {
                $this->refreshCommit($project);
            } catch (Throwable $e) {
                $warnings[] = 'Could not read the latest commit: '.$e->getMessage();
            }
        }
        if (! empty($data['domain'])) {
            try {
                $this->domains->add($project, $data['domain']);
            } catch (Throwable $e) {
                $warnings[] = 'Domain was not added: '.$e->getMessage();
            }
        }
        if (! empty($data['database'])) {
            try {
                $this->databases->create(Identifier::fromSlug($project->slug), $project);
            } catch (Throwable $e) {
                $warnings[] = 'Database was not created: '.$e->getMessage().' You can retry from the Database tab.';
            }
        }
        if (! empty($data['auto_deploy'])) {
            $result = $this->autoDeploy->enable($project);
            if ($result['error']) {
                $warnings[] = $result['error'];
            }
        }

        return ['project' => $project->fresh(), 'warnings' => $warnings];
    }

    public function refreshCommit(Project $project): void
    {
        $repository = $project->repository;
        if (! $repository) {
            return;
        }
        if ($repository->isGitHub()) {
            try {
                $this->fetcher->refreshVisibility($repository);
            } catch (SourceException $e) {
                $this->fetcher->recordFailure($repository, $e);
                if ($e->kind !== SourceException::NOT_FOUND) {
                    throw $e;
                }
                // Not visible: latestCommit() below explains it (and falls back to the token).
            }
        }
        try {
            $this->fetcher->latestCommit($repository); // records the head commit and access status
        } catch (Throwable $e) {
            if (! $e instanceof SourceException) {
                $repository->update(['last_checked_at' => now(), 'last_check_error' => $e->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * Mark the project for deletion and queue the cleanup. Nothing is removed from
     * the request thread. Databases, volumes and backups are only deleted when the
     * administrator explicitly opted in.
     *
     * @param  array{delete_databases: bool, delete_volumes: bool, delete_backups: bool}  $options
     */
    public function requestDeletion(Project $project, array $options, User $user): Operation
    {
        $operation = DB::transaction(function () use ($project, $options, $user) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->isDeleting()) {
                throw new DomainException('The project is already being deleted.');
            }
            $locked->update(['deleting_at' => now(), 'status' => ProjectStatus::Deleting]);
            $locked->deployments()->where('status', DeploymentStatus::Queued->value)->whereNull('started_at')->update([
                'status' => DeploymentStatus::Cancelled->value, 'finished_at' => now(), 'failure_reason' => 'The project is being deleted.',
            ]);

            return Operation::query()->create([
                'type' => 'project.delete',
                'status' => JobStatus::Queued,
                'project_id' => $locked->id,
                'target_type' => $locked->getMorphClass(),
                'target_id' => $locked->id,
                'message' => 'Waiting for running work to finish',
                'meta' => ['options' => $options, 'name' => $locked->name, 'slug' => $locked->slug],
                'initiated_by' => $user->id,
            ]);
        });

        DeleteProject::dispatch($operation->id, $project->id)->afterCommit();
        $this->audit->log('project.delete_requested', $project, metadata: $options);

        return $operation;
    }

    /** @return array<string, mixed> resources affected by deleting the project, for the confirmation dialog */
    public function deletionImpact(Project $project): array
    {
        return [
            'containers' => $project->currentDeployment?->container_name ? 1 : 0,
            'deployments' => $project->deployments()->count(),
            'domains' => $project->domains()->pluck('hostname'),
            'databases' => $project->databases()->get(['name', 'size_bytes']),
            'volumes' => $project->volumes()->get(['name', 'mount_path', 'size_bytes']),
            'backups' => $project->backups()->count(),
            'environment_variables' => $project->environmentVariables()->count(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = trim(substr(preg_replace('/[^a-z0-9-]/', '', $base) ?? '', 0, 30), '-');
        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'app'.($base !== '' ? '-'.$base : '');
            $base = substr($base, 0, 30);
        }
        $slug = $base;
        $i = 2;
        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = substr($base, 0, 26).'-'.$i++;
        }

        return $slug;
    }
}
