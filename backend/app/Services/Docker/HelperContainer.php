<?php

namespace App\Services\Docker;

/**
 * Runs short-lived utility containers (e.g. tar for volume backups). Commands
 * are fixed argument lists; variable values are passed as separate arguments,
 * never interpolated into a shell string.
 */
class HelperContainer
{
    public function __construct(private readonly DockerClient $docker) {}

    /**
     * @param  list<string>  $cmd
     * @param  list<array<string, mixed>>  $mounts  Docker "Mounts" entries
     * @return array{exit_code: int, output: string}
     */
    public function run(array $cmd, array $mounts, int $timeout = 3600): array
    {
        $image = (string) config('privatecloud.docker.helper_image');
        if ($this->docker->inspectImage($image) === null) {
            [$name, $tag] = explode(':', $image.(str_contains($image, ':') ? '' : ':latest'), 2);
            $this->docker->pullImage($name, $tag);
        }

        $name = config('privatecloud.docker.prefix').'-helper-'.bin2hex(random_bytes(6));
        $id = $this->docker->createContainer($name, [
            'Image' => $image,
            'Cmd' => $cmd,
            'Labels' => ['privatecloud.managed' => 'true', 'privatecloud.helper' => 'true'],
            'HostConfig' => [
                'Mounts' => $mounts,
                'NetworkMode' => 'none',
                'Memory' => 512 * 1024 * 1024,
                'SecurityOpt' => ['no-new-privileges:true'],
            ],
        ]);

        try {
            $this->docker->startContainer($id);
            $result = $this->docker->waitContainer($id, $timeout);
            $logs = $this->docker->containerLogs($id, 200, timestamps: false);

            return [
                'exit_code' => (int) ($result['StatusCode'] ?? 1),
                'output' => implode("\n", array_column($logs, 'line')),
            ];
        } finally {
            $this->docker->removeContainer($id, force: true);
        }
    }
}
