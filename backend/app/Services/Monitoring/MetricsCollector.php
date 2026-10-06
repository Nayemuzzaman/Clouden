<?php

namespace App\Services\Monitoring;

use App\Enums\ProjectStatus;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Notifier;

/** Periodically records server and per-project metrics and raises resource warnings. */
class MetricsCollector
{
    public function __construct(
        private readonly HostMetrics $host,
        private readonly ContainerStats $stats,
        private readonly Notifier $notifier,
    ) {}

    public function collect(): void
    {
        $server = Server::local();
        $snapshot = $this->host->snapshot();
        $now = now();

        Metric::query()->create([
            'server_id' => $server->id,
            'cpu_percent' => $snapshot['cpu_percent'],
            'memory_used_bytes' => $snapshot['memory_used_bytes'],
            'memory_total_bytes' => $snapshot['memory_total_bytes'],
            'disk_used_bytes' => $snapshot['disk_used_bytes'],
            'disk_total_bytes' => $snapshot['disk_total_bytes'],
            'load_1' => $snapshot['load'][0] ?? null,
            'net_rx_bytes' => $snapshot['network']['rx_bytes'] ?? null,
            'net_tx_bytes' => $snapshot['network']['tx_bytes'] ?? null,
            'recorded_at' => $now,
        ]);
        $server->update(['last_seen_at' => $now]);

        $projects = Project::query()->with('currentDeployment')->where('status', ProjectStatus::Running->value)->get();
        foreach ($projects as $project) {
            $container = $project->currentDeployment?->container_name;
            if (! $container) {
                continue;
            }
            $usage = $this->stats->forContainer($container, 5);
            if ($usage === null) {
                continue;
            }
            Metric::query()->create([
                'server_id' => $server->id,
                'project_id' => $project->id,
                'cpu_percent' => $usage['cpu_percent'],
                'memory_used_bytes' => $usage['memory_used_bytes'],
                'memory_total_bytes' => $usage['memory_limit_bytes'],
                'net_rx_bytes' => $usage['net_rx_bytes'],
                'net_tx_bytes' => $usage['net_tx_bytes'],
                'recorded_at' => $now,
            ]);
        }

        $this->checkThresholds($snapshot);
    }

    public function prune(): int
    {
        return Metric::query()->where('recorded_at', '<', now()->subDays(max(1, (int) config('privatecloud.monitoring.retention_days'))))->delete();
    }

    /** @return array{cpu: int, memory: int, disk: int} */
    public static function thresholds(): array
    {
        $defaults = config('privatecloud.monitoring.thresholds');
        $stored = Setting::get('monitoring.thresholds', []);

        return [
            'cpu' => (int) ($stored['cpu'] ?? $defaults['cpu']),
            'memory' => (int) ($stored['memory'] ?? $defaults['memory']),
            'disk' => (int) ($stored['disk'] ?? $defaults['disk']),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function checkThresholds(array $snapshot): void
    {
        $t = self::thresholds();
        $cooldown = (int) config('privatecloud.monitoring.alert_cooldown_minutes');

        if ($snapshot['memory_total_bytes']) {
            $memory = $snapshot['memory_used_bytes'] / $snapshot['memory_total_bytes'] * 100;
            if ($memory >= $t['memory']) {
                $this->notifier->notifyOnce('memory-high', $cooldown, 'server.memory_high', 'Server memory is high', sprintf('Memory usage is %.0f%% (warning threshold %d%%). Consider lowering project memory limits or upgrading the server.', $memory, $t['memory']), 'warning', '/server');
            }
        }
        if ($snapshot['disk_total_bytes']) {
            $disk = $snapshot['disk_used_bytes'] / $snapshot['disk_total_bytes'] * 100;
            if ($disk >= $t['disk']) {
                $this->notifier->notifyOnce('disk-high', $cooldown, 'server.disk_high', 'Server disk is filling up', sprintf('Disk usage is %.0f%% (warning threshold %d%%). Builds fail when the disk is full. Remove old backups or clean up unused images on the Server page.', $disk, $t['disk']), 'warning', '/server');
            }
        }
        if ($snapshot['cpu_percent'] !== null) {
            // Require the threshold to be exceeded for 5 consecutive samples to ignore short build spikes.
            $recent = Metric::query()->whereNull('project_id')->latest('recorded_at')->limit(5)->pluck('cpu_percent');
            if ($recent->count() === 5 && $recent->every(fn ($v) => $v !== null && $v >= $t['cpu'])) {
                $this->notifier->notifyOnce('cpu-high', $cooldown, 'server.cpu_high', 'Server CPU is high', sprintf('CPU usage has been above %d%% for 5 minutes.', $t['cpu']), 'warning', '/server');
            }
        }
    }
}
