<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property string $name
 * @property string $slug
 * @property ProjectStatus $status
 * @property string $source_type
 * @property string|null $image
 * @property string $dockerfile_path
 * @property string $build_context
 * @property int $port
 * @property int $memory_limit_mb
 * @property float $cpu_limit
 * @property string $health_check_type
 * @property string $health_check_path
 * @property int $health_check_status_min
 * @property int $health_check_status_max
 * @property int $health_check_timeout
 * @property int $health_check_retries
 * @property int $health_check_interval
 * @property bool $auto_deploy
 * @property string|null $webhook_secret
 * @property int $image_retention
 * @property string $backup_schedule
 * @property string $backup_time
 * @property int $backup_retention
 * @property Carbon|null $last_scheduled_backup_at
 * @property int|null $current_deployment_id
 * @property Carbon|null $rolled_back_at
 * @property string|null $rollback_hold_sha
 * @property Carbon|null $deleting_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Repository|null $repository
 * @property-read Deployment|null $currentDeployment
 * @property-read Deployment|null $latestDeployment
 */
class Project extends Model
{
    use HasFactory;

    public const SOURCE_GITHUB = 'github';

    public const SOURCE_GIT = 'git';

    public const SOURCE_IMAGE = 'image';

    protected $fillable = [
        'server_id', 'name', 'slug', 'status', 'source_type', 'image', 'dockerfile_path', 'build_context', 'port',
        'memory_limit_mb', 'cpu_limit', 'health_check_type', 'health_check_path', 'health_check_status_min',
        'health_check_status_max', 'health_check_timeout', 'health_check_retries', 'health_check_interval',
        'auto_deploy', 'webhook_secret', 'image_retention', 'backup_schedule', 'backup_time', 'backup_retention',
        'last_scheduled_backup_at', 'current_deployment_id', 'deleting_at', 'rolled_back_at', 'rollback_hold_sha',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'auto_deploy' => 'boolean',
            'webhook_secret' => 'encrypted',
            'cpu_limit' => 'float',
            'port' => 'integer',
            'memory_limit_mb' => 'integer',
            'deleting_at' => 'datetime',
            'last_scheduled_backup_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasOne<Repository, $this> */
    public function repository(): HasOne
    {
        return $this->hasOne(Repository::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function currentDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'current_deployment_id');
    }

    /** @return HasOne<Deployment, $this> */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany('number');
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<EnvironmentVariable, $this> */
    public function environmentVariables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    /** @return HasMany<Volume, $this> */
    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    /** @return HasMany<ProjectDatabase, $this> */
    public function databases(): HasMany
    {
        return $this->hasMany(ProjectDatabase::class);
    }

    /** @return HasMany<Backup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /** @return HasMany<Container, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    /** Name of the lock held by the deployment job (Laravel's WithoutOverlapping middleware). */
    public static function lockName(int $projectId): string
    {
        return 'laravel-queue-overlap:project:'.$projectId;
    }

    public function hasActiveDeployment(): bool
    {
        return $this->deployments()->whereIn('status', DeploymentStatus::activeValues())->exists();
    }

    public function isDeleting(): bool
    {
        return $this->deleting_at !== null;
    }

    /** Name of the per-project Docker network. */
    public function networkName(): string
    {
        return config('privatecloud.docker.prefix').'-net-'.$this->slug;
    }

    public function primaryDomain(): ?Domain
    {
        $domains = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();

        return $domains->sortByDesc('is_primary')->first();
    }
}
