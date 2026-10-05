<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $hostname
 * @property bool $is_primary
 * @property string $dns_status
 * @property array<string, mixed>|null $dns_records
 * @property Carbon|null $dns_checked_at
 * @property string $cert_status
 * @property string|null $cert_error
 * @property Carbon|null $cert_expires_at
 * @property Carbon|null $cert_checked_at
 * @property Carbon|null $created_at
 * @property-read Project $project
 */
class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'hostname', 'is_primary', 'dns_status', 'dns_records', 'dns_checked_at', 'cert_status',
        'cert_error', 'cert_expires_at', 'cert_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'dns_records' => 'array',
            'dns_checked_at' => 'datetime',
            'cert_expires_at' => 'datetime',
            'cert_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
