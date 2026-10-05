<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $project_id
 * @property string $engine
 * @property string $name
 * @property string $username
 * @property string $password
 * @property string $host
 * @property int $port
 * @property string $status
 * @property string|null $last_error
 * @property int|null $size_bytes
 * @property Carbon|null $created_at
 * @property-read Project|null $project
 */
class ProjectDatabase extends Model
{
    use HasFactory;

    protected $fillable = ['server_id', 'project_id', 'engine', 'name', 'username', 'password', 'host', 'port', 'status', 'last_error', 'size_bytes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectDatabase $db) {
            $db->uuid ??= (string) Str::uuid();
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

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<Backup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /** Host name applications should use (the network alias inside project networks). */
    public function appHost(): string
    {
        return (string) config('privatecloud.apps_db.app_host_alias');
    }

    public function connectionUrl(bool $withPassword): string
    {
        $password = $withPassword ? rawurlencode($this->password) : '********';

        return sprintf('postgresql://%s:%s@%s:%d/%s', $this->username, $password, $this->appHost(), $this->port, $this->name);
    }
}
