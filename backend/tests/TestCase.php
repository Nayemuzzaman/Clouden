<?php

namespace Tests;

use App\Http\Middleware\RequireRecentPassword;
use App\Models\Project;
use App\Models\User;
use App\Services\Docker\DockerClient;
use App\Services\Domains\DnsChecker;
use App\Services\Process\CommandRunner;
use App\Services\Routing\CaddyReloader;
use App\Services\Server\ServerIdentity;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeCaddyReloader;
use Tests\Fakes\FakeCommandRunner;
use Tests\Fakes\FakeDocker;

abstract class TestCase extends BaseTestCase
{
    protected FakeDocker $docker;

    protected FakeCommandRunner $runner;

    protected FakeCaddyReloader $caddy;

    protected function setUp(): void
    {
        parent::setUp();

        $dataDir = storage_path('framework/testing/privatecloud-'.getmypid());
        File::deleteDirectory($dataDir);
        config([
            'privatecloud.data_dir' => $dataDir,
            'privatecloud.caddy.sites_dir' => $dataDir.'/caddy/sites',
            'privatecloud.caddy.logs_dir' => $dataDir.'/caddy/logs',
            'privatecloud.backups.local_path' => $dataDir.'/backups',
            'privatecloud.monitoring.disk_path' => $dataDir,
            'privatecloud.monitoring.proc_path' => base_path('tests/Fixtures/proc'),
            'privatecloud.deploy.min_free_disk_mb' => 1,
            'privatecloud.apps_db.admin_password' => env('PC_TEST_APPS_DB_PASSWORD', 'test'),
        ]);
        File::ensureDirectoryExists($dataDir);

        $this->docker = new FakeDocker;
        $this->runner = new FakeCommandRunner;
        $this->caddy = new FakeCaddyReloader;
        $this->app->instance(DockerClient::class, $this->docker);
        $this->app->instance(CommandRunner::class, $this->runner);
        $this->app->instance(CaddyReloader::class, $this->caddy);
        $this->app->instance(DnsChecker::class, new class(app(ServerIdentity::class)) extends DnsChecker
        {
            protected function lookup(string $hostname, int $type, string $field): array
            {
                return $type === DNS_A && ! str_starts_with($hostname, 'nodns.') ? ['203.0.113.10'] : [];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('privatecloud.data_dir'));
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    protected function actingAsAdmin(): User
    {
        $user = $this->admin();
        $this->actingAs($user);

        return $user;
    }

    /** Pretend the administrator confirmed their password just now. */
    protected function withConfirmedPassword(): static
    {
        return $this->withSession([RequireRecentPassword::SESSION_KEY => time()]);
    }

    /** @param array<string, mixed> $attributes */
    protected function project(array $attributes = []): Project
    {
        return Project::factory()->withRepository()->create($attributes);
    }
}
