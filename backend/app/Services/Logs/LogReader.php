<?php

namespace App\Services\Logs;

use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Environment\EnvironmentService;
use App\Services\Environment\LogRedactor;
use Illuminate\Support\Carbon;

/**
 * Reads application (container) logs and web/proxy access logs. Output is always
 * bounded so a noisy application cannot exhaust server or browser memory.
 */
class LogReader
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly EnvironmentService $environment,
    ) {}

    /**
     * @return list<array{timestamp: ?string, stream: string, level: ?string, message: string}>
     */
    public function containerLogs(Project $project, string $container, int $tail, ?string $since = null): array
    {
        $tail = max(1, min($tail, (int) config('privatecloud.logs.download_max_lines')));
        $redactor = new LogRedactor($this->environment->secretValues($project));
        $lines = $this->docker->containerLogs($container, $tail, $since);

        return array_map(fn ($l) => [
            'timestamp' => $l['timestamp'],
            'stream' => $l['stream'],
            'level' => self::detectLevel($l['line'], $l['stream']),
            'message' => mb_substr($redactor->redact($l['line']), 0, 8000),
        ], $lines);
    }

    /**
     * Tail of the Caddy JSON access log for a project.
     *
     * @return list<array{timestamp: ?string, stream: string, level: ?string, message: string}>
     */
    public function proxyLogs(Project $project, int $tail): array
    {
        $file = rtrim((string) config('privatecloud.caddy.logs_dir'), '/').'/'.$project->slug.'.access.log';
        $entries = [];
        foreach (self::tailFile($file, max(1, min($tail, 5000))) as $line) {
            $data = json_decode($line, true);
            if (! is_array($data)) {
                continue;
            }
            $req = $data['request'] ?? [];
            $status = (int) ($data['status'] ?? 0);
            $entries[] = [
                'timestamp' => isset($data['ts']) ? Carbon::createFromTimestamp((float) $data['ts'])->toIso8601ZuluString('millisecond') : null,
                'stream' => 'proxy',
                'level' => $status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info'),
                'message' => sprintf(
                    '%s %s %s %d %.0fms %s',
                    $req['client_ip'] ?? $req['remote_ip'] ?? '-',
                    $req['method'] ?? '-',
                    mb_substr((string) ($req['host'] ?? '').($req['uri'] ?? ''), 0, 500),
                    $status,
                    ((float) ($data['duration'] ?? 0)) * 1000,
                    mb_substr((string) ($req['headers']['User-Agent'][0] ?? ''), 0, 120),
                ),
            ];
        }

        return $entries;
    }

    public static function detectLevel(string $line, string $stream): ?string
    {
        if (preg_match('/\b(FATAL|CRITICAL|ERROR|ERR)\b|"level"\s*:\s*"(error|fatal)"|\blevel=(error|fatal)/i', $line)) {
            return 'error';
        }
        if (preg_match('/\b(WARN|WARNING)\b|"level"\s*:\s*"warn/i', $line)) {
            return 'warn';
        }
        if (preg_match('/\bDEBUG\b|"level"\s*:\s*"debug"/i', $line)) {
            return 'debug';
        }

        return $stream === 'stderr' ? 'warn' : 'info';
    }

    /**
     * Read the last $lines lines of a file without loading the whole file.
     *
     * @return list<string>
     */
    public static function tailFile(string $path, int $lines): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $buffer = '';
        $chunk = 65536;
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;
            if (strlen($buffer) > 20 * 1024 * 1024) {
                break;
            }
        }
        fclose($handle);
        $all = array_values(array_filter(explode("\n", $buffer), fn ($l) => $l !== ''));

        return array_slice($all, -$lines);
    }
}
