<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->project = $this->project(['auto_deploy' => true, 'webhook_secret' => 'whsec-123']);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload, string $event = 'push', ?string $delivery = null, ?string $secret = 'whsec-123'): TestResponse
    {
        $body = json_encode($payload);
        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $delivery ?? (string) Str::uuid(),
            'Content-Type' => 'application/json',
        ];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', "/api/v1/webhooks/github/{$this->project->uuid}", [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** @return array<string, mixed> */
    private function push(string $branch = 'main'): array
    {
        return [
            'ref' => 'refs/heads/'.$branch,
            'after' => str_repeat('d', 40),
            'repository' => ['full_name' => 'acme/shop'],
            'head_commit' => ['message' => "Add feature\n\nbody", 'author' => ['name' => 'Grace'], 'timestamp' => '2026-10-05T10:00:00Z'],
        ];
    }

    public function test_valid_push_to_configured_branch_queues_a_deployment(): void
    {
        $this->send($this->push())->assertStatus(202)->assertJsonPath('status', 'queued');

        $deployment = Deployment::query()->firstOrFail();
        $this->assertSame('webhook', $deployment->trigger);
        $this->assertSame(str_repeat('d', 40), $deployment->commit_sha);
        $this->assertSame('Add feature', $this->project->repository->fresh()->latest_commit_message);
        Bus::assertDispatched(RunDeployment::class);
    }

    public function test_invalid_or_missing_signature_is_rejected(): void
    {
        $this->send($this->push(), secret: 'wrong-secret')->assertStatus(401);
        $this->send($this->push(), secret: null)->assertStatus(401);

        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, WebhookEvent::query()->count(), 'unauthenticated payloads are not stored');
    }

    public function test_pushes_to_other_branches_are_ignored(): void
    {
        $this->send($this->push('feature/x'))->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame('ignored', WebhookEvent::query()->first()->status);
    }

    public function test_duplicate_delivery_does_not_deploy_twice(): void
    {
        $this->send($this->push(), delivery: 'delivery-1')->assertStatus(202);
        $this->send($this->push(), delivery: 'delivery-1')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_auto_deploy_off_ignores_pushes_but_records_latest_commit(): void
    {
        $this->project->update(['auto_deploy' => false]);
        $this->send($this->push())->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(str_repeat('d', 40), $this->project->repository->fresh()->latest_commit_sha);
    }

    public function test_ping_and_other_events(): void
    {
        $this->send(['zen' => 'hi'], 'ping')->assertOk()->assertJsonPath('message', 'Webhook connected.');
        $this->send(['action' => 'opened'], 'pull_request')->assertOk()->assertJsonPath('status', 'ignored');
    }

    public function test_push_for_another_repository_is_ignored(): void
    {
        $payload = $this->push();
        $payload['repository']['full_name'] = 'evil/repo';
        $this->send($payload)->assertOk()->assertJsonPath('status', 'ignored');
    }

    public function test_unknown_project_returns_not_found(): void
    {
        $this->call('POST', '/api/v1/webhooks/github/'.Str::uuid(), [], [], [], [], '{}')->assertNotFound();
    }

    public function test_rapid_pushes_queue_safely(): void
    {
        $this->send($this->push(), delivery: 'a')->assertStatus(202);
        $second = $this->push();
        $second['after'] = str_repeat('e', 40);
        $this->send($second, delivery: 'b')->assertStatus(202);

        // The first (still queued) deployment is superseded by the newer push.
        $this->assertSame(['cancelled', 'queued'], Deployment::query()->orderBy('number')->get()->map(fn ($d) => $d->status->value)->all());
    }
}
