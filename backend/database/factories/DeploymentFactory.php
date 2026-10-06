<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deployment> */
class DeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'number' => fn (array $attrs) => (int) Deployment::query()->where('project_id', $attrs['project_id'])->max('number') + 1,
            'type' => Deployment::TYPE_DEPLOY,
            'trigger' => 'manual',
            'status' => DeploymentStatus::Queued,
            'queued_at' => now(),
        ];
    }

    /** A successful deployment whose image and container exist. */
    public function live(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => DeploymentStatus::Success,
            'commit_sha' => str_repeat('a', 40),
            'commit_message' => 'Initial version',
            'image_available' => true,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
    }
}
