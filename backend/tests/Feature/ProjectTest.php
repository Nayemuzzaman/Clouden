<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeleteProject;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGitHub(): void
    {
        Http::fake([
            'api.github.com/repos/acme/shop/commits/*' => Http::response([
                'sha' => str_repeat('c', 40),
                'commit' => ['message' => "Fix homepage layout\n\nDetails", 'author' => ['name' => 'Ada', 'date' => '2026-10-01T10:00:00Z']],
            ]),
            'api.github.com/repos/missing/repo/commits/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
    }

    public function test_create_github_project_with_domain_and_environment(): void
    {
        $this->fakeGitHub();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Japan Lingo',
            'source_type' => 'github',
            'repository' => 'acme/shop',
            'branch' => 'main',
            'domain' => 'App.Example.com',
            'memory_limit_mb' => 1024,
            'cpu_limit' => 1,
            'environment' => [
                ['key' => 'APP_ENV', 'value' => 'production'],
                ['key' => 'APP_KEY', 'value' => 'base64:supersecretvalue'],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.slug', 'japan-lingo')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.repository.full_name', 'acme/shop')
            ->assertJsonPath('data.repository.latest_commit.short_sha', 'ccccccc')
            ->assertJsonPath('data.repository.latest_commit.message', 'Fix homepage layout')
            ->assertJsonPath('data.primary_domain', 'app.example.com')
            ->assertJsonPath('warnings', []);

        $project = Project::query()->where('slug', 'japan-lingo')->firstOrFail();
        $this->assertTrue($project->environmentVariables()->where('key', 'APP_KEY')->first()->is_secret, 'APP_KEY is detected as a secret');
        $this->assertFalse($project->environmentVariables()->where('key', 'APP_ENV')->first()->is_secret);
        $this->assertSame(1, $this->caddy->reloads, 'adding the domain regenerated the routing configuration');
        $this->assertFileExists(config('privatecloud.caddy.sites_dir').'/japan-lingo.caddy');
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created']);
    }

    public function test_slugs_are_unique(): void
    {
        $this->fakeGitHub();
        $this->actingAsAdmin();
        $payload = ['name' => 'Shop', 'source_type' => 'github', 'repository' => 'acme/shop', 'branch' => 'main'];
        $this->postJson('/api/v1/projects', $payload)->assertCreated()->assertJsonPath('data.slug', 'shop');
        $this->postJson('/api/v1/projects', $payload)->assertCreated()->assertJsonPath('data.slug', 'shop-2');
    }

    public function test_validation_rejects_unsafe_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/projects', [
            'name' => 'Bad', 'source_type' => 'github', 'repository' => 'not a repo', 'branch' => '--upload-pack=evil',
        ])->assertStatus(422)->assertJsonValidationErrors(['repository', 'branch']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Bad', 'source_type' => 'git', 'repository_url' => 'http://127.0.0.1/repo.git', 'branch' => 'main',
        ])->assertStatus(422)->assertJsonValidationErrors(['repository_url']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Bad', 'source_type' => 'git', 'repository_url' => 'https://user:pass@github.com/a/b.git', 'branch' => 'main',
        ])->assertStatus(422)->assertJsonValidationErrors(['repository_url']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Bad', 'source_type' => 'github', 'repository' => 'acme/shop', 'branch' => 'main',
            'dockerfile_path' => '../../etc/passwd',
            'volumes' => [['name' => 'data', 'mount_path' => '/proc/self']],
            'environment' => [['key' => '1INVALID', 'value' => 'x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['dockerfile_path', 'volumes.0.mount_path', 'environment.0.key']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_project_creation_reports_unreachable_repository_as_warning(): void
    {
        $this->fakeGitHub();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/projects', ['name' => 'Ghost', 'source_type' => 'github', 'repository' => 'missing/repo', 'branch' => 'main'])
            ->assertCreated()
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'not found'));
    }

    public function test_update_settings(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();

        $this->patchJson("/api/v1/projects/{$project->slug}", [
            'memory_limit_mb' => 256, 'cpu_limit' => 0.5, 'health_check_path' => '/health', 'branch' => 'develop', 'health_check_type' => 'container',
        ])->assertOk()
            ->assertJsonPath('data.memory_limit_mb', 256)
            ->assertJsonPath('data.cpu_limit', 0.5)
            ->assertJsonPath('data.health_check.type', 'container')
            ->assertJsonPath('data.repository.branch', 'develop');

        $this->patchJson("/api/v1/projects/{$project->slug}", ['memory_limit_mb' => 999999])->assertJsonValidationErrors('memory_limit_mb');
        $this->patchJson("/api/v1/projects/{$project->slug}", ['health_check_status_min' => 300, 'health_check_status_max' => 200])->assertJsonValidationErrors('health_check_status_max');
    }

    public function test_deletion_requires_typing_the_project_name(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $project = $this->project(['name' => 'Production Shop']);

        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'production shop'])->assertJsonValidationErrors('confirm');
        $this->assertFalse($project->fresh()->isDeleting());
        Bus::assertNotDispatched(DeleteProject::class);

        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'Production Shop'])->assertStatus(202)->assertJsonPath('data.type', 'project.delete');
        $this->assertTrue($project->fresh()->isDeleting());
        Bus::assertDispatched(DeleteProject::class);
    }

    public function test_deletion_impact_lists_affected_resources(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        Domain::query()->create(['project_id' => $project->id, 'hostname' => 'shop.example.com']);
        $project->volumes()->create(['name' => 'uploads', 'mount_path' => '/app/uploads', 'docker_name' => 'pc-vol-x-uploads']);

        $this->getJson("/api/v1/projects/{$project->slug}/deletion-impact")->assertOk()
            ->assertJsonPath('domains.0', 'shop.example.com')
            ->assertJsonPath('volumes.0.name', 'uploads');
    }

    public function test_deleting_project_blocks_new_deployments_and_cancels_queued_ones(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $project = $this->project(['name' => 'Shop']);
        $queued = Deployment::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'Shop'])->assertStatus(202);

        $this->assertSame(DeploymentStatus::Cancelled, $queued->fresh()->status);
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('message', 'The project is being deleted.');
        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'Shop'])->assertStatus(422);
        Bus::assertNotDispatched(RunDeployment::class);
    }

    public function test_project_deleter_keeps_databases_and_volumes_unless_chosen(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['name' => 'Shop']);
        $db = $project->databases()->create(['server_id' => $project->server_id, 'name' => 'shop', 'username' => 'shop', 'password' => 'x', 'host' => 'h', 'status' => 'ready']);
        $project->volumes()->create(['name' => 'uploads', 'mount_path' => '/app/uploads', 'docker_name' => 'pc-vol-shop-uploads']);
        $this->docker->volumes['pc-vol-shop-uploads'] = true;

        // QUEUE_CONNECTION=sync: the job runs immediately.
        $this->deleteJson("/api/v1/projects/{$project->slug}", ['confirm' => 'Shop', 'delete_databases' => false, 'delete_volumes' => false])->assertStatus(202);

        $this->assertNull(Project::query()->find($project->id));
        $this->assertNull($db->fresh()->project_id, 'database is kept as a standalone database');
        $this->assertArrayHasKey('pc-vol-shop-uploads', $this->docker->volumes, 'volume data is kept');
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.deleted']);
    }

    public function test_start_stop_restart_controls(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['status' => ProjectStatus::Running]);
        $this->docker->addImage('pc-x:1');
        $id = $this->docker->createContainer('pc-shop-1', ['Image' => 'pc-x:1']);
        $this->docker->startContainer($id);
        $deployment = Deployment::factory()->live()->create(['project_id' => $project->id, 'container_name' => 'pc-shop-1']);
        $project->update(['current_deployment_id' => $deployment->id]);

        $this->postJson("/api/v1/projects/{$project->slug}/stop")->assertOk()->assertJsonPath('status', 'stopped');
        $this->assertFalse($this->docker->isRunning('pc-shop-1'));
        $this->postJson("/api/v1/projects/{$project->slug}/start")->assertOk()->assertJsonPath('status', 'running');
        $this->assertTrue($this->docker->isRunning('pc-shop-1'));
        $this->postJson("/api/v1/projects/{$project->slug}/restart")->assertOk();

        $this->getJson("/api/v1/projects/{$project->slug}/container")->assertOk()
            ->assertJsonPath('container.running', true)
            ->assertJsonPath('usage.memory_used_bytes', 100 * 1024 * 1024);
    }

    public function test_controls_explain_when_never_deployed(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $this->postJson("/api/v1/projects/{$project->slug}/restart")->assertStatus(422)->assertJsonPath('message', 'This project has not been deployed yet.');
    }
}
