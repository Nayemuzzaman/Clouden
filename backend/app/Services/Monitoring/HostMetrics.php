<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;

/**
 * Reads Linux host metrics from procfs. Inside the platform container the
 * host's /proc is mounted read-only (PC_HOST_PROC=/host/proc). Every reader
 * degrades to null when a file is unavailable (e.g. macOS development).
 */
class HostMetrics
{
    public function __construct(private readonly ?string $proc = null, private readonly ?string $diskPath = null) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $memory = $this->memory();
        $disk = $this->disk();
        $load = $this->load();

        return [
            'cpu_percent' => $this->cpuPercent(),
            'cpu_cores' => $this->cpuCores(),
            'memory_total_bytes' => $memory['total'] ?? null,
            'memory_used_bytes' => $memory['used'] ?? null,
            'memory_available_bytes' => $memory['available'] ?? null,
            'swap_total_bytes' => $memory['swap_total'] ?? null,
            'swap_used_bytes' => $memory['swap_used'] ?? null,
            'disk_total_bytes' => $disk['total'] ?? null,
            'disk_used_bytes' => $disk['used'] ?? null,
            'disk_free_bytes' => $disk['free'] ?? null,
            'load' => $load,
            'uptime_seconds' => $this->uptime(),
            'network' => $this->network(),
            'hostname' => $this->hostname(),
            'kernel' => $this->read('sys/kernel/osrelease'),
        ];
    }

    public function cpuPercent(): ?float
    {
        $sample = $this->cpuSample();
        if ($sample === null) {
            return null;
        }
        $previous = Cache::get('privatecloud:cpu-sample');
        if (! is_array($previous) || (microtime(true) - $previous['at']) > 300 || (microtime(true) - $previous['at']) < 0.2) {
            usleep(250_000);
            $previous = $sample;
            $sample = $this->cpuSample();
        }
        Cache::put('privatecloud:cpu-sample', $sample, 600);

        $total = $sample['total'] - $previous['total'];
        $idle = $sample['idle'] - $previous['idle'];
        if ($total <= 0) {
            return 0.0;
        }

        return round(max(0, min(100, (1 - $idle / $total) * 100)), 1);
    }

    /** @return array{total: int, idle: int, at: float}|null */
    private function cpuSample(): ?array
    {
        $stat = $this->read('stat');
        if ($stat === null || ! preg_match('/^cpu\s+(.+)$/m', $stat, $m)) {
            return null;
        }
        $values = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0); // idle + iowait
        // guest/guest_nice are already included in user/nice
        $total = array_sum(array_slice($values, 0, 8));

        return ['total' => $total, 'idle' => $idle, 'at' => microtime(true)];
    }

    public function cpuCores(): ?int
    {
        $info = $this->read('cpuinfo');
        if ($info === null) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $info);

        return $count > 0 ? $count : null;
    }

    /** @return array<string, int>|null */
    public function memory(): ?array
    {
        $info = $this->read('meminfo');
        if ($info === null) {
            return null;
        }
        $values = [];
        foreach (explode("\n", $info) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }
        if (! isset($values['MemTotal'])) {
            return null;
        }
        $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Cached'] ?? 0));

        return [
            'total' => $values['MemTotal'],
            'available' => $available,
            'used' => $values['MemTotal'] - $available,
            'swap_total' => $values['SwapTotal'] ?? 0,
            'swap_used' => ($values['SwapTotal'] ?? 0) - ($values['SwapFree'] ?? 0),
        ];
    }

    /** @return array{total: int, free: int, used: int}|null */
    public function disk(): ?array
    {
        $path = $this->diskPath ?? (string) config('privatecloud.monitoring.disk_path');
        if (! is_dir($path)) {
            $path = '/';
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false) {
            return null;
        }

        return ['total' => (int) $total, 'free' => (int) $free, 'used' => (int) ($total - $free)];
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    public function load(): ?array
    {
        $load = $this->read('loadavg');
        if ($load === null) {
            $sys = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

            return $sys ? [round($sys[0], 2), round($sys[1], 2), round($sys[2], 2)] : null;
        }
        $parts = explode(' ', $load);

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }

    public function uptime(): ?int
    {
        $uptime = $this->read('uptime');

        return $uptime === null ? null : (int) explode(' ', $uptime)[0];
    }

    /** @return array{rx_bytes: int, tx_bytes: int, rx_rate: ?float, tx_rate: ?float}|null */
    public function network(): ?array
    {
        // /proc/1/net/dev shows the host network namespace (PID 1 of the host's PID namespace).
        $dev = $this->read('1/net/dev') ?? $this->read('net/dev');
        if ($dev === null) {
            return null;
        }
        $rx = 0;
        $tx = 0;
        foreach (explode("\n", $dev) as $line) {
            if (! preg_match('/^\s*([^:]+):\s*(.+)$/', $line, $m)) {
                continue;
            }
            $iface = trim($m[1]);
            if ($iface === 'lo' || str_starts_with($iface, 'veth') || str_starts_with($iface, 'docker') || str_starts_with($iface, 'br-')) {
                continue;
            }
            $cols = preg_split('/\s+/', trim($m[2])) ?: [];
            $rx += (int) ($cols[0] ?? 0);
            $tx += (int) ($cols[8] ?? 0);
        }

        $now = microtime(true);
        $previous = Cache::get('privatecloud:net-sample');
        Cache::put('privatecloud:net-sample', ['rx' => $rx, 'tx' => $tx, 'at' => $now], 600);
        $rxRate = $txRate = null;
        if (is_array($previous) && ($elapsed = $now - $previous['at']) > 0.5 && $rx >= $previous['rx']) {
            $rxRate = round(($rx - $previous['rx']) / $elapsed, 1);
            $txRate = round(($tx - $previous['tx']) / $elapsed, 1);
        }

        return ['rx_bytes' => $rx, 'tx_bytes' => $tx, 'rx_rate' => $rxRate, 'tx_rate' => $txRate];
    }

    private function hostname(): ?string
    {
        return $this->read('sys/kernel/hostname') ?? (gethostname() ?: null);
    }

    private function read(string $file): ?string
    {
        $path = rtrim($this->proc ?? (string) config('privatecloud.monitoring.proc_path'), '/').'/'.$file;
        if (! @is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : trim($content);
    }
}
