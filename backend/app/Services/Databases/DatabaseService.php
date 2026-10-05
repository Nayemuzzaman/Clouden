<?php

namespace App\Services\Databases;

use App\Models\Project;
use App\Models\ProjectDatabase;
use App\Models\Server;
use App\Services\Audit\AuditLogger;
use App\Services\Deployment\NetworkManager;
use App\Services\Environment\EnvironmentService;
use DomainException;
use Throwable;

class DatabaseService
{
    public function __construct(
        private readonly PostgresProvisioner $provisioner,
        private readonly EnvironmentService $environment,
        private readonly NetworkManager $networks,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $name, ?Project $project = null): ProjectDatabase
    {
        $name = strtolower(trim($name));
        if (! Identifier::isValidManagedName($name)) {
            throw new DomainException('Database names must start with a letter and contain only lowercase letters, digits and underscores (max 63).');
        }
        if (ProjectDatabase::query()->where('name', $name)->orWhere('username', $name)->exists()) {
            throw new DomainException('A database with this name already exists.');
        }
        if ($project && $project->databases()->exists()) {
            throw new DomainException('This project already has a database.');
        }

        $database = ProjectDatabase::query()->create([
            'server_id' => $project->server_id ?? Server::local()->id,
            'project_id' => $project?->id,
            'name' => $name,
            'username' => $name,
            'password' => PostgresProvisioner::generatePassword(),
            'host' => (string) config('privatecloud.apps_db.host'),
            'port' => (int) config('privatecloud.apps_db.port'),
            'status' => 'provisioning',
        ]);

        try {
            $this->provisioner->create($database->name, $database->username, $database->password);
            $database->update(['status' => 'ready', 'last_error' => null]);
        } catch (Throwable $e) {
            $database->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            $this->audit->log('database.created', $database, 'failure', ['error' => $e->getMessage()]);

            throw new DomainException($e->getMessage());
        }

        if ($project) {
            $this->connectToProject($database, $project);
        }
        $this->audit->log('database.created', $database, metadata: ['project' => $project?->slug]);

        return $database->fresh();
    }

    /** Retry provisioning of a database that failed to create. */
    public function retry(ProjectDatabase $database): ProjectDatabase
    {
        try {
            $this->provisioner->create($database->name, $database->username, $database->password);
            $database->update(['status' => 'ready', 'last_error' => null]);
            if ($database->project) {
                $this->connectToProject($database, $database->project);
            }
        } catch (Throwable $e) {
            $database->update(['status' => 'failed', 'last_error' => $e->getMessage()]);

            throw new DomainException($e->getMessage());
        }

        return $database->fresh();
    }

    public function delete(ProjectDatabase $database): void
    {
        $project = $database->project;
        $database->update(['status' => 'deleting']);
        try {
            $this->provisioner->drop($database->name, $database->username);
        } catch (Throwable $e) {
            $database->update(['status' => 'ready', 'last_error' => $e->getMessage()]);
            $this->audit->log('database.deleted', $database, 'failure', ['error' => $e->getMessage()]);

            throw new DomainException($e->getMessage());
        }

        if ($project) {
            $this->environment->removeDatabase($project);
            $this->networks->detachDatabase($project);
        }
        $this->audit->log('database.deleted', $database, metadata: ['project' => $project?->slug]);
        $database->delete();
    }

    public function resetCredentials(ProjectDatabase $database): ProjectDatabase
    {
        $password = PostgresProvisioner::generatePassword();
        $this->provisioner->resetPassword($database->username, $password);
        $database->update(['password' => $password]);
        if ($database->project) {
            $this->environment->applyDatabase($database->project, $database);
        }
        $this->audit->log('database.credentials_reset', $database);

        return $database->fresh();
    }

    public function refreshSize(ProjectDatabase $database): void
    {
        $size = $this->provisioner->size($database->name);
        if ($size !== null) {
            $database->update(['size_bytes' => $size]);
        }
    }

    private function connectToProject(ProjectDatabase $database, Project $project): void
    {
        $this->environment->applyDatabase($project, $database);
        try {
            $this->networks->attachDatabase($project);
        } catch (Throwable) {
            // The network is (re)attached on the next deployment as well.
        }
    }
}
