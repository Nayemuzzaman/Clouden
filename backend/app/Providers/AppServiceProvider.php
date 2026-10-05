<?php

namespace App\Providers;

use App\Services\Backups\BackupStorage;
use App\Services\Backups\LocalBackupStorage;
use App\Services\Docker\DockerClient;
use App\Services\Process\CommandRunner;
use App\Services\Process\SymfonyCommandRunner;
use App\Services\Routing\CaddyReloader;
use App\Services\Routing\DockerCaddyReloader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CommandRunner::class, SymfonyCommandRunner::class);
        $this->app->singleton(DockerClient::class, fn () => DockerClient::fromConfig());
        $this->app->bind(CaddyReloader::class, DockerCaddyReloader::class);
        $this->app->singleton(BackupStorage::class, function () {
            return match (config('privatecloud.backups.storage')) {
                'local' => new LocalBackupStorage((string) config('privatecloud.backups.local_path')),
                default => throw new InvalidArgumentException('Unsupported backup storage: '.config('privatecloud.backups.storage')),
            };
        });
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
