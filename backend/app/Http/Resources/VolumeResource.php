<?php

namespace App\Http\Resources;

use App\Models\Volume;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Volume */
class VolumeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mount_path' => $this->mount_path,
            'docker_name' => $this->docker_name,
            'size_bytes' => $this->size_bytes,
            'size_checked_at' => $this->size_checked_at?->toIso8601String(),
            'project' => $this->whenLoaded('project', fn () => ['name' => $this->project->name, 'slug' => $this->project->slug]),
        ];
    }
}
