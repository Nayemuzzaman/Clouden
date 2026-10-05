<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $docker_id
 * @property string $name
 * @property string $image
 * @property string $role
 * @property string $state
 * @property Carbon|null $removed_at
 */
class Container extends Model
{
    public const ROLE_CANDIDATE = 'candidate';

    public const ROLE_PRODUCTION = 'production';

    public const ROLE_RETIRED = 'retired';

    protected $fillable = ['server_id', 'project_id', 'deployment_id', 'docker_id', 'name', 'image', 'role', 'state', 'removed_at'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
