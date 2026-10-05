<?php

namespace App\Services\Monitoring;

use App\Services\Docker\DockerClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ContainerStats
{
    public function __construct(private readonly DockerClient $docker) {}

    /**
     * Resource usage of a container, cached briefly so dashboards polling every
     * few seconds do not hammer the Docker daemon.
     *
     * @return array{cpu_percent: float, memory_used_bytes: int, memory_limit_bytes: int, net_rx_bytes: int, net_tx_bytes: int, pids: int}|null
     */
    public function forContainer(string $container, int $cacheSeconds = 10): ?array
    {
        return Cache::remember('privatecloud:stats:'.$container, $cacheSeconds, function () use ($container) {
            try {
                return self::parse($this->docker->containerStats($container));
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $stats  Docker stats payload
     * @return array{cpu_percent: float, memory_used_bytes: int, memory_limit_bytes: int, net_rx_bytes: int, net_tx_bytes: int, pids: int}|null
     */
    public static function parse(array $stats): ?array
    {
        if (empty($stats['cpu_stats'])) {
            return null;
        }
        $cpuDelta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
        $cpus = $stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? [1]);
        $cpuPercent = ($systemDelta > 0 && $cpuDelta >= 0) ? ($cpuDelta / $systemDelta) * $cpus * 100 : 0.0;

        $memUsage = (int) ($stats['memory_stats']['usage'] ?? 0);
        // Exclude page cache like `docker stats` does (cgroup v2: inactive_file, v1: total_inactive_file).
        $cache = (int) ($stats['memory_stats']['stats']['inactive_file'] ?? $stats['memory_stats']['stats']['total_inactive_file'] ?? 0);

        $rx = $tx = 0;
        foreach ($stats['networks'] ?? [] as $network) {
            $rx += (int) ($network['rx_bytes'] ?? 0);
            $tx += (int) ($network['tx_bytes'] ?? 0);
        }

        return [
            'cpu_percent' => round($cpuPercent, 2),
            'memory_used_bytes' => max(0, $memUsage - $cache),
            'memory_limit_bytes' => (int) ($stats['memory_stats']['limit'] ?? 0),
            'net_rx_bytes' => $rx,
            'net_tx_bytes' => $tx,
            'pids' => (int) ($stats['pids_stats']['current'] ?? 0),
        ];
    }
}
