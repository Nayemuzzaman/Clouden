<?php

namespace App\Services\Deployment;

use App\Services\Process\CommandRunner;
use InvalidArgumentException;

/**
 * Builds images with BuildKit through the docker CLI (the Engine HTTP build
 * endpoint only offers the legacy builder, which breaks many modern
 * Dockerfiles). Arguments are passed as a list; build-arg VALUES are passed
 * through the process environment so they never appear in the process list.
 */
class ImageBuilder
{
    private const RESERVED_BUILD_ARGS = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_BUILDKIT', 'BUILDKIT_PROGRESS'];

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $buildArgs
     * @param  callable(string): void  $onLine
     * @return array{success: bool, lines: list<string>, timed_out: bool}
     */
    public function build(string $contextDir, string $dockerfile, string $tag, array $labels, array $buildArgs, callable $onLine): array
    {
        $command = [
            (string) config('privatecloud.docker.binary'), 'build',
            '--progress=plain',
            '--file', $dockerfile,
            '--tag', $tag,
        ];
        foreach ($labels as $key => $value) {
            $command[] = '--label';
            $command[] = $key.'='.$value;
        }

        $env = [
            'DOCKER_HOST' => 'unix://'.config('privatecloud.docker.socket'),
            'DOCKER_BUILDKIT' => '1',
            'BUILDKIT_PROGRESS' => 'plain',
        ];
        foreach ($buildArgs as $key => $value) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || in_array(strtoupper($key), self::RESERVED_BUILD_ARGS, true)) {
                throw new InvalidArgumentException("Build argument {$key} is not allowed.");
            }
            $command[] = '--build-arg';
            $command[] = $key; // value is read from the environment by the docker CLI
            $env[$key] = $value;
        }
        $command[] = $contextDir;

        $lines = [];
        $result = $this->runner->run(
            $command,
            env: $env,
            timeout: (int) config('privatecloud.deploy.build_timeout'),
            onLine: function (string $stream, string $line) use (&$lines, $onLine): void {
                $lines[] = $line;
                if (count($lines) > 5000) {
                    array_shift($lines);
                }
                $onLine($line);
            },
        );

        return ['success' => $result->successful(), 'lines' => $lines, 'timed_out' => $result->timedOut];
    }
}
