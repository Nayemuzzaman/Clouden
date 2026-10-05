<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverInterrupted;
use App\Enums\DeploymentStatus;
use App\Enums\JobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\RunDeployment;
use App\Models\Backup;
use App\Models\Deployment;
use App\Models\GithubConnection;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectDatabase;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backups\BackupService;
use App\Services\Databases\PostgresProvisioner;
use App\Services\Monitoring\ServiceHealth;
use App\Services\Source\GitHubClient;
use App\Services\Source\SourceFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regression tests for the production-readiness review. */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ HTTP layer

    public function test_api_never_sends_cors_headers_to_other_origins(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/auth/me', ['Origin' => 'https://evil.example']);
        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $preflight = $this->call('OPTIONS', '/api/v1/projects', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_remember_me_cookie_is_capped(): void
    {
        $user = $this->admin();
        config(['privatecloud.security.remember_days' => 14]);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1', 'remember' => true])->assertOk();

        $cookie = $response->getCookie(Auth::guard('web')->getRecallerName(), false);
        $this->assertNotNull($cookie);
        $this->assertLessThanOrEqual(now()->addDays(14)->addMinute()->timestamp, $cookie->getExpiresTime());
    }

    public function test_changing_the_password_signs_out_other_sessions_and_remember_tokens(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->actingAsAdmin();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        foreach (['other-device-1', 'other-device-2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'someone-else', 'user_id' => null, 'payload' => '', 'last_activity' => time()]);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'correct-horse-battery-1',
            'password' => 'another-long-password-2',
            'password_confirmation' => 'another-long-password-2',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->whereIn('id', ['other-device-1', 'other-device-2'])->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'someone-else')->count());
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    public function test_cli_password_reset_signs_out_every_session(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->admin();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('privatecloud:admin', ['--email' => $user->email, '--reset' => true])
            ->expectsQuestion('Password (min. 12 characters)', 'brand-new-password-9')
            ->expectsQuestion('Confirm password', 'brand-new-password-9')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    // ------------------------------------------------ production configuration

    /** @return array<string, mixed> */
    private function productionConfig(): array
    {
        return [
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://cloud.acme-corp.io',
            'session.secure' => true,
            'session.encrypt' => true,
            'privatecloud.security.dev_mode' => false,
            'privatecloud.deploy.allow_insecure_git' => false,
            'privatecloud.caddy.auto_https' => 'on',
            'privatecloud.dashboard_domain' => 'cloud.acme-corp.io',
            'privatecloud.public_ipv4' => '203.0.113.10',
            'privatecloud.data_dir' => '/var/lib/privatecloud',
            'database.connections.pgsql.password' => Str::random(40),
            'privatecloud.apps_db.admin_password' => Str::random(40),
        ];
    }

    public function test_check_config_accepts_a_production_configuration(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('The configuration check refuses root, and the tests run as root.');
        }
        config($this->productionConfig());

        $this->artisan('privatecloud:check-config')->assertExitCode(0);
    }

    public function test_check_config_refuses_development_settings_in_production(): void
    {
        $cases = [
            'app.debug' => [true, 'APP_DEBUG'],
            'app.env' => ['local', 'APP_ENV'],
            'session.secure' => [false, 'SESSION_SECURE_COOKIE'],
            'privatecloud.deploy.allow_insecure_git' => [true, 'PC_ALLOW_INSECURE_GIT'],
            'privatecloud.caddy.auto_https' => ['off', 'PC_AUTO_HTTPS'],
            'privatecloud.dashboard_domain' => ['localhost', 'PC_DASHBOARD_DOMAIN'],
            'app.url' => ['http://cloud.acme-corp.io', 'APP_URL'],
            'database.connections.pgsql.password' => ['secret', 'DB_PASSWORD'],
        ];
        foreach ($cases as $key => [$value, $expected]) {
            config($this->productionConfig());
            config([$key => $value]);

            $this->artisan('privatecloud:check-config')->expectsOutputToContain($expected)->assertExitCode(1);
        }
    }

    public function test_check_config_only_warns_in_development_mode(): void
    {
        config($this->productionConfig());
        config(['app.debug' => true, 'app.env' => 'local', 'privatecloud.security.dev_mode' => true]);

        $this->artisan('privatecloud:check-config')->expectsOutputToContain('[dev] APP_DEBUG')->assertExitCode(0);
    }

    public function test_development_placeholder_accounts_block_production_start(): void
    {
        config($this->productionConfig());
        User::factory()->create(['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN]);

        $this->artisan('privatecloud:check-config')->expectsOutputToContain('admin@example.com')->assertExitCode(1);
    }

    public function test_placeholder_admin_email_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('privatecloud:admin', ['--email' => 'admin@example.com', '--password-stdin' => true])->assertExitCode(1);
        $this->assertSame(0, User::query()->count());
    }

    public function test_admin_check_exists_and_delete(): void
    {
        $this->artisan('privatecloud:admin', ['--check-exists' => true])->assertExitCode(1);
        $user = $this->admin();
        $this->artisan('privatecloud:admin', ['--check-exists' => true])->assertExitCode(0);

        $this->artisan('privatecloud:admin', ['--email' => $user->email, '--delete' => true])->assertExitCode(0);
        $this->assertSame(0, User::query()->count());
    }

    // ------------------------------------------------------- resource isolation

    public function test_volume_names_cannot_collide_between_projects(): void
    {
        $this->actingAsAdmin();
        $a = $this->project(['slug' => 'a', 'name' => 'A']);
        $ab = $this->project(['slug' => 'a-b', 'name' => 'A B']);

        $first = $this->postJson('/api/v1/projects/a/volumes', ['name' => 'b-c', 'mount_path' => '/data'])->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/projects/a-b/volumes', ['name' => 'c', 'mount_path' => '/data'])->assertCreated()->json('data');

        $names = [$a->volumes()->value('docker_name'), $ab->volumes()->value('docker_name')];
        $this->assertNotSame($names[0], $names[1]);
        $this->assertSame("pc-vol-{$a->id}-a_b-c", $names[0]);
        $this->assertSame("pc-vol-{$ab->id}-a-b_c", $names[1]);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    public function test_reserved_names_cannot_be_build_arguments(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);

        foreach (['LD_PRELOAD', 'DOCKER_HOST', 'BUILDKIT_HOST', 'http_proxy', 'GIT_SSH_COMMAND', 'PATH'] as $key) {
            $this->postJson('/api/v1/projects/shop/environment', ['key' => $key, 'value' => 'x', 'available_at_build' => true])
                ->assertStatus(422)->assertJsonValidationErrors('available_at_build');
        }
        // The same names are fine as runtime-only variables.
        $this->postJson('/api/v1/projects/shop/environment', ['key' => 'HTTP_PROXY', 'value' => 'http://proxy:3128'])->assertCreated();
        $this->postJson('/api/v1/projects/shop/environment', ['key' => 'NEXT_PUBLIC_API_URL', 'value' => 'https://api', 'available_at_build' => true])->assertCreated();
    }

    public function test_git_only_uses_https_and_ignores_credential_helpers(): void
    {
        $base = SourceFetcher::gitBase();
        $this->assertSame('git', $base[0]);
        foreach (['protocol.allow=never', 'protocol.https.allow=always', 'credential.helper=', 'http.sslVerify=true'] as $option) {
            $this->assertContains($option, $base);
        }
        $this->assertNotContains('protocol.http.allow=always', $base);

        config(['privatecloud.deploy.allow_insecure_git' => true]);
        $this->assertContains('protocol.http.allow=always', SourceFetcher::gitBase(), 'plain http only in development');
    }

    public function test_webhook_replay_with_a_new_delivery_id_is_rejected(): void
    {
        Bus::fake();
        $project = $this->project(['auto_deploy' => true, 'webhook_secret' => 'whsec-123']);
        $body = (string) json_encode(['ref' => 'refs/heads/main', 'after' => str_repeat('e', 40), 'repository' => ['full_name' => 'acme/shop']]);
        $send = fn (string $delivery) => $this->call('POST', "/api/v1/webhooks/github/{$project->uuid}", [], [], [], $this->transformHeadersToServerVars([
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => $delivery,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'whsec-123'),
            'Content-Type' => 'application/json',
        ]), $body);

        $send('delivery-original')->assertStatus(202);
        $send('delivery-forged-new-id')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_project_deletion_finishes_even_if_the_github_webhook_cannot_be_removed(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['name' => 'Shop']);
        $project->repository->update(['webhook_id' => 77, 'full_name' => 'acme/shop']);
        // A token that can no longer be decrypted (e.g. APP_KEY changed) makes the GitHub client fail.
        DB::table('github_connections')->insert(['account_login' => 'acme', 'token' => 'not-encrypted', 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'Shop'])->assertStatus(202);

        $this->assertNull(Project::query()->find($project->id));
        $operation = Operation::query()->where('type', 'project.delete')->firstOrFail();
        $this->assertSame(JobStatus::Success, $operation->status);
        $this->assertStringContainsString('GitHub webhook was not removed', (string) $operation->message);
    }

    // ---------------------------------------------------- worker restart recovery

    public function test_interrupted_deployment_is_failed_cleaned_up_and_unlocked(): void
    {
        $project = $this->project(['slug' => 'shop', 'status' => ProjectStatus::Running]);
        $live = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Success, 'container_name' => 'pc-shop-1']);
        $project->update(['current_deployment_id' => $live->id]);
        $this->docker->addImage('pc-shop:1');
        $this->docker->addImage('pc-shop:2');
        $this->docker->createContainer('pc-shop-1', ['Image' => 'pc-shop:1']);
        $this->docker->createContainer('pc-shop-2', ['Image' => 'pc-shop:2']);
        $interrupted = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::HealthChecking, 'container_name' => 'pc-shop-2', 'started_at' => now()]);
        $queued = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Queued]);
        $buildDir = config('privatecloud.data_dir').'/builds/deployment-'.$interrupted->id;
        File::ensureDirectoryExists($buildDir);
        Cache::lock('laravel-queue-overlap:project:'.$project->id, 3600)->get();

        $this->artisan('privatecloud:recover-interrupted', ['queue' => 'deployments'])->assertExitCode(0);

        $this->assertSame(DeploymentStatus::Failed, $interrupted->fresh()->status);
        $this->assertSame('health_checking', $interrupted->fresh()->failure_stage);
        $this->assertSame(DeploymentStatus::Queued, $queued->fresh()->status, 'queued work is left for the worker');
        $this->assertArrayNotHasKey('pc-shop-2', $this->docker->containers, 'half-started candidate removed');
        $this->assertArrayHasKey('pc-shop-1', $this->docker->containers, 'production untouched');
        $this->assertDirectoryDoesNotExist($buildDir);
        $this->assertTrue(Cache::lock('laravel-queue-overlap:project:'.$project->id, 10)->get(), 'project lock released');
    }

    public function test_interrupted_backup_and_restore_are_failed_and_partial_files_removed(): void
    {
        $project = $this->project(['slug' => 'shop']);
        $database = ProjectDatabase::query()->create(['server_id' => Server::local()->id, 'project_id' => $project->id, 'name' => 'shop', 'username' => 'shop', 'password' => 'x', 'host' => 'h', 'port' => 5432, 'status' => 'ready']);
        $backup = Backup::query()->create(['project_id' => $project->id, 'project_database_id' => $database->id, 'type' => Backup::TYPE_DATABASE, 'trigger' => 'manual', 'status' => JobStatus::Running, 'label' => 'Database shop', 'storage' => 'local']);
        $partial = config('privatecloud.backups.local_path').'/databases/shop/20261005-030000-'.substr($backup->uuid, 0, 8).'.dump';
        $other = config('privatecloud.backups.local_path').'/databases/shop/20261004-030000-aaaaaaaa.dump';
        File::ensureDirectoryExists(dirname($partial));
        File::put($partial, 'half a dump');
        File::put($other, 'a completed backup');
        $restore = Operation::query()->create(['type' => 'backup.restore', 'status' => JobStatus::Running, 'project_id' => $project->id, 'meta' => ['safety_backup' => 'safety-uuid']]);

        $this->artisan('privatecloud:recover-interrupted', ['queue' => 'default'])->assertExitCode(0);

        $this->assertSame(JobStatus::Failed, $backup->fresh()->status);
        $this->assertFileDoesNotExist($partial);
        $this->assertFileExists($other, 'other backups are untouched');
        $this->assertSame(JobStatus::Failed, $restore->fresh()->status);
        $this->assertStringContainsString('safety-uuid', (string) $restore->fresh()->error);
    }

    public function test_project_lock_key_matches_the_job_middleware(): void
    {
        $job = new RunDeployment(1, 42);
        $middleware = $job->middleware()[0];
        $this->assertSame('laravel-queue-overlap:project:42', $middleware->getLockKey($job));

        Cache::lock('laravel-queue-overlap:project:42', 60)->get();
        RecoverInterrupted::releaseProjectLock(42);
        $this->assertTrue(Cache::lock('laravel-queue-overlap:project:42', 60)->get());
    }

    public function test_idle_reports_running_work(): void
    {
        $this->artisan('privatecloud:idle')->expectsOutput('idle')->assertExitCode(0);
        $project = $this->project(['slug' => 'shop']);
        Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Building]);
        $this->artisan('privatecloud:idle')->expectsOutput('running: 1 deployments')->assertExitCode(1);
    }

    // --------------------------------------------------------------- disk usage

    public function test_backup_refuses_to_start_without_enough_free_disk(): void
    {
        $this->mock(PostgresProvisioner::class, fn ($mock) => $mock->shouldReceive('size')->andReturn(1024));
        config(['privatecloud.backups.min_free_disk_mb' => 1024 * 1024 * 1024]); // 1 PB
        $project = $this->project(['slug' => 'shop']);
        $database = ProjectDatabase::query()->create(['server_id' => Server::local()->id, 'project_id' => $project->id, 'name' => 'shop', 'username' => 'shop', 'password' => 'x', 'host' => 'h', 'port' => 5432, 'status' => 'ready']);

        $backup = app(BackupService::class)->backupDatabase($database, null);

        $this->assertSame(JobStatus::Failed, $backup->fresh()->status);
        $this->assertStringContainsString('Not enough free disk space', (string) $backup->fresh()->error);
        $this->assertSame([], $this->runner->commandsFor('pg_dump'), 'pg_dump never started');
    }

    public function test_history_pruning_keeps_the_live_deployment_logs(): void
    {
        $project = $this->project(['slug' => 'shop']);
        $old = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Success, 'finished_at' => now()->subDays(200)]);
        $live = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Success, 'finished_at' => now()->subDays(150)]);
        $recent = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Failed, 'finished_at' => now()->subDay()]);
        $project->update(['current_deployment_id' => $live->id]);
        foreach ([$old, $live, $recent] as $deployment) {
            DB::table('deployment_logs')->insert(['deployment_id' => $deployment->id, 'stream' => 'system', 'level' => 'info', 'line' => 'line', 'logged_at' => now()]);
        }

        $this->artisan('privatecloud:prune-history')->assertExitCode(0);

        $this->assertSame(0, DB::table('deployment_logs')->where('deployment_id', $old->id)->count());
        $this->assertSame(1, DB::table('deployment_logs')->where('deployment_id', $live->id)->count());
        $this->assertSame(1, DB::table('deployment_logs')->where('deployment_id', $recent->id)->count());
        $this->assertNotNull($old->fresh(), 'deployment history itself is kept');
    }

    // ------------------------------------------------------------------ restore

    public function test_only_one_restore_per_database_at_a_time(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $project = $this->project(['slug' => 'shop']);
        $database = ProjectDatabase::query()->create(['server_id' => Server::local()->id, 'project_id' => $project->id, 'name' => 'shop', 'username' => 'shop', 'password' => 'x', 'host' => 'h', 'port' => 5432, 'status' => 'ready']);
        $make = fn () => Backup::query()->create(['project_id' => $project->id, 'project_database_id' => $database->id, 'type' => Backup::TYPE_DATABASE, 'trigger' => 'manual', 'status' => JobStatus::Success, 'label' => 'Database shop', 'storage' => 'local', 'path' => 'databases/shop/x-'.Str::random(8).'.dump']);
        [$first, $second] = [$make(), $make()];

        $this->withConfirmedPassword()->postJson("/api/v1/backups/{$first->uuid}/restore", ['confirm' => 'shop'])->assertStatus(202);
        $this->withConfirmedPassword()->postJson("/api/v1/backups/{$second->uuid}/restore", ['confirm' => 'shop'])
            ->assertStatus(422)->assertJsonPath('message', 'A restore of this database is already in progress.');
    }

    // ------------------------------------------------------- health reporting

    public function test_health_command_fails_when_a_service_is_down(): void
    {
        $this->mock(PostgresProvisioner::class, fn ($mock) => $mock->shouldReceive('ping')->andReturn(false));
        Http::fake(['*' => Http::response('', 404)]);
        Cache::put(ServiceHealth::HEARTBEAT_KEY, now()->timestamp);

        $this->artisan('privatecloud:health')->expectsOutputToContain('PostgreSQL (applications)')->assertExitCode(1);
    }

    public function test_caddy_is_down_when_it_does_not_answer_http(): void
    {
        $this->docker->addImage('caddy:2-alpine');
        $this->docker->createContainer('privatecloud-caddy', ['Image' => 'caddy:2-alpine']);
        $this->docker->startContainer('privatecloud-caddy');
        Http::fake(fn () => throw new ConnectionException('refused'));

        $caddy = collect(app(ServiceHealth::class)->check(fresh: true))->firstWhere('key', 'caddy');

        $this->assertSame('down', $caddy['status']);
        $this->assertStringContainsString('does not answer', (string) $caddy['detail']);
    }

    // ------------------------------------------------------------------ GitHub

    public function test_rejected_github_token_is_recorded_and_reported_once(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['slug' => 'shop']);
        $project->repository->update(['provider' => Project::SOURCE_GITHUB, 'full_name' => 'acme/shop']);
        GithubConnection::query()->create(['account_login' => 'acme', 'token' => 'github_pat_expired_token_000000']);
        Http::fake([
            '*/repos/acme/shop/commits/*' => Http::response(['message' => 'Bad credentials'], 401),
            '*/user' => Http::response(['login' => 'acme', 'name' => 'Acme']),
        ]);

        $this->postJson('/api/v1/projects/shop/refresh-commit')->assertStatus(422)->assertJsonPath('message', 'GitHub rejected the access token. Reconnect GitHub in Settings.');
        $this->postJson('/api/v1/projects/shop/refresh-commit')->assertStatus(422);

        $this->assertNotNull(Setting::get(GitHubClient::TOKEN_REJECTED_SETTING));
        $this->assertSame(1, DB::table('notifications')->where('data', 'like', '%GitHub token rejected%')->count());
        $this->getJson('/api/v1/settings')->assertJsonPath('github.connected', true)->assertJsonMissingPath('github.token')
            ->assertJsonPath('github.token_rejected_at', Setting::get(GitHubClient::TOKEN_REJECTED_SETTING));

        $this->withConfirmedPassword()->postJson('/api/v1/settings/github', ['token' => 'github_pat_new_valid_token_1111'])->assertOk();
        $this->assertNull(Setting::get(GitHubClient::TOKEN_REJECTED_SETTING));
    }
}
