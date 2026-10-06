<?php

namespace App\Services\Docker;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Docker Engine API client that talks to the local daemon over its Unix
 * socket. All parameters are sent as JSON bodies or URL-encoded query
 * parameters, never through a shell. The socket is only reachable from trusted
 * backend processes; it is never exposed to the browser or the network.
 */
class DockerClient
{
    public function __construct(
        private readonly string $socket,
        private readonly string $apiVersion,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('privatecloud.docker.socket'),
            (string) config('privatecloud.docker.api_version'),
        );
    }

    // ---------------------------------------------------------------- system

    public function ping(): bool
    {
        try {
            return $this->request(5)->get('/_ping')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return $this->json($this->request(10)->get('/info'));
    }

    /** @return array<string, mixed> */
    public function systemDf(string $type = 'volume'): array
    {
        return $this->json($this->request(60)->get('/system/df', ['type' => $type]));
    }

    // ------------------------------------------------------------ containers

    /**
     * @param  array<string, list<string>>  $filters
     * @return list<array<string, mixed>>
     */
    public function listContainers(array $filters = [], bool $all = true): array
    {
        $query = ['all' => $all ? 'true' : 'false'];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }

        return $this->json($this->request(15)->get('/containers/json', $query));
    }

    /** @return array<string, mixed>|null */
    public function inspectContainer(string $idOrName): ?array
    {
        $response = $this->request(10)->get('/containers/'.rawurlencode($idOrName).'/json');
        if ($response->status() === 404) {
            return null;
        }

        return $this->json($response);
    }

    /** @param array<string, mixed> $spec Docker "ContainerCreate" body */
    public function createContainer(string $name, array $spec): string
    {
        $response = $this->request(30)->withQueryParameters(['name' => $name])->post('/containers/create', $spec);

        return (string) $this->json($response)['Id'];
    }

    public function startContainer(string $id): void
    {
        $response = $this->request(60)->post('/containers/'.rawurlencode($id).'/start');
        if ($response->status() !== 304) {
            $this->json($response);
        }
    }

    public function stopContainer(string $id, int $timeout = 10): void
    {
        $response = $this->request($timeout + 30)->post('/containers/'.rawurlencode($id).'/stop?t='.$timeout);
        if (! in_array($response->status(), [304, 404], true)) {
            $this->json($response);
        }
    }

    public function restartContainer(string $id, int $timeout = 10): void
    {
        $this->json($this->request($timeout + 60)->post('/containers/'.rawurlencode($id).'/restart?t='.$timeout));
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        // Note: DELETE parameters must go in the query string (a request body is ignored by Docker).
        $response = $this->request(60)->withQueryParameters(['force' => $force ? 'true' : 'false', 'v' => 'false'])
            ->delete('/containers/'.rawurlencode($id));
        if ($response->status() !== 404) {
            $this->json($response);
        }
    }

    /** @return array<string, mixed> */
    public function waitContainer(string $id, int $timeout): array
    {
        return $this->json($this->request($timeout)->post('/containers/'.rawurlencode($id).'/wait'));
    }

    /**
     * One-shot stats sample (the daemon waits ~1s to compute CPU deltas).
     *
     * @return array<string, mixed>
     */
    public function containerStats(string $id): array
    {
        return $this->json($this->request(15)->get('/containers/'.rawurlencode($id).'/stats', ['stream' => 'false']));
    }

    /**
     * @return list<array{stream: string, timestamp: string|null, line: string}>
     */
    public function containerLogs(string $id, int $tail = 200, ?string $since = null, bool $timestamps = true): array
    {
        $query = ['stdout' => 'true', 'stderr' => 'true', 'timestamps' => $timestamps ? 'true' : 'false', 'tail' => (string) $tail];
        if ($since !== null) {
            $query['since'] = $since;
        }
        $response = $this->request(30)->get('/containers/'.rawurlencode($id).'/logs', $query);
        if (! $response->successful()) {
            $this->json($response);
        }

        $lines = [];
        foreach (StreamDemuxer::lines($response->body()) as $entry) {
            $ts = null;
            $line = $entry['line'];
            if ($timestamps && preg_match('/^(\d{4}-\d{2}-\d{2}T[0-9:.]+Z) (.*)$/s', $line, $m)) {
                $ts = $m[1];
                $line = $m[2];
            }
            $lines[] = ['stream' => $entry['stream'], 'timestamp' => $ts, 'line' => $line];
        }

        return $lines;
    }

    /**
     * Run a fixed command inside a running container and return its exit code and output.
     *
     * @param  list<string>  $cmd
     * @return array{exit_code: int, output: string}
     */
    public function exec(string $container, array $cmd, int $timeout = 60): array
    {
        $create = $this->json($this->request(15)->post('/containers/'.rawurlencode($container).'/exec', [
            'Cmd' => $cmd,
            'AttachStdout' => true,
            'AttachStderr' => true,
        ]));
        $execId = (string) $create['Id'];

        $start = $this->request($timeout)->post('/exec/'.$execId.'/start', ['Detach' => false, 'Tty' => false]);
        if (! $start->successful()) {
            $this->json($start);
        }
        $output = StreamDemuxer::text($start->body());
        $inspect = $this->json($this->request(10)->get('/exec/'.$execId.'/json'));

        return ['exit_code' => (int) ($inspect['ExitCode'] ?? 1), 'output' => $output];
    }

    // -------------------------------------------------------------- networks

    /** @param array<string, string> $labels */
    public function ensureNetwork(string $name, array $labels = []): string
    {
        $existing = $this->request(10)->get('/networks/'.rawurlencode($name));
        if ($existing->successful()) {
            return (string) $existing->json('Id');
        }

        $created = $this->json($this->request(20)->post('/networks/create', [
            'Name' => $name,
            'Driver' => 'bridge',
            'CheckDuplicate' => true,
            'Labels' => (object) $labels,
        ]));

        return (string) $created['Id'];
    }

    public function removeNetwork(string $name): void
    {
        $response = $this->request(20)->delete('/networks/'.rawurlencode($name));
        if (! in_array($response->status(), [204, 404], true)) {
            $this->json($response);
        }
    }

    /** @param list<string> $aliases */
    public function connectNetwork(string $network, string $container, array $aliases = []): void
    {
        $body = ['Container' => $container];
        if ($aliases !== []) {
            $body['EndpointConfig'] = ['Aliases' => $aliases];
        }
        $response = $this->request(20)->post('/networks/'.rawurlencode($network).'/connect', $body);
        // 403 is returned when the container is already connected: treat as success (idempotent).
        if ($response->status() === 403 && str_contains((string) $response->json('message'), 'already exists')) {
            return;
        }
        $this->json($response);
    }

    public function disconnectNetwork(string $network, string $container): void
    {
        $response = $this->request(20)->post('/networks/'.rawurlencode($network).'/disconnect', ['Container' => $container, 'Force' => true]);
        if (! in_array($response->status(), [200, 404], true)) {
            $this->json($response);
        }
    }

    /** @return list<string> names of networks the container is attached to */
    public function containerNetworks(string $container): array
    {
        $info = $this->inspectContainer($container);

        return array_keys($info['NetworkSettings']['Networks'] ?? []);
    }

    // ---------------------------------------------------------------- images

    /** @return array<string, mixed>|null */
    public function inspectImage(string $ref): ?array
    {
        $response = $this->request(10)->get('/images/'.rawurlencode($ref).'/json');
        if ($response->status() === 404) {
            return null;
        }

        return $this->json($response);
    }

    public function removeImage(string $ref): void
    {
        $response = $this->request(60)->withQueryParameters(['force' => 'false', 'noprune' => 'false'])->delete('/images/'.rawurlencode($ref));
        if (! in_array($response->status(), [200, 404], true)) {
            $this->json($response);
        }
    }

    /**
     * Pull an image. Progress messages are passed to $onLine.
     *
     * @param  (callable(string): void)|null  $onLine
     */
    public function pullImage(string $image, string $tag, ?callable $onLine = null, int $timeout = 900): void
    {
        $response = $this->request($timeout)->withQueryParameters(['fromImage' => $image, 'tag' => $tag])->post('/images/create');
        if (! $response->successful()) {
            $this->json($response);
        }

        foreach (preg_split('/\r?\n/', $response->body()) ?: [] as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            if (isset($event['error'])) {
                throw new DockerException((string) $event['error']);
            }
            if ($onLine && isset($event['status']) && ! isset($event['progressDetail']['current'])) {
                $onLine(trim(($event['id'] ?? '').' '.$event['status']));
            }
        }
    }

    // --------------------------------------------------------------- volumes

    /**
     * Create the volume if it does not exist. Docker returns the EXISTING volume
     * (with its original labels) when the name is already taken.
     *
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    public function ensureVolume(string $name, array $labels = []): array
    {
        return $this->json($this->request(20)->post('/volumes/create', ['Name' => $name, 'Labels' => (object) $labels]));
    }

    public function removeVolume(string $name): void
    {
        $response = $this->request(30)->delete('/volumes/'.rawurlencode($name));
        if (! in_array($response->status(), [204, 404], true)) {
            $this->json($response);
        }
    }

    // ----------------------------------------------------------- internals

    private function request(int $timeout): PendingRequest
    {
        return Http::baseUrl('http://docker/'.$this->apiVersion)
            ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socket]])
            ->connectTimeout(5)
            ->timeout($timeout)
            ->acceptJson();
    }

    /** @return array<mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            $message = $response->json('message') ?: trim($response->body()) ?: 'Docker request failed';
            throw new DockerException((string) $message, $response->status());
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
