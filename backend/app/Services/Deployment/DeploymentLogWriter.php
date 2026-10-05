<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Services\Environment\LogRedactor;

/**
 * Buffers deployment log lines and writes them in batches so streaming a large
 * build log does not issue one INSERT per line. Secret values are redacted.
 */
class DeploymentLogWriter
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private float $lastFlush;

    private int $written = 0;

    private const MAX_LINES = 20000;

    public function __construct(
        private readonly Deployment $deployment,
        private readonly LogRedactor $redactor,
    ) {
        $this->lastFlush = microtime(true);
    }

    public function system(string $line, string $level = 'info'): void
    {
        $this->write('system', $line, $level);
        $this->flush();
    }

    public function write(string $stream, string $line, string $level = 'info'): void
    {
        if ($this->written >= self::MAX_LINES) {
            if ($this->written === self::MAX_LINES) {
                $this->buffer[] = $this->row('system', 'Log limit reached; further output is not stored.', 'warn');
                $this->written++;
            }

            return;
        }
        $this->buffer[] = $this->row($stream, mb_substr($this->redactor->redact(rtrim($line)), 0, 4000), $level);
        $this->written++;

        if (count($this->buffer) >= 50 || microtime(true) - $this->lastFlush > 1.0) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        DeploymentLog::query()->insert($this->buffer);
        $this->buffer = [];
        $this->lastFlush = microtime(true);
    }

    /** @return array<string, mixed> */
    private function row(string $stream, string $line, string $level): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'stream' => $stream,
            'level' => $level,
            'line' => $line,
            'logged_at' => now()->format('Y-m-d H:i:s.v'),
        ];
    }
}
