<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = 'App '.Str::lower(Str::random(6));

        return [
            'server_id' => fn () => Server::local()->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => ProjectStatus::Created,
            'source_type' => Project::SOURCE_GITHUB,
            'port' => 3000,
            'memory_limit_mb' => 512,
            'cpu_limit' => 1,
            'health_check_type' => 'http',
            'health_check_path' => '/health',
            'health_check_retries' => 2,
            'health_check_interval' => 1,
            'webhook_secret' => 'test-webhook-secret',
        ];
    }

    public function withRepository(string $fullName = 'acme/shop', string $branch = 'main'): static
    {
        return $this->afterCreating(function (Project $project) use ($fullName, $branch) {
            if ($project->source_type !== Project::SOURCE_IMAGE) {
                $project->repository()->create([
                    'provider' => $project->source_type,
                    'full_name' => $project->source_type === Project::SOURCE_GITHUB ? $fullName : null,
                    'url' => 'https://github.com/'.$fullName.'.git',
                    'branch' => $branch,
                ]);
            }
        });
    }
}
