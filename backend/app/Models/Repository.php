<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $provider
 * @property string|null $full_name
 * @property string $url
 * @property string $branch
 * @property string|null $latest_commit_sha
 * @property string|null $latest_commit_message
 * @property string|null $latest_commit_author
 * @property Carbon|null $latest_commit_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_check_error
 * @property int|null $webhook_id
 */
class Repository extends Model
{
    protected $fillable = [
        'project_id', 'provider', 'full_name', 'url', 'branch', 'latest_commit_sha', 'latest_commit_message',
        'latest_commit_author', 'latest_commit_at', 'last_checked_at', 'last_check_error', 'webhook_id',
    ];

    protected function casts(): array
    {
        return ['latest_commit_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
