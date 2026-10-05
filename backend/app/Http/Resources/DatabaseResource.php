<?php

namespace App\Http\Resources;

use App\Models\ProjectDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectDatabase */
class DatabaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'engine' => $this->engine,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'host' => $this->appHost(),
            'port' => $this->port,
            'database' => $this->name,
            'username' => $this->username,
            'connection_string' => $this->connectionUrl(false),
            'size_bytes' => $this->size_bytes,
            'project' => $this->project ? ['name' => $this->project->name, 'slug' => $this->project->slug] : null,
            'last_backup' => $this->whenLoaded('backups', fn () => ($b = $this->backups->first()) ? [
                'status' => $b->status->value, 'created_at' => $b->created_at?->toIso8601String(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
