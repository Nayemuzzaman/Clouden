<?php

namespace App\Services\Routing;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Regenerates Caddy site files from the database and reloads Caddy.
 *
 * The whole managed configuration is rendered from current state each time, so
 * the operation is idempotent. If Caddy rejects the new configuration the
 * previous files are restored and Caddy keeps serving its last good config.
 */
class CaddyConfigurator
{
    public function __construct(
        private readonly CaddyfileRenderer $renderer,
        private readonly CaddyReloader $reloader,
    ) {}

    /**
     * @param  array<int, string|null>  $upstreamOverrides  project id => "container:port" (null = not running)
     */
    public function sync(array $upstreamOverrides = []): void
    {
        Cache::lock('privatecloud:caddy-sync', 120)->block(60, function () use ($upstreamOverrides) {
            $this->syncLocked($upstreamOverrides);
        });
    }

    public function upstreamFor(Project $project): ?string
    {
        if (in_array($project->status, [ProjectStatus::Stopped, ProjectStatus::Deleting], true)) {
            return null;
        }
        $deployment = $project->currentDeployment;
        if (! $deployment || ! $deployment->container_name) {
            return null;
        }

        return $deployment->container_name.':'.$project->port;
    }

    /** The upstream ("container:port") the project's current Caddy site file routes to, if any. */
    public function routedUpstream(Project $project): ?string
    {
        $file = rtrim((string) config('privatecloud.caddy.sites_dir'), '/').'/'.$project->slug.'.caddy';
        if (! is_file($file)) {
            return null;
        }

        return preg_match('/^\s*reverse_proxy\s+(\S+)\s*$/m', (string) File::get($file), $m) ? $m[1] : null;
    }

    /** @param array<int, string|null> $upstreamOverrides */
    private function syncLocked(array $upstreamOverrides): void
    {
        $dir = (string) config('privatecloud.caddy.sites_dir');
        File::ensureDirectoryExists($dir, 0755);

        $desired = [];
        $projects = Project::query()->with(['domains', 'currentDeployment'])->whereNull('deleting_at')->get();
        foreach ($projects as $project) {
            $hostnames = $project->domains->sortByDesc('is_primary')->pluck('hostname')->all();
            if ($hostnames === []) {
                continue;
            }
            $upstream = array_key_exists($project->id, $upstreamOverrides)
                ? $upstreamOverrides[$project->id]
                : $this->upstreamFor($project);
            $content = $this->renderer->render(
                $project,
                $hostnames,
                $upstream,
                config('privatecloud.caddy.auto_https') !== 'off',
                (string) config('privatecloud.caddy.logs_dir_in_caddy'),
            );
            if ($content !== '') {
                $desired[$project->slug.'.caddy'] = $content;
            }
        }

        $previous = [];
        foreach (File::glob($dir.'/*.caddy') as $path) {
            $previous[basename($path)] = (string) File::get($path);
        }

        if ($previous == $desired) {
            return; // nothing changed, avoid an unnecessary reload
        }

        $this->writeFiles($dir, $desired, array_keys($previous));
        $result = $this->reloader->reload();

        if (! $result['success']) {
            $this->writeFiles($dir, $previous, array_keys($desired));
            Log::error('Caddy rejected the generated configuration', ['output' => $result['output']]);

            throw new RoutingException('The web server rejected the new routing configuration, so the previous configuration was kept. '.mb_substr($result['output'], -800));
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $existing
     */
    private function writeFiles(string $dir, array $files, array $existing): void
    {
        foreach ($files as $name => $content) {
            $tmp = $dir.'/.'.$name.'.tmp';
            File::put($tmp, $content);
            @chmod($tmp, 0644);
            rename($tmp, $dir.'/'.$name);
        }
        foreach ($existing as $name) {
            if (! array_key_exists($name, $files)) {
                File::delete($dir.'/'.$name);
            }
        }
    }
}
