<?php

namespace Tests\Fakes;

use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;

/** In-memory Docker daemon used by tests. */
class FakeDocker extends DockerClient
{
    /** @var array<string, array<string, mixed>> */
    public array $containers = [];

    /** @var array<string, array<string, mixed>> */
    public array $images = [];

    /** @var array<string, list<string>> network => containers */
    public array $networks = [];

    /** @var array<string, bool> */
    public array $volumes = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var (callable(array<string, mixed>): int)|null Simulates a helper container run; returns its exit code. */
    public $onWait = null;

    /** Set to make newly started containers exit immediately with this code. */
    public ?int $crashOnStart = null;

    public function __construct()
    {
        parent::__construct('/dev/null', 'v1.44');
    }

    public function ping(): bool
    {
        return true;
    }

    public function info(): array
    {
        return ['ServerVersion' => 'fake', 'ContainersRunning' => count(array_filter($this->containers, fn ($c) => $c['State']['Running']))];
    }

    public function listContainers(array $filters = [], bool $all = true): array
    {
        $result = [];
        foreach ($this->containers as $name => $c) {
            if (isset($filters['label'])) {
                foreach ($filters['label'] as $label) {
                    [$k, $v] = explode('=', $label, 2);
                    if (($c['Config']['Labels'][$k] ?? null) !== $v) {
                        continue 2;
                    }
                }
            }
            $result[] = ['Id' => $c['Id'], 'Names' => ['/'.$name], 'Labels' => $c['Config']['Labels'], 'Image' => $c['Config']['Image'], 'State' => $c['State']['Running'] ? 'running' : 'exited'];
        }

        return $result;
    }

    public function inspectContainer(string $idOrName): ?array
    {
        return $this->find($idOrName);
    }

    public function createContainer(string $name, array $spec): string
    {
        $this->calls[] = "create:$name";
        if (! isset($this->images[$spec['Image']])) {
            throw new DockerException("No such image: {$spec['Image']}", 404);
        }
        $id = hash('sha256', $name.microtime());
        $this->containers[$name] = [
            'Id' => $id,
            'Name' => '/'.$name,
            'Config' => ['Image' => $spec['Image'], 'Labels' => $spec['Labels'] ?? [], 'Env' => $spec['Env'] ?? [], 'ExposedPorts' => $spec['ExposedPorts'] ?? []],
            'HostConfig' => $spec['HostConfig'] ?? [],
            'Cmd' => $spec['Cmd'] ?? [],
            'State' => ['Running' => false, 'Restarting' => false, 'ExitCode' => 0, 'Status' => 'created', 'OOMKilled' => false],
            'RestartCount' => 0,
            'NetworkSettings' => ['Networks' => [($spec['HostConfig']['NetworkMode'] ?? 'bridge') => []]],
            'Created' => now()->toIso8601String(),
        ];

        return $id;
    }

    public function startContainer(string $id): void
    {
        $this->calls[] = "start:$id";
        $name = $this->nameOf($id);
        if ($this->crashOnStart !== null) {
            $this->containers[$name]['State'] = ['Running' => false, 'Restarting' => false, 'ExitCode' => $this->crashOnStart, 'Status' => 'exited', 'OOMKilled' => false];

            return;
        }
        $this->containers[$name]['State']['Running'] = true;
        $this->containers[$name]['State']['Status'] = 'running';
    }

    public function stopContainer(string $id, int $timeout = 10): void
    {
        $this->calls[] = 'stop:'.$this->nameOf($id);
        if ($name = $this->nameOf($id)) {
            $this->containers[$name]['State']['Running'] = false;
            $this->containers[$name]['State']['Status'] = 'exited';
        }
    }

    public function restartContainer(string $id, int $timeout = 10): void
    {
        $this->calls[] = 'restart:'.$this->nameOf($id);
        $this->startContainer($id);
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        $name = $this->nameOf($id);
        $this->calls[] = 'remove:'.$name;
        unset($this->containers[$name]);
    }

