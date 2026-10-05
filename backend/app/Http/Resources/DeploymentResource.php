<?php

namespace App\Http\Resources;

use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deployment */
class DeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->failure_detail ? json_decode($this->failure_detail, true) : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'trigger' => $this->trigger,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->isActive(),
            'is_production' => $this->project && $this->project->current_deployment_id === $this->id,
            'branch' => $this->branch,
            'commit' => $this->commit_sha ? [
                'sha' => $this->commit_sha,
                'short_sha' => $this->shortSha(),
                'message' => $this->commit_message,
                'author' => $this->commit_author,
            ] : null,
            'image_tag' => $this->image_tag,
            'image_id' => $this->image_id ? substr($this->image_id, 7, 12) : null,
            'image_available' => $this->image_available,
            'container_name' => $this->container_name,
            'rollback_of' => $this->rollback_of_id ? ['id' => $this->rollback_of_id, 'number' => $this->rollbackOf?->number] : null,
            'initiated_by' => $this->initiator->name ?? ($this->trigger === 'webhook' ? 'GitHub push' : null),
            'failure' => $this->failure_reason ? [
                'stage' => $this->failure_stage,
                'reason' => $this->failure_reason,
                'step' => is_array($detail) ? ($detail['step'] ?? null) : null,
                'excerpt' => is_array($detail) ? ($detail['excerpt'] ?? null) : null,
            ] : null,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'build_duration_seconds' => $this->buildDurationSeconds(),
            'duration_seconds' => $this->durationSeconds(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
