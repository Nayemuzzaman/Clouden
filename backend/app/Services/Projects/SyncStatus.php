<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Repository;

/**
 * How production relates to the production branch:
 *
 *   synced       production runs the commit at the head of the branch
 *   out_of_sync  the branch head is a different commit (not deployed yet, or
 *                production was intentionally rolled back)
 *   deploying    a deployment is in progress
 *   failed       the latest attempt to deploy the branch head failed; the
 *                previous production version is still live
 *   unknown      PrivateCloud has not read the branch yet, or cannot read it
 *
 * The branch head is the last commit PrivateCloud learned about from a push
 * webhook or from asking GitHub ("Check for new commits", "Deploy Latest"); the
 * dashboard shows when that was. Nothing here calls GitHub.
 */
final class SyncStatus
{
    /** @return array<string, mixed>|null null for projects that do not deploy from a repository */
    public static function for(Project $project): ?array
    {
        $repository = $project->relationLoaded('repository') ? $project->repository : $project->repository()->first();
        if (! $repository instanceof Repository || $project->source_type === Project::SOURCE_IMAGE) {
            return null;
        }
        $production = $project->relationLoaded('currentDeployment') ? $project->currentDeployment : $project->currentDeployment()->first();
        $latest = $project->relationLoaded('latestDeployment') ? $project->latestDeployment : $project->latestDeployment()->first();

        $desired = $repository->latest_commit_sha;
        $productionSha = $production?->commit_sha;
        $rolledBack = $project->rolled_back_at !== null && $desired !== null && $productionSha !== $desired;
        $attention = in_array($repository->access_status, [
            Repository::ACCESS_AUTH_FAILED, Repository::ACCESS_NO_ACCESS, Repository::ACCESS_NO_CONTENTS, Repository::ACCESS_BRANCH_MISSING,
        ], true) ? $repository->access_status : null;

        $failed = $latest !== null && $latest->status === DeploymentStatus::Failed && $latest->type === Deployment::TYPE_DEPLOY
            && $latest->id !== $production?->id && ($desired === null || $latest->commit_sha === $desired);

        [$state, $message] = match (true) {
            $latest !== null && $latest->status->isActive() => ['deploying', 'Deploying '.($latest->shortSha() ?? 'a new version').'. The current version keeps serving traffic until the new one is healthy.'],
            $failed => ['failed', 'The latest commit of '.$repository->branch.' failed to deploy.'.($production ? ' Your previous production version is still running.' : '')],
            $desired === null => ['unknown', $attention ? self::attentionMessage($attention, $repository) : 'PrivateCloud has not read '.$repository->branch.' yet. Check for new commits.'],
            $production === null => ['out_of_sync', 'Not deployed yet.'],
            $productionSha === $desired => ['synced', 'Production runs the latest commit of '.$repository->branch.'.'],
            $rolledBack => ['out_of_sync', 'Production was intentionally rolled back. Auto deploy resumes with the next push to '.$repository->branch.', or click Deploy Latest.'],
            default => ['out_of_sync', $repository->branch.' has a newer commit than production.'],
        };

        return [
            'state' => $state,
            'message' => $message,
            'branch' => $repository->branch,
            'desired' => $desired ? [
                'sha' => $desired,
                'short_sha' => substr($desired, 0, 7),
                'message' => $repository->latest_commit_message,
                'author' => $repository->latest_commit_author,
                'committed_at' => $repository->latest_commit_at?->toIso8601String(),
            ] : null,
            'production' => $production ? [
                'deployment_id' => $production->id,
                'number' => $production->number,
                'sha' => $productionSha,
                'short_sha' => $production->shortSha(),
                'message' => $production->commit_message,
                'branch' => $production->branch,
                'deployed_at' => $production->finished_at?->toIso8601String(),
            ] : null,
            'deploying' => $latest !== null && $latest->status->isActive() ? [
                'deployment_id' => $latest->id, 'number' => $latest->number, 'sha' => $latest->commit_sha, 'short_sha' => $latest->shortSha(), 'status' => $latest->status->value,
            ] : null,
            'failed' => $failed ? [
                'deployment_id' => $latest->id, 'number' => $latest->number, 'sha' => $latest->commit_sha, 'short_sha' => $latest->shortSha(),
                'stage' => $latest->failure_stage, 'reason' => $latest->failure_reason, 'finished_at' => $latest->finished_at?->toIso8601String(),
            ] : null,
            'rolled_back' => $rolledBack,
            'rolled_back_at' => $rolledBack ? $project->rolled_back_at?->toIso8601String() : null,
            'attention' => $attention,
            'attention_message' => $attention ? self::attentionMessage($attention, $repository) : null,
            'checked_at' => $repository->last_checked_at?->toIso8601String(),
        ];
    }

    private static function attentionMessage(string $status, Repository $repository): string
    {
        return match ($status) {
            Repository::ACCESS_AUTH_FAILED => 'GitHub rejected the saved access token (expired or revoked). Your running application is unaffected. Reconnect GitHub in Settings.',
            Repository::ACCESS_NO_ACCESS => 'PrivateCloud can no longer access '.$repository->displayName().' (it may have been made private, renamed, or the token lost access). Your running application is unaffected.',
            Repository::ACCESS_NO_CONTENTS => 'The GitHub token cannot read the code of '.$repository->displayName().' (fine-grained tokens need "Contents: Read"). Your running application is unaffected.',
            Repository::ACCESS_BRANCH_MISSING => 'The production branch "'.$repository->branch.'" is unavailable (deleted or renamed). Current production remains online. Choose an existing branch in Settings.',
            default => (string) $repository->last_check_error,
        };
    }
}
