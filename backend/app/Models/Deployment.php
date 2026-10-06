<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property string $type
 * @property string $trigger
 * @property DeploymentStatus $status
 * @property string|null $branch
 * @property string|null $commit_sha
 * @property string|null $commit_message
 * @property string|null $commit_author
 * @property Carbon|null $commit_committed_at
 * @property string|null $repository
 * @property string|null $source_visibility
 * @property string|null $webhook_delivery_id
 * @property string|null $image_tag
 * @property string|null $image_id
 * @property bool $image_available
 * @property string|null $container_id
 * @property string|null $container_name
 * @property int|null $rollback_of_id
 * @property int|null $initiated_by
 * @property string|null $failure_stage
 * @property string|null $failure_reason
 * @property string|null $failure_detail
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $build_started_at
 * @property Carbon|null $build_finished_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read Project $project
 * @property-read User|null $initiator
 * @property-read Deployment|null $rollbackOf
 */
class Deployment extends Model
{
    use HasFactory;

    public const TYPE_DEPLOY = 'deploy';

    public const TYPE_ROLLBACK = 'rollback';

    public const TYPE_REDEPLOY = 'redeploy';

    protected $fillable = [
        'project_id', 'number', 'type', 'trigger', 'status', 'branch', 'commit_sha', 'commit_message', 'commit_author',
        'commit_committed_at', 'repository', 'source_visibility', 'webhook_delivery_id',
        'image_tag', 'image_id', 'image_available', 'container_id', 'container_name', 'rollback_of_id', 'initiated_by',
        'failure_stage', 'failure_reason', 'failure_detail', 'queued_at', 'started_at', 'build_started_at',
        'build_finished_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'image_available' => 'boolean',
            'commit_committed_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'build_started_at' => 'datetime',
            'build_finished_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @return BelongsTo<Deployment, $this> */
    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }

    /** @return HasMany<DeploymentLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class);
    }

    public function isProduction(): bool
    {
        return $this->project?->current_deployment_id === $this->id;
    }

    public function buildDurationSeconds(): ?int
    {
        if (! $this->build_started_at || ! $this->build_finished_at) {
            return null;
        }

        return (int) $this->build_started_at->diffInSeconds($this->build_finished_at);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    public function shortSha(): ?string
    {
        return $this->commit_sha ? substr($this->commit_sha, 0, 7) : null;
    }
}
