<?php

namespace App\Services\Process;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
