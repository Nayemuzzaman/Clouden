<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $deployment_id
 * @property string $stream
 * @property string $level
 * @property string $line
 * @property Carbon|null $logged_at
 */
class DeploymentLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['deployment_id', 'stream', 'level', 'line', 'logged_at'];

    protected function casts(): array
    {
        return ['logged_at' => 'datetime'];
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
