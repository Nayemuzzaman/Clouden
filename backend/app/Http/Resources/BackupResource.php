<?php

namespace App\Http\Resources;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backup */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'trigger' => $this->trigger,
            'status' => $this->status->value,
            'label' => $this->label,
            'project' => $this->project ? ['name' => $this->project->name, 'slug' => $this->project->slug] : null,
            'database' => $this->database?->name,
            'volume' => $this->volume?->name,
            'source_exists' => $this->type === Backup::TYPE_DATABASE ? $this->project_database_id !== null : $this->volume_id !== null,
            'storage' => $this->storage,
            'location' => $this->path,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
