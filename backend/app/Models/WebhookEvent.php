<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $ref
 * @property string|null $commit_sha
 * @property string $status
 * @property string|null $reason
 */
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'provider', 'delivery_id', 'event', 'ref', 'commit_sha', 'status', 'reason', 'deployment_id'];
}
