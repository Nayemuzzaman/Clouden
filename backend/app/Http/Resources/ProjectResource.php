<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $repository = $this->whenLoaded('repository');
        $primary = $this->relationLoaded('domains') ? $this->primaryDomain() : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'source_type' => $this->source_type,
            'image' => $this->image,
            'dockerfile_path' => $this->dockerfile_path,
            'build_context' => $this->build_context,
            'port' => $this->port,
            'memory_limit_mb' => $this->memory_limit_mb,
            'cpu_limit' => $this->cpu_limit,
            'health_check' => [
                'type' => $this->health_check_type,
                'path' => $this->health_check_path,
                'status_min' => $this->health_check_status_min,
                'status_max' => $this->health_check_status_max,
                'timeout' => $this->health_check_timeout,
                'retries' => $this->health_check_retries,
                'interval' => $this->health_check_interval,
            ],
            'auto_deploy' => $this->auto_deploy,
            'image_retention' => $this->image_retention,
            'backup_schedule' => $this->backup_schedule,
            'backup_time' => $this->backup_time,
            'backup_retention' => $this->backup_retention,
            'deleting' => $this->isDeleting(),
            'repository' => $repository instanceof Repository ? [
                'provider' => $repository->provider,
                'full_name' => $repository->full_name,
                'url' => $repository->url,
                'branch' => $repository->branch,
                'latest_commit' => $repository->latest_commit_sha ? [
                    'sha' => $repository->latest_commit_sha,
                    'short_sha' => substr($repository->latest_commit_sha, 0, 7),
                    'message' => $repository->latest_commit_message,
                    'author' => $repository->latest_commit_author,
                    'committed_at' => $repository->latest_commit_at?->toIso8601String(),
                ] : null,
                'last_checked_at' => $repository->last_checked_at?->toIso8601String(),
                'last_check_error' => $repository->last_check_error,
                'webhook_installed' => $repository->webhook_id !== null,
            ] : null,
            'primary_domain' => $primary?->hostname,
            'domains' => DomainResource::collection($this->whenLoaded('domains')),
            'current_deployment' => new DeploymentResource($this->whenLoaded('currentDeployment')),
            'latest_deployment' => new DeploymentResource($this->whenLoaded('latestDeployment')),
            'database' => $this->relationLoaded('databases') && $this->databases->first() ? new DatabaseResource($this->databases->first()) : null,
            'volumes' => VolumeResource::collection($this->whenLoaded('volumes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
