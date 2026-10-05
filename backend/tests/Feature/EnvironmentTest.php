<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_are_encrypted_at_rest(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();

        $this->postJson("/api/v1/projects/{$project->slug}/environment", ['key' => 'DB_PASSWORD', 'value' => 'hunter2-very-secret'])->assertCreated();

        $raw = DB::table('environment_variables')->where('key', 'DB_PASSWORD')->value('value');
        $this->assertNotSame('hunter2-very-secret', $raw);
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertSame('hunter2-very-secret', EnvironmentVariable::query()->where('key', 'DB_PASSWORD')->first()->value);
    }

    public function test_listing_never_returns_secret_values(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $project->environmentVariables()->create(['key' => 'APP_ENV', 'value' => 'production', 'is_secret' => false]);
        $project->environmentVariables()->create(['key' => 'APP_KEY', 'value' => 'base64:topsecret', 'is_secret' => true]);

        $response = $this->getJson("/api/v1/projects/{$project->slug}/environment")->assertOk();

        $response->assertJsonPath('data.0.key', 'APP_ENV')->assertJsonPath('data.0.value', 'production');
        $response->assertJsonPath('data.1.key', 'APP_KEY')->assertJsonPath('data.1.value', null)->assertJsonPath('data.1.is_secret', true);
        $this->assertStringNotContainsString('topsecret', $response->getContent());
        $this->assertStringNotContainsString('topsecret', $this->getJson("/api/v1/projects/{$project->slug}")->getContent());
    }

    public function test_reveal_requires_recent_password_confirmation_and_is_audited(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $variable = $project->environmentVariables()->create(['key' => 'APP_KEY', 'value' => 'base64:topsecret', 'is_secret' => true]);

        $this->postJson("/api/v1/projects/{$project->slug}/environment/{$variable->id}/reveal")
            ->assertStatus(423)->assertJsonPath('code', 'password_confirmation_required');

        $this->withConfirmedPassword()->postJson("/api/v1/projects/{$project->slug}/environment/{$variable->id}/reveal")
            ->assertOk()->assertJsonPath('value', 'base64:topsecret');

        $log = AuditLog::query()->where('action', 'environment.revealed')->firstOrFail();
        $this->assertSame('APP_KEY', $log->metadata['key']);
        $this->assertStringNotContainsString('topsecret', json_encode(AuditLog::all()->toArray()));
    }

    public function test_audit_log_never_contains_values_on_change(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $this->postJson("/api/v1/projects/{$project->slug}/environment", ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_abcdef'])->assertCreated();
        $variable = EnvironmentVariable::query()->firstOrFail();
        $this->putJson("/api/v1/projects/{$project->slug}/environment/{$variable->id}", ['value' => 'sk_live_changed'])->assertOk();

        $this->assertStringNotContainsString('sk_live', json_encode(AuditLog::all()->toArray()));
        $this->assertSame(2, AuditLog::query()->where('action', 'like', 'environment.%')->count());
    }

    public function test_bulk_import_from_dotenv(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $project->environmentVariables()->create(['key' => 'EXISTING', 'value' => 'old']);

        $content = "# comment\nAPP_ENV=production\nexport APP_NAME=\"My App\"\nEXISTING=new\nMULTI=\"line1\\nline2\"\nbad line\n";
        $this->postJson("/api/v1/projects/{$project->slug}/environment/import", ['content' => $content])
            ->assertOk()->assertJson(['created' => 3, 'updated' => 0, 'skipped' => 1])->assertJsonCount(1, 'errors');
        $this->assertSame('old', $project->environmentVariables()->where('key', 'EXISTING')->first()->value);
        $this->assertSame("line1\nline2", $project->environmentVariables()->where('key', 'MULTI')->first()->value);

        $this->postJson("/api/v1/projects/{$project->slug}/environment/import", ['content' => 'EXISTING=new', 'overwrite' => true])->assertJson(['updated' => 1]);
        $this->assertSame('new', $project->environmentVariables()->where('key', 'EXISTING')->first()->value);
    }

    public function test_key_validation_and_duplicates(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $this->postJson("/api/v1/projects/{$project->slug}/environment", ['key' => 'BAD-KEY', 'value' => 'x'])->assertJsonValidationErrors('key');
        $this->postJson("/api/v1/projects/{$project->slug}/environment", ['key' => 'GOOD_KEY', 'value' => 'x'])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->slug}/environment", ['key' => 'GOOD_KEY', 'value' => 'y'])->assertJsonValidationErrors('key');
    }

    public function test_variables_of_other_projects_are_not_accessible(): void
    {
        $this->actingAsAdmin();
        $a = $this->project();
        $b = $this->project();
        $variable = $b->environmentVariables()->create(['key' => 'SECRET', 'value' => 'b-secret', 'is_secret' => true]);

        $this->withConfirmedPassword()->postJson("/api/v1/projects/{$a->slug}/environment/{$variable->id}/reveal")->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$a->slug}/environment/{$variable->id}")->assertNotFound();
    }

    public function test_environment_reports_pending_redeploy(): void
    {
        $this->actingAsAdmin();
        $project = $this->project();
        $deployment = Deployment::factory()->live()->create(['project_id' => $project->id]);
        $project->update(['current_deployment_id' => $deployment->id]);
        $this->travel(1)->minutes();
        $project->environmentVariables()->create(['key' => 'NEW', 'value' => '1']);

        $this->getJson("/api/v1/projects/{$project->slug}/environment")->assertJsonPath('pending_redeploy', true);
    }
}
