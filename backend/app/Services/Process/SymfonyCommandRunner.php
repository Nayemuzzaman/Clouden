<?php

namespace App\Services\Process;

use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyCommandRunner implements CommandRunner
{
    private const PASSTHROUGH_ENV = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'DOCKER_HOST', 'DOCKER_CONFIG'];

    public function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        ?int $timeout = 60,
        ?callable $onLine = null,
        ?string $input = null,
    ): CommandResult {
        if ($command === [] || ! array_is_list($command)) {
            throw new InvalidArgumentException('Command must be a non-empty list of arguments.');
        }
        foreach ($command as $arg) {
            if (! is_string($arg) || str_contains($arg, "\0")) {
                throw new InvalidArgumentException('Command arguments must be strings without NUL bytes.');
            }
        }

        $process = new Process($command, $cwd, $this->buildEnvironment($env), $input, $timeout === null ? null : (float) $timeout);

        $buffers = ['out' => '', 'err' => ''];
        $callback = null;
        if ($onLine !== null) {
            $callback = function (string $type, string $chunk) use (&$buffers, $onLine): void {
                $stream = $type === Process::ERR ? 'err' : 'out';
                $buffers[$stream] .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffers[$stream], "\n")) !== false) {
                    $onLine($stream, substr($buffers[$stream], 0, $pos));
                    $buffers[$stream] = substr($buffers[$stream], $pos + 1);
                }
            };
        }

        $timedOut = false;
        try {
            $process->run($callback);
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        if ($onLine !== null) {
            foreach ($buffers as $stream => $rest) {
                if ($rest !== '') {
                    $onLine($stream, $rest);
                }
            }
        }

        return new CommandResult(
            $timedOut ? 124 : ($process->getExitCode() ?? 1),
            $process->getOutput(),
            $process->getErrorOutput(),
            $timedOut,
        );
    }

    /**
     * Start from an empty environment: every inherited variable is explicitly
     * removed (Symfony treats `false` as "unset") except a small allow-list.
     *
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    private function buildEnvironment(array $env): array
    {
        $result = [];
        foreach (array_keys(getenv()) as $key) {
            $result[$key] = false;
        }
        foreach ($_ENV as $key => $_) {
            $result[$key] = false;
        }
        foreach (self::PASSTHROUGH_ENV as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $result[$key] = $value;
            }
        }
        if (($result['PATH'] ?? false) === false) {
            $result['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }

        foreach ($env as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }
}
