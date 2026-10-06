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
    /**
     * Build-argument names become environment variables of the docker CLI
     * process, so names that change how that process (or the dynamic loader,
     * TLS, proxies, git or the Go runtime) behaves are refused.
     */
    private const RESERVED_BUILD_ARGS = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'SHELL', 'USER', 'IFS', 'ENV', 'BASH_ENV', 'GODEBUG', 'GOFLAGS', 'GOTRACEBACK', 'GOMAXPROCS', 'GOGC', 'GOMEMLIMIT'];

    private const RESERVED_BUILD_ARG_PREFIXES = ['DOCKER_', 'BUILDKIT_', 'BUILDX_', 'COMPOSE_', 'LD_', 'DYLD_', 'SSL_', 'CURL_', 'GIT_', 'XDG_', 'OTEL_', 'NO_PROXY', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'FTP_PROXY'];

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
            if (! self::isAllowedBuildArg($key)) {
                throw new InvalidArgumentException("The variable {$key} cannot be used at build time because its name is reserved for the build tooling. Rename it or turn off \"available at build time\".");
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

    public static function isAllowedBuildArg(string $key): bool
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return false;
        }
        $upper = strtoupper($key);
        if (in_array($upper, self::RESERVED_BUILD_ARGS, true)) {
            return false;
        }
        foreach (self::RESERVED_BUILD_ARG_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
