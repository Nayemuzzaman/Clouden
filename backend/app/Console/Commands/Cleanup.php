<?php

namespace App\Console\Commands;

use App\Services\Process\CommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Frees disk space: old BuildKit cache, dangling images, leftover build directories. */
class Cleanup extends Command
{
    protected $signature = 'privatecloud:cleanup {--build-cache-hours=168 : Remove build cache unused for this many hours}';

    protected $description = 'Remove old build cache, dangling images and temporary build files';

    public function handle(CommandRunner $runner): int
    {
        $hours = max(1, (int) $this->option('build-cache-hours'));
        $env = ['DOCKER_HOST' => 'unix://'.config('privatecloud.docker.socket')];
        $docker = (string) config('privatecloud.docker.binary');

        $result = $runner->run([$docker, 'builder', 'prune', '--force', '--filter', 'until='.$hours.'h'], env: $env, timeout: 600);
        $this->line(trim($result->output) ?: trim($result->errorOutput));

        $result = $runner->run([$docker, 'image', 'prune', '--force', '--filter', 'label=privatecloud.managed=true'], env: $env, timeout: 600);
        $this->line(trim($result->output) ?: trim($result->errorOutput));

        $builds = rtrim((string) config('privatecloud.data_dir'), '/').'/builds';
        foreach (is_dir($builds) ? File::directories($builds) : [] as $dir) {
            if (filemtime($dir) < time() - 86400) {
                File::deleteDirectory($dir);
            }
        }

        return self::SUCCESS;
    }
}
