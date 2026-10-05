<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Project;
use App\Services\Deployment\HealthChecker;
use App\Services\Process\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeploymentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<string> */
    private array $transitions = [];

    private int $healthStatus = 200;

    private bool $buildFails = false;

    private bool $withDockerfile = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['privatecloud.deploy.drain_seconds' => 0]);
        $this->app->instance(HealthChecker::class, new HealthChecker($this->docker, fn () => null));

        $this->project = $this->project(['name' => 'Shop', 'slug' => 'shop']);
        Domain::query()->create(['project_id' => $this->project->id, 'hostname' => 'shop.example.com', 'is_primary' => true]);
        $this->project->environmentVariables()->create(['key' => 'API_TOKEN', 'value' => 'super-secret-token-123', 'is_secret' => true]);

        Deployment::updated(function (Deployment $d) {
            if ($d->wasChanged('status')) {
                $this->transitions[] = $d->status->value;
            }
        });

        $sha = 0;
        Http::fake(function ($request) use (&$sha) {
            $url = $request->url();
            if (str_contains($url, '/commits/')) {
                $sha++;

                return Http::response(['sha' => str_repeat(dechex($sha + 9), 40), 'commit' => ['message' => "Commit {$sha}", 'author' => ['name' => 'Ada']]]);
            }
            if (str_contains($url, '/tarball/')) {
                return Http::response('fake-archive');
            }
            if (preg_match('#^http://pc-shop-\d+:3000/health$#', $url)) {
                return Http::response('ok', $this->healthStatus);
            }

            return Http::response('unexpected '.$url, 500);
        });

        // "tar -xzf archive -C dir" → materialize a tiny repository.
        $this->runner->onBinary('tar', function (array $cmd) {
            $dir = $cmd[array_search('-C', $cmd, true) + 1];
            File::ensureDirectoryExists($dir);
            if ($this->withDockerfile) {
                File::put($dir.'/Dockerfile', "FROM node:22-alpine\nCMD [\"node\", \"server.js\"]\n");
            }
            File::put($dir.'/package.json', '{"dependencies":{"express":"4"}}');

            return new CommandResult(0, '', '');
        });

        // "docker build ... --tag X ctx" → emit BuildKit-style output and register the image.
        $this->runner->onBinary('docker', function (array $cmd, ?callable $onLine) {
            $tag = $cmd[array_search('--tag', $cmd, true) + 1];
            $lines = $this->buildFails
                ? ['#1 [internal] load build definition from Dockerfile', '#7 [4/5] RUN npm run build', '#7 0.512 > build', '#7 1.204 Error: Cannot find module \'xyz\'', '#7 ERROR: process "/bin/sh -c npm run build" did not complete successfully: exit code: 1', 'ERROR: failed to build: failed to solve: process "/bin/sh -c npm run build" did not complete successfully: exit code: 1']
                : ['#1 [internal] load build definition from Dockerfile', '#5 [2/3] RUN echo super-secret-token-123', '#5 DONE 0.1s', '#8 naming to '.$tag.' done'];
            foreach ($lines as $line) {
                $onLine('err', $line);
            }
            if ($this->buildFails) {
                return new CommandResult(1, '', implode("\n", $lines));
            }
            $this->docker->addImage($tag);

            return new CommandResult(0, '', '');
        });
    }

    private function deploy(): Deployment
    {
        $this->transitions = [];

        // QUEUE_CONNECTION=sync runs the job inline after the request.
        $response = $this->postJson('/api/v1/projects/shop/deployments')->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');

        return Deployment::query()->findOrFail($response->json('data.id'));
    }

    public function test_first_deployment_goes_through_every_stage_and_goes_live(): void
    {
        $this->actingAsAdmin();
        $deployment = $this->deploy()->fresh();

        $this->assertSame(['cloning', 'building', 'starting', 'health_checking', 'routing', 'success'], $this->transitions);
        $this->assertSame(DeploymentStatus::Success, $deployment->status);
        $this->assertSame('pc-shop:1', $deployment->image_tag);
        $this->assertSame('pc-shop-1', $deployment->container_name);
        $this->assertSame(str_repeat('a', 40), $deployment->commit_sha);
        $this->assertNotNull($deployment->build_started_at);

        $project = $this->project->fresh();
        $this->assertSame($deployment->id, $project->current_deployment_id);
        $this->assertSame(ProjectStatus::Running, $project->status);
        $this->assertTrue($this->docker->isRunning('pc-shop-1'));

        // Routing points at the new container.
        $site = File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy');
        $this->assertStringContainsString('shop.example.com {', $site);
        $this->assertStringContainsString('reverse_proxy pc-shop-1:3000', $site);

        // Resource limits and hardening were applied to the container.
        $host = $this->docker->containers['pc-shop-1']['HostConfig'];
        $this->assertSame(512 * 1024 * 1024, $host['Memory']);
        $this->assertSame(1_000_000_000, $host['NanoCpus']);
        $this->assertContains('no-new-privileges:true', $host['SecurityOpt']);
        $this->assertSame('pc-net-shop', $host['NetworkMode']);
        $this->assertContains('API_TOKEN=super-secret-token-123', $this->docker->containers['pc-shop-1']['Config']['Env']);
        $this->assertContains('PORT=3000', $this->docker->containers['pc-shop-1']['Config']['Env']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'deployment.succeeded']);
    }

    public function test_secret_values_are_redacted_from_build_logs(): void
    {
        $this->actingAsAdmin();
        $deployment = $this->deploy();

        $logs = $deployment->logs()->pluck('line')->implode("\n");
        $this->assertStringContainsString('RUN echo [secret]', $logs);
        $this->assertStringNotContainsString('super-secret-token-123', $logs);

        // Build args are passed by name only; values travel through the environment.
        $this->project->environmentVariables()->where('key', 'API_TOKEN')->update(['available_at_build' => true]);
        $this->deploy();
        $build = collect($this->runner->commandsFor('docker'))->last();
        $this->assertContains('API_TOKEN', $build);
        $this->assertNotContains('super-secret-token-123', $build);
    }

    public function test_successful_update_retires_previous_container_but_keeps_its_image(): void
    {
        $this->actingAsAdmin();
        $first = $this->deploy()->fresh();
        $second = $this->deploy()->fresh();

        $this->assertSame(DeploymentStatus::Success, $second->status);
        $this->assertSame($second->id, $this->project->fresh()->current_deployment_id);
        $this->assertTrue($this->docker->isRunning('pc-shop-2'));
        $this->assertArrayNotHasKey('pc-shop-1', $this->docker->containers, 'old container removed after the switch');

        // The old container was only stopped AFTER the new one was started and routed.
        $calls = $this->docker->calls;
        $this->assertLessThan(array_search('stop:pc-shop-1', $calls, true), array_search('create:pc-shop-2', $calls, true));
        $this->assertArrayHasKey('pc-shop:1', $this->docker->images, 'previous image retained for rollback');
        $this->assertTrue($first->fresh()->image_available);
    }

    public function test_build_failure_keeps_production_running_and_explains_the_error(): void
    {
        $this->actingAsAdmin();
        $live = $this->deploy()->fresh();
        $this->buildFails = true;

        $failed = $this->deploy()->fresh();

        $this->assertSame(['cloning', 'building', 'failed'], $this->transitions);
        $this->assertSame('building', $failed->failure_stage);
        $this->assertSame("Error: Cannot find module 'xyz'", $failed->failure_reason);
        $this->getJson("/api/v1/projects/shop/deployments/{$failed->id}")->assertOk()
            ->assertJsonPath('data.failure.step', 'npm run build')
            ->assertJsonPath('data.status', 'failed');

        $project = $this->project->fresh();
        $this->assertSame($live->id, $project->current_deployment_id, 'production deployment unchanged');
        $this->assertSame(ProjectStatus::Running, $project->status);
        $this->assertTrue($this->docker->isRunning('pc-shop-1'), 'production container untouched');
        $this->assertStringContainsString('reverse_proxy pc-shop-1:3000', File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy'));
    }

    public function test_health_check_failure_removes_candidate_and_keeps_previous_version(): void
    {
        $this->actingAsAdmin();
        $live = $this->deploy()->fresh();
        $this->healthStatus = 500;

        $failed = $this->deploy()->fresh();

        $this->assertSame(DeploymentStatus::Failed, $failed->status);
        $this->assertSame('health_checking', $failed->failure_stage);
        $this->assertStringContainsString('HTTP 500', $failed->failure_reason);
        $this->assertArrayNotHasKey('pc-shop-2', $this->docker->containers, 'failed candidate removed');
        $this->assertTrue($this->docker->isRunning('pc-shop-1'));
        $this->assertSame($live->id, $this->project->fresh()->current_deployment_id);
        $this->assertTrue($failed->logs()->where('stream', 'container')->exists(), 'container output captured for debugging');
    }

    public function test_failed_deployment_removes_its_own_image_but_never_production_images(): void
    {
        $this->actingAsAdmin();
        $this->deploy();
        $this->healthStatus = 500;

        $failed = $this->deploy()->fresh();

        $this->assertArrayNotHasKey('pc-shop:2', $this->docker->images, 'image of the failed deployment removed');
        $this->assertFalse($failed->image_available);
        $this->assertArrayHasKey('pc-shop:1', $this->docker->images, 'production image kept');
    }

    public function test_failure_reason_never_stores_secret_values(): void
    {
        $this->actingAsAdmin();
        $this->runner->prepend(fn (array $cmd) => basename($cmd[0]) === 'docker' && in_array('build', $cmd, true), function (array $cmd, ?callable $onLine) {
            foreach (['#7 [4/5] RUN ./check', '#7 1.2 Error: token super-secret-token-123 was rejected', '#7 ERROR: process "/bin/sh -c ./check" did not complete successfully: exit code: 1'] as $line) {
                $onLine('err', $line);
            }

            return new CommandResult(1, '', '');
        });

        $failed = $this->deploy()->fresh();

        $this->assertSame(DeploymentStatus::Failed, $failed->status);
        $this->assertStringNotContainsString('super-secret-token-123', (string) $failed->failure_reason);
        $this->assertStringContainsString('[secret]', (string) $failed->failure_reason);
        $this->assertStringNotContainsString('super-secret-token-123', (string) $failed->failure_detail);
    }

    public function test_reserved_build_argument_fails_the_build_cleanly(): void
    {
        $this->actingAsAdmin();
        $this->project->environmentVariables()->create(['key' => 'LD_PRELOAD', 'value' => '/tmp/x.so', 'available_at_build' => true]);

        $failed = $this->deploy()->fresh();

        $this->assertSame('building', $failed->failure_stage);
        $this->assertStringContainsString('LD_PRELOAD', (string) $failed->failure_reason);
        $this->assertSame([], array_filter($this->runner->commandsFor('docker'), fn ($c) => in_array('build', $c, true)), 'docker build never ran');
    }

    public function test_volume_owned_by_another_project_is_never_mounted(): void
    {
        $this->actingAsAdmin();
        $this->project->volumes()->create(['name' => 'data', 'mount_path' => '/data', 'docker_name' => 'pc-vol-shared']);
        $this->docker->volumes['pc-vol-shared'] = ['privatecloud.managed' => 'true', 'privatecloud.project' => '999'];

        $failed = $this->deploy()->fresh();

        $this->assertSame('starting', $failed->failure_stage);
        $this->assertStringContainsString('belongs to another project', (string) $failed->failure_reason);
        $this->assertSame([], array_filter($this->docker->calls, fn ($c) => str_starts_with($c, 'create:')), 'no container was created');
    }

    public function test_rollback_refuses_an_image_tag_that_now_points_to_a_different_image(): void
    {
        $this->actingAsAdmin();
        $first = $this->deploy()->fresh();
        $this->deploy();
        $this->docker->images['pc-shop:1'] = ['Id' => 'sha256:'.str_repeat('f', 64)];

        $response = $this->postJson("/api/v1/projects/shop/deployments/{$first->id}/rollback")->assertStatus(202);
        $rollback = Deployment::query()->findOrFail($response->json('data.id'));

        $this->assertSame(DeploymentStatus::Failed, $rollback->status);
        $this->assertStringContainsString('no longer the image deployed by #1', (string) $rollback->failure_reason);
        $this->assertSame(2, $this->project->fresh()->currentDeployment->number, 'production unchanged');
    }

    public function test_application_crash_during_startup_is_reported(): void
    {
        $this->actingAsAdmin();
        $this->docker->crashOnStart = 1;

        $failed = $this->deploy()->fresh();

        $this->assertSame(DeploymentStatus::Failed, $failed->status);
        $this->assertStringContainsString('exited during startup (exit code 1)', $failed->failure_reason);
        $this->assertSame(ProjectStatus::Failed, $this->project->fresh()->status, 'never deployed successfully');
        $this->assertNull($this->project->fresh()->current_deployment_id);
    }

    public function test_container_health_check_mode(): void
    {
        $this->actingAsAdmin();
        config(['privatecloud.deploy.container_stable_seconds' => 1]);
        $this->project->update(['health_check_type' => 'container']);
        $this->healthStatus = 500; // HTTP is not consulted in container mode

        $this->assertSame(DeploymentStatus::Success, $this->deploy()->fresh()->status);
    }

    public function test_routing_failure_keeps_previous_configuration(): void
    {
        $this->actingAsAdmin();
        $live = $this->deploy()->fresh();
        $siteBefore = File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy');
        $this->caddy->fail = true;

        $failed = $this->deploy()->fresh();

        $this->assertSame('routing', $failed->failure_stage);
        $this->assertSame($siteBefore, File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy'), 'site file restored');
        $this->assertArrayNotHasKey('pc-shop-2', $this->docker->containers);
        $this->assertSame($live->id, $this->project->fresh()->current_deployment_id);
    }

    public function test_rollback_starts_previous_image_and_switches_traffic(): void
    {
        $this->actingAsAdmin();
        $first = $this->deploy()->fresh();
        $this->deploy();
        $this->transitions = [];

        $response = $this->postJson("/api/v1/projects/shop/deployments/{$first->id}/rollback")->assertStatus(202);
        $rollback = Deployment::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['starting', 'health_checking', 'routing', 'success'], $this->transitions, 'no rebuild on rollback');
        $this->assertSame(Deployment::TYPE_ROLLBACK, $rollback->type);
        $this->assertSame('pc-shop:1', $rollback->image_tag);
        $this->assertSame($first->commit_sha, $rollback->commit_sha);
        $this->assertSame($rollback->id, $this->project->fresh()->current_deployment_id);
        $this->assertTrue($this->docker->isRunning('pc-shop-3'));
        $this->assertArrayNotHasKey('pc-shop-2', $this->docker->containers);
        $this->assertSame(3, Deployment::query()->count(), 'history preserved');
    }

    public function test_rollback_is_refused_when_image_was_pruned_or_deployment_failed(): void
    {
        $this->actingAsAdmin();
        $first = $this->deploy()->fresh();
        $this->deploy();
        $first->update(['image_available' => false]);

        $this->postJson("/api/v1/projects/shop/deployments/{$first->id}/rollback")->assertStatus(422);

        $failed = Deployment::factory()->create(['project_id' => $this->project->id, 'status' => DeploymentStatus::Failed]);
        $this->postJson("/api/v1/projects/shop/deployments/{$failed->id}/rollback")->assertStatus(422);
    }

    public function test_image_retention_removes_old_images(): void
    {
        $this->actingAsAdmin();
        $this->project->update(['image_retention' => 1]);
        $this->deploy();
        $this->deploy();
        $this->deploy();

        $this->assertArrayNotHasKey('pc-shop:1', $this->docker->images);
        $this->assertArrayHasKey('pc-shop:2', $this->docker->images, 'one previous image kept');
        $this->assertArrayHasKey('pc-shop:3', $this->docker->images, 'live image kept');
        $this->assertFalse(Deployment::query()->where('number', 1)->first()->image_available);
    }

    public function test_missing_dockerfile_gives_a_helpful_message(): void
    {
        $this->actingAsAdmin();
        $this->withDockerfile = false;

        $failed = $this->deploy()->fresh();

        $this->assertSame('building', $failed->failure_stage);
        $this->assertStringContainsString('No Dockerfile found', $failed->failure_reason);
        $this->assertStringContainsString('Node.js', $failed->failure_reason);
    }

    public function test_github_outage_fails_cleanly(): void
    {
        $this->actingAsAdmin();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $failed = $this->deploy()->fresh();

        $this->assertSame('cloning', $failed->failure_stage);
        $this->assertStringContainsString('GitHub could not be reached', $failed->failure_reason);
    }

    public function test_low_disk_space_stops_before_building(): void
    {
        $this->actingAsAdmin();
        config(['privatecloud.deploy.min_free_disk_mb' => PHP_INT_MAX >> 21]);

        $failed = $this->deploy()->fresh();

        $this->assertStringContainsString('Not enough free disk space', $failed->failure_reason);
        $this->assertSame([], $this->runner->commandsFor('docker'));
    }

    public function test_a_newer_request_supersedes_a_queued_deployment(): void
    {
        Bus::fake();
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/projects/shop/deployments')->assertStatus(202)->json('data.id');
        $second = $this->postJson('/api/v1/projects/shop/deployments')->assertStatus(202)->json('data.id');

        $this->assertSame(DeploymentStatus::Cancelled, Deployment::query()->find($first)->status);
        $this->assertSame('Superseded by deployment #2.', Deployment::query()->find($first)->failure_reason);
        $this->assertSame(DeploymentStatus::Queued, Deployment::query()->find($second)->status);
        Bus::assertDispatchedTimes(RunDeployment::class, 2);
    }

    public function test_deployment_jobs_share_a_per_project_lock(): void
    {
        $job = new RunDeployment(1, $this->project->id);
        $middleware = $job->middleware()[0];

        $this->assertSame('project:'.$this->project->id, $middleware->key);
        $this->assertTrue($middleware->shareKey);
        $this->assertSame('deployments', $job->queue);
    }

    public function test_cancel_queued_deployment(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/projects/shop/deployments')->json('data.id');

        $this->postJson("/api/v1/projects/shop/deployments/{$id}/cancel")->assertOk()->assertJsonPath('message', 'Deployment cancelled.');
        $this->postJson("/api/v1/projects/shop/deployments/{$id}/cancel")->assertStatus(409);
    }

    public function test_deployment_logs_can_be_polled_incrementally(): void
    {
        $this->actingAsAdmin();
        $deployment = $this->deploy();

        $all = $this->getJson("/api/v1/projects/shop/deployments/{$deployment->id}/logs")->assertOk()->json('lines');
        $this->assertNotEmpty($all);
        $last = end($all)['id'];
        $this->getJson("/api/v1/projects/shop/deployments/{$deployment->id}/logs?after_id={$last}")->assertOk()->assertJsonPath('lines', []);
    }

    public function test_deployments_from_another_project_are_not_reachable(): void
    {
        $this->actingAsAdmin();
        $other = $this->project(['name' => 'Other', 'slug' => 'other']);
        $foreign = Deployment::factory()->create(['project_id' => $other->id]);

        $this->getJson("/api/v1/projects/shop/deployments/{$foreign->id}")->assertNotFound();
    }
}
