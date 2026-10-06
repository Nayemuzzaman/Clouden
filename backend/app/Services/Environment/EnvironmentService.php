<?php

namespace App\Services\Environment;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\ProjectDatabase;

class EnvironmentService
{
    /**
     * Final environment for the application container. User variables win over
     * the defaults injected by the platform (PORT).
     *
     * @return array<string, string>
     */
    public function compile(Project $project): array
    {
        $env = ['PORT' => (string) $project->port];
        foreach ($project->environmentVariables()->orderBy('key')->get() as $variable) {
            $env[$variable->key] = (string) $variable->value;
        }

        return $env;
    }

    /** @return array<string, string> variables marked "available at build time" */
    public function buildArgs(Project $project): array
    {
        $args = [];
        foreach ($project->environmentVariables()->where('available_at_build', true)->get() as $variable) {
            $args[$variable->key] = (string) $variable->value;
        }

        return $args;
    }

    /** @return list<string> secret values, used to redact logs */
    public function secretValues(Project $project): array
    {
        return $project->environmentVariables()->where('is_secret', true)->get()
            ->map(fn (EnvironmentVariable $v) => (string) $v->value)
            ->filter(fn (string $v) => strlen($v) >= 6)
            ->values()
            ->all();
    }

    public function set(Project $project, string $key, string $value, ?bool $isSecret = null, bool $isSystem = false, ?bool $availableAtBuild = null): EnvironmentVariable
    {
        $variable = $project->environmentVariables()->firstOrNew(['key' => $key]);
        $variable->value = $value;
        $variable->is_secret = $isSecret ?? ($variable->exists ? $variable->is_secret : EnvironmentKey::looksSecret($key));
        $variable->is_system = $isSystem;
        if ($availableAtBuild !== null) {
            $variable->available_at_build = $availableAtBuild;
        }
        $variable->save();

        return $variable;
    }

    /** Inject (or refresh) the connection variables for an attached database. */
    public function applyDatabase(Project $project, ProjectDatabase $database): void
    {
        $values = [
            'DB_CONNECTION' => ['pgsql', false],
            'DB_HOST' => [$database->appHost(), false],
            'DB_PORT' => [(string) $database->port, false],
            'DB_DATABASE' => [$database->name, false],
            'DB_USERNAME' => [$database->username, false],
            'DB_PASSWORD' => [$database->password, true],
            'DATABASE_URL' => [$database->connectionUrl(true), true],
        ];
        foreach ($values as $key => [$value, $secret]) {
            $existing = $project->environmentVariables()->where('key', $key)->first();
            if ($existing && ! $existing->is_system) {
                continue; // never overwrite a variable the administrator defined manually
            }
            $this->set($project, $key, $value, $secret, true);
        }
    }

    public function removeDatabase(Project $project): void
    {
        $project->environmentVariables()->where('is_system', true)
            ->whereIn('key', ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DATABASE_URL'])
            ->delete();
    }
}
