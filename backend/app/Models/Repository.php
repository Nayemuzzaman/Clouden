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
 * @property string|null $visibility
 * @property string|null $access_status
 * @property string|null $webhook_status
 * @property string|null $webhook_error
 * @property string|null $webhook_url
 * @property Carbon|null $webhook_last_delivery_at
 * @property-read Project $project
 */
class Repository extends Model
{
    protected $fillable = [
        'project_id', 'provider', 'full_name', 'url', 'branch', 'latest_commit_sha', 'latest_commit_message',
        'latest_commit_author', 'latest_commit_at', 'last_checked_at', 'last_check_error', 'webhook_id',
        'visibility', 'access_status', 'webhook_status', 'webhook_error', 'webhook_url', 'webhook_last_delivery_at',
    ];

    public const ACCESS_OK = 'ok';

    public const ACCESS_AUTH_FAILED = 'auth_failed';

    public const ACCESS_NO_ACCESS = 'no_access';

    public const ACCESS_NO_CONTENTS = 'no_contents_permission';

    public const ACCESS_BRANCH_MISSING = 'branch_missing';

    public const ACCESS_RATE_LIMITED = 'rate_limited';

    public const ACCESS_UNREACHABLE = 'unreachable';

    public const WEBHOOK_ACTIVE = 'active';

    public const WEBHOOK_FAILED = 'failed';

    public const WEBHOOK_MANUAL = 'manual';

    public const WEBHOOK_ORPHANED = 'orphaned';

    protected function casts(): array
    {
        return ['latest_commit_at' => 'datetime', 'last_checked_at' => 'datetime', 'webhook_last_delivery_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isGitHub(): bool
    {
        return $this->provider === Project::SOURCE_GITHUB;
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /** owner/name for GitHub, the URL otherwise: what deployments record as their source. */
    public function displayName(): string
    {
        return $this->full_name ?: $this->url;
    }
}
