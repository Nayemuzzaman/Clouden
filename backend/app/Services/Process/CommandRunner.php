<?php

namespace App\Services\Process;

/**
 * Executes external programs WITHOUT a shell.
 *
 * Commands are always passed as an argument list, so user-influenced values can
 * never be interpreted as shell syntax. The child environment is scrubbed: only
 * the variables passed explicitly (plus PATH/HOME/LANG) are visible, so platform
 * secrets such as APP_KEY never leak into git or docker subprocesses.
 */
interface CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  (callable(string $stream, string $line): void)|null  $onLine  called for each output line ("out" or "err")
     */
    public function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        ?int $timeout = 60,
        ?callable $onLine = null,
        ?string $input = null,
    ): CommandResult;
}
