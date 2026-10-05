<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_local
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property Carbon|null $last_seen_at
 */
class Server extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'driver', 'is_local', 'public_ipv4', 'public_ipv6', 'status', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_local' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    /** The server the control plane runs on. Created on first use. */
    public static function local(): self
    {
        return self::query()->where('is_local', true)->orderBy('id')->first()
            ?? self::query()->create(['name' => 'Local server', 'driver' => 'local', 'is_local' => true]);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
