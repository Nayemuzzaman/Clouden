<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $project_id
 * @property int|null $project_database_id
 * @property int|null $volume_id
 * @property string $type
 * @property string $trigger
 * @property JobStatus $status
 * @property string|null $label
 * @property string $storage
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property string|null $error
 * @property int|null $initiated_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property-read Project|null $project
 * @property-read ProjectDatabase|null $database
 * @property-read Volume|null $volume
 */
class Backup extends Model
{
    use HasFactory;

    public const TYPE_DATABASE = 'database';

    public const TYPE_VOLUME = 'volume';

    protected $fillable = [
        'project_id', 'project_database_id', 'volume_id', 'type', 'trigger', 'status', 'label', 'storage', 'path',
        'size_bytes', 'checksum_sha256', 'error', 'initiated_by', 'started_at', 'finished_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Backup $backup) {
            $backup->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ProjectDatabase::class, 'project_database_id');
    }

    /** @return BelongsTo<Volume, $this> */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }
}
