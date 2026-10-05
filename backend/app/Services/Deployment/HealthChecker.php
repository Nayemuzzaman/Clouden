<?php

namespace App\Services\Deployment;

use App\Models\Project;
use App\Services\Docker\DockerClient;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a freshly started container before it receives traffic.
 *
 * "http": GET http://<container>:<port><path> must return a status within the
 *         configured range (default 200-399).
 * "container": the container must stay running without restarting for a few
 *         seconds. This only proves the process did not crash; it does NOT prove
 *         the application serves requests correctly.
 */
class HealthChecker
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(private readonly DockerClient $docker, ?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? fn (int $seconds) => sleep($seconds);
    }

    /**
     * @param  callable(string): void  $log
     * @return array{healthy: bool, reason: ?string}
     */
    public function check(Project $project, string $container, callable $log): array
    {
        return $project->health_check_type === 'container'
            ? $this->checkContainer($container, $log)
            : $this->checkHttp($project, $container, $log);
    }

    /** @param callable(string): void $log */
    private function checkHttp(Project $project, string $container, callable $log): array
    {
        $path = '/'.ltrim($project->health_check_path ?: '/', '/');
        $url = 'http://'.$container.':'.$project->port.$path;
        $retries = max(1, $project->health_check_retries);
        $lastReason = null;

        $log("HTTP health check: GET {$path} on port {$project->port} (expecting {$project->health_check_status_min}-{$project->health_check_status_max}, up to {$retries} attempts)");

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $state = $this->containerState($container);
            if ($state !== null && ! $state['running']) {
                return ['healthy' => false, 'reason' => "The application exited during startup (exit code {$state['exit_code']}). Check the container logs below."];
            }
            if ($state !== null && $state['restart_count'] > 0) {
                return ['healthy' => false, 'reason' => 'The application crashed and was restarted during startup. Check the container logs below.'];
            }

            try {
                $response = Http::timeout(max(1, $project->health_check_timeout))->connectTimeout(max(1, $project->health_check_timeout))
                    ->withoutRedirecting()->withHeaders(['User-Agent' => 'PrivateCloud-HealthCheck'])->get($url);
                $status = $response->status();
                if ($status >= $project->health_check_status_min && $status <= $project->health_check_status_max) {
                    $log("Attempt {$attempt}: HTTP {$status} — healthy");

                    return ['healthy' => true, 'reason' => null];
                }
                $lastReason = "The health check returned HTTP {$status}, expected {$project->health_check_status_min}-{$project->health_check_status_max}.";
                $log("Attempt {$attempt}: HTTP {$status}");
            } catch (Throwable $e) {
                $lastReason = str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), 'Failed to connect')
                    ? "Nothing is listening on port {$project->port}. Make sure the application listens on 0.0.0.0:{$project->port} (the PORT environment variable is set for you)."
                    : 'The health check request failed: '.mb_substr($e->getMessage(), 0, 200);
                $log("Attempt {$attempt}: not ready (".mb_substr($e->getMessage(), 0, 120).')');
            }

            if ($attempt < $retries) {
                ($this->sleeper)(max(1, $project->health_check_interval));
            }
        }

        return ['healthy' => false, 'reason' => $lastReason ?? 'The application did not become healthy.'];
    }

    /** @param callable(string): void $log */
    private function checkContainer(string $container, callable $log): array
    {
        $seconds = max(1, (int) config('privatecloud.deploy.container_stable_seconds'));
        $log("Container check: waiting {$seconds}s to confirm the process keeps running (this does not test HTTP responses)");
        ($this->sleeper)($seconds);
        $state = $this->containerState($container);
        if ($state === null) {
            return ['healthy' => false, 'reason' => 'The container disappeared during startup.'];
        }
        if (! $state['running']) {
            return ['healthy' => false, 'reason' => "The application exited during startup (exit code {$state['exit_code']})."];
        }
        if ($state['restart_count'] > 0) {
            return ['healthy' => false, 'reason' => 'The application crashed and was restarted during startup.'];
        }
        $log('Container is running');

        return ['healthy' => true, 'reason' => null];
    }

    /** @return array{running: bool, exit_code: int, restart_count: int}|null */
    private function containerState(string $container): ?array
    {
        $info = $this->docker->inspectContainer($container);
        if ($info === null) {
            return null;
        }

        return [
            'running' => (bool) ($info['State']['Running'] ?? false) && ! ($info['State']['Restarting'] ?? false),
            'exit_code' => (int) ($info['State']['ExitCode'] ?? 0),
            'restart_count' => (int) ($info['RestartCount'] ?? 0),
        ];
    }
}
