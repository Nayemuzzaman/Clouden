<?php

namespace Tests\Fakes;

use App\Services\Process\CommandResult;
use App\Services\Process\CommandRunner;
use Closure;

/**
 * Records commands and returns scripted results. A handler receives the command
 * list and may produce side effects (e.g. write a file, emit build output).
 */
class FakeCommandRunner implements CommandRunner
{
    /** @var list<array{command: list<string>, env: array<string, string>, cwd: ?string}> */
    public array $commands = [];

    /** @var list<array{0: Closure(list<string>): bool, 1: Closure(list<string>, ?callable, ?string): CommandResult}> */
    private array $handlers = [];

    /**
     * @param  Closure(list<string>): bool  $matcher
     * @param  Closure(list<string>, ?callable, ?string): CommandResult  $handler
     */
    public function on(Closure $matcher, Closure $handler): self
    {
        $this->handlers[] = [$matcher, $handler];

        return $this;
    }

    /** Like on(), but takes precedence over handlers registered earlier. */
    public function prepend(Closure $matcher, Closure $handler): self
    {
        array_unshift($this->handlers, [$matcher, $handler]);

        return $this;
    }

    public function onBinary(string $binary, Closure $handler): self
    {
        return $this->on(fn (array $cmd) => basename($cmd[0]) === $binary, $handler);
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?int $timeout = 60, ?callable $onLine = null, ?string $input = null): CommandResult
    {
        $this->commands[] = ['command' => $command, 'env' => $env, 'cwd' => $cwd];
        foreach ($this->handlers as [$matcher, $handler]) {
            if ($matcher($command)) {
                return $handler($command, $onLine, $cwd);
            }
        }

        return new CommandResult(0, '', '');
    }

    /** @return list<list<string>> */
    public function commandsFor(string $binary): array
    {
        return array_values(array_map(fn ($c) => $c['command'], array_filter($this->commands, fn ($c) => basename($c['command'][0]) === $binary)));
    }
}
