<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property int|null $project_id
 * @property float|null $cpu_percent
 * @property int|null $memory_used_bytes
 * @property int|null $memory_total_bytes
 * @property int|null $disk_used_bytes
 * @property int|null $disk_total_bytes
 * @property float|null $load_1
 * @property int|null $net_rx_bytes
 * @property int|null $net_tx_bytes
 * @property Carbon $recorded_at
 */
class Metric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'project_id', 'cpu_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes',
        'disk_total_bytes', 'load_1', 'net_rx_bytes', 'net_tx_bytes', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'cpu_percent' => 'float', 'load_1' => 'float'];
    }
}
