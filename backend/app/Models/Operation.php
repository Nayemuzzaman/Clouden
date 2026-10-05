<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property JobStatus $status
 * @property int|null $project_id
 * @property string|null $message
 * @property string|null $error
 * @property array<string, mixed>|null $meta
 * @property int|null $initiated_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read Model|null $target
 */
class Operation extends Model
{
    protected $fillable = ['type', 'status', 'project_id', 'target_type', 'target_id', 'message', 'error', 'meta', 'initiated_by', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Operation $op) {
            $op->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function markRunning(?string $message = null): void
    {
        $this->update(['status' => JobStatus::Running, 'started_at' => now(), 'message' => $message]);
    }

    public function progress(string $message): void
    {
        $this->update(['message' => $message]);
    }

    public function markSucceeded(?string $message = null): void
    {
        $this->update(['status' => JobStatus::Success, 'finished_at' => now(), 'message' => $message ?? $this->message]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => JobStatus::Failed, 'finished_at' => now(), 'error' => $error]);
    }
}