    public function waitContainer(string $id, int $timeout): array
    {
        $name = $this->nameOf($id);
        $spec = $this->containers[$name] ?? [];
        $code = $this->onWait ? ($this->onWait)($spec) : 0;

        return ['StatusCode' => $code];
    }

    public function containerStats(string $id): array
    {
        return [
            'cpu_stats' => ['cpu_usage' => ['total_usage' => 2000], 'system_cpu_usage' => 100000, 'online_cpus' => 2],
            'precpu_stats' => ['cpu_usage' => ['total_usage' => 1000], 'system_cpu_usage' => 50000],
            'memory_stats' => ['usage' => 150 * 1024 * 1024, 'limit' => 512 * 1024 * 1024, 'stats' => ['inactive_file' => 50 * 1024 * 1024]],
            'networks' => ['eth0' => ['rx_bytes' => 100, 'tx_bytes' => 200]],
            'pids_stats' => ['current' => 7],
        ];
    }

    public function containerLogs(string $id, int $tail = 200, ?string $since = null, bool $timestamps = true): array
    {
        return [
            ['stream' => 'stdout', 'timestamp' => '2026-10-05T10:00:00.000000000Z', 'line' => 'Server listening on port 3000'],
            ['stream' => 'stderr', 'timestamp' => '2026-10-05T10:00:01.000000000Z', 'line' => 'Error: something failed'],
        ];
    }

    public function exec(string $container, array $cmd, int $timeout = 60): array
    {
        $this->calls[] = 'exec:'.$container.':'.implode(' ', $cmd);

        return ['exit_code' => 0, 'output' => ''];
    }

    public function ensureNetwork(string $name, array $labels = []): string
    {
        $this->networks[$name] ??= [];

        return 'net-'.$name;
    }

    public function removeNetwork(string $name): void
    {
        unset($this->networks[$name]);
    }

    public function connectNetwork(string $network, string $container, array $aliases = []): void
    {
        $this->networks[$network][] = $container;
    }

    public function disconnectNetwork(string $network, string $container): void
    {
        $this->networks[$network] = array_values(array_diff($this->networks[$network] ?? [], [$container]));
    }

    public function containerNetworks(string $container): array
    {
        return [];
    }

    public function inspectImage(string $ref): ?array
    {
        return $this->images[$ref] ?? null;
    }

    public function removeImage(string $ref): void
    {
        $this->calls[] = "rmi:$ref";
        unset($this->images[$ref]);
    }

    public function pullImage(string $image, string $tag, ?callable $onLine = null, int $timeout = 900): void
    {
        $this->images[$image.':'.$tag] = ['Id' => 'sha256:'.str_repeat('b', 64)];
    }

    public function ensureVolume(string $name, array $labels = []): array
    {
        if (! isset($this->volumes[$name])) {
            $this->volumes[$name] = $labels;
        }

        return ['Name' => $name, 'Labels' => is_array($this->volumes[$name]) ? $this->volumes[$name] : []];
    }

    public function removeVolume(string $name): void
    {
        $this->calls[] = "rmvol:$name";
        unset($this->volumes[$name]);
    }

    public function systemDf(string $type = 'volume'): array
    {
        return ['Volumes' => []];
    }

    public function addImage(string $tag): void
    {
        $this->images[$tag] = ['Id' => 'sha256:'.hash('sha256', $tag)];
    }

    public function isRunning(string $name): bool
    {
        return (bool) ($this->containers[$name]['State']['Running'] ?? false);
    }

    private function find(string $idOrName): ?array
    {
        $name = $this->nameOf($idOrName);

        return $name ? $this->containers[$name] : null;
    }

    private function nameOf(string $idOrName): ?string
    {
        if (isset($this->containers[$idOrName])) {
            return $idOrName;
        }
        foreach ($this->containers as $name => $c) {
            if ($c['Id'] === $idOrName) {
                return $name;
            }
        }

        return null;
    }
}
