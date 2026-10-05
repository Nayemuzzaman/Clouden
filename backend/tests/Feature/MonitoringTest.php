<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Deployment;
use App\Models\Metric;
use App\Models\Setting;
use App\Services\Monitoring\HostMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_metrics_are_read_from_procfs(): void
    {
        $metrics = app(HostMetrics::class)->snapshot();

        $this->assertSame(8000000 * 1024, $metrics['memory_total_bytes']);
        $this->assertSame((8000000 - 4600000) * 1024, $metrics['memory_used_bytes']);
        $this->assertSame([0.52, 0.40, 0.31], $metrics['load']);
        $this->assertSame(1468800, $metrics['uptime_seconds']);
        $this->assertSame(2, $metrics['cpu_cores']);
        $this->assertSame(5000000, $metrics['network']['rx_bytes'], 'loopback excluded');
        $this->assertSame('test-host', $metrics['hostname']);
        $this->assertNotNull($metrics['disk_total_bytes']);
    }

    public function test_dashboard_returns_server_and_project_summary(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['name' => 'JapanLingo', 'status' => ProjectStatus::Running]);
        $deployment = Deployment::factory()->live()->create(['project_id' => $project->id, 'container_name' => 'pc-x-1']);
        $project->update(['current_deployment_id' => $deployment->id]);
        Metric::query()->create(['server_id' => $project->server_id, 'project_id' => $project->id, 'cpu_percent' => 4.2, 'memory_used_bytes' => 620 * 1024 * 1024, 'recorded_at' => now()]);

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('server.memory_total_bytes', 8000000 * 1024)
            ->assertJsonPath('projects.0.name', 'JapanLingo')
            ->assertJsonPath('projects.0.status', 'running')
            ->assertJsonPath('projects.0.cpu_percent', 4.2)
            ->assertJsonPath('projects.0.latest_deployment.status', 'success')
            ->assertJsonStructure(['services' => [['key', 'name', 'status']], 'thresholds' => ['cpu', 'memory', 'disk']]);
    }

    public function test_metrics_collection_and_threshold_alerts(): void
    {
        $this->admin();
        $project = $this->project(['status' => ProjectStatus::Running]);
        $this->docker->addImage('img');
        $this->docker->startContainer($this->docker->createContainer('pc-x-1', ['Image' => 'img']));
        $deployment = Deployment::factory()->live()->create(['project_id' => $project->id, 'container_name' => 'pc-x-1']);
        $project->update(['current_deployment_id' => $deployment->id]);
        Setting::put('monitoring.thresholds', ['cpu' => 90, 'memory' => 10, 'disk' => 99]);

        $this->artisan('privatecloud:metrics')->assertSuccessful();
        $this->artisan('privatecloud:metrics')->assertSuccessful();

        $this->assertSame(2, Metric::query()->whereNull('project_id')->count());
        $this->assertSame(2, Metric::query()->where('project_id', $project->id)->count());
        // Memory is 42.5% used > 10% threshold: one notification despite two samples (cooldown).
        $this->assertSame(1, DB::table('notifications')->where('data', 'like', '%server.memory_high%')->count());
    }

    public function test_reconciler_detects_crashed_application(): void
    {
        $this->admin();
        $project = $this->project(['status' => ProjectStatus::Running]);
        $deployment = Deployment::factory()->live()->create(['project_id' => $project->id, 'container_name' => 'pc-gone-1']);
        $project->update(['current_deployment_id' => $deployment->id]);

        $this->artisan('privatecloud:reconcile')->assertSuccessful();

        $this->assertSame(ProjectStatus::Crashed, $project->fresh()->status);
    }

    public function test_reconciler_fails_stale_deployments(): void
    {
        $deployment = Deployment::factory()->create(['status' => 'building', 'started_at' => now()->subHours(3)]);
        $this->artisan('privatecloud:reconcile')->assertSuccessful();
        $this->assertSame('failed', $deployment->fresh()->status->value);
    }

    public function test_thresholds_are_configurable(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/v1/settings/thresholds', ['cpu' => 80, 'memory' => 85, 'disk' => 75])->assertOk()->assertJsonPath('thresholds.disk', 75);
        $this->putJson('/api/v1/settings/thresholds', ['cpu' => 500, 'memory' => 85, 'disk' => 75])->assertJsonValidationErrors('cpu');
    }
}
