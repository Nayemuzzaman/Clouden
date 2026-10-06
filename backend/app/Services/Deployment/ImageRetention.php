<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;

/**
 * Keeps the images of the most recent successful deployments (configurable per
 * project) so they can be rolled back to, and removes older ones to save disk.
 */
class ImageRetention
{
    public function __construct(private readonly DockerClient $docker) {}

    /** @param callable(string): void|null $log */
    public function prune(Project $project, ?callable $log = null): void
    {
        if ($project->source_type === Project::SOURCE_IMAGE) {
            return; // pulled images may be shared; never remove them automatically
        }

        $keep = max(1, (int) $project->image_retention);
        $currentTag = $project->currentDeployment?->image_tag;

        $deployments = $project->deployments()
            ->where('image_available', true)
            ->whereNotNull('image_tag')
            ->whereNotIn('status', DeploymentStatus::activeValues())
            ->orderByDesc('number')
            ->get();

        $kept = [];
        foreach ($deployments as $deployment) {
            $tag = (string) $deployment->image_tag;
            if ($tag === $currentTag) {
                continue;
            }
            $keepIt = $deployment->status === DeploymentStatus::Success && (in_array($tag, $kept, true) || count($kept) < $keep);
            if ($keepIt) {
                $kept[] = $tag;

                continue;
            }
            if (in_array($tag, $kept, true)) {
                continue;
            }
            try {
                $this->docker->removeImage($tag);
                Deployment::query()->where('project_id', $project->id)->where('image_tag', $tag)->update(['image_available' => false]);
                if ($log) {
                    $log("Removed old image {$tag} (keeping the last {$keep} for rollback)");
                }
            } catch (DockerException) {
                // In use by a container or already gone: leave it for the next run.
            }
        }
    }
}
