<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $docker_name
 * @property string $mount_path
 * @property int|null $size_bytes
 * @property Carbon|null $size_checked_at
 * @property-read Project $project
 */
class Volume extends Model
{
    protected $fillable = ['project_id', 'name', 'docker_name', 'mount_path', 'size_bytes', 'size_checked_at'];

    protected function casts(): array
    {
        return ['size_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
