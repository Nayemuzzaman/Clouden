<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $key
 * @property string $value
 * @property bool $is_secret
 * @property bool $is_system
 * @property bool $available_at_build
 * @property Carbon|null $updated_at
 */
class EnvironmentVariable extends Model
{
    protected $fillable = ['project_id', 'key', 'value', 'is_secret', 'is_system', 'available_at_build'];

    // The value is never serialized implicitly; API resources decide what to show.
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
            'is_system' => 'boolean',
            'available_at_build' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
