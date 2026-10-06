<?php

namespace Tests\Feature;

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_domain_routes_it_and_checks_dns(): void
    {
        $this->actingAsAdmin();
        $project = $this->project(['slug' => 'shop']);

        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'https://App.Example.com/'])
            ->assertCreated()
            ->assertJsonPath('data.hostname', 'app.example.com')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.dns.status', 'ok')
            ->assertJsonPath('data.certificate.status', 'pending');

        $this->assertSame(1, $this->caddy->reloads);
        $site = File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy');
        $this->assertStringContainsString('app.example.com {', $site);
        $this->assertStringContainsString('This application is not running', $site, 'not deployed yet: friendly 503 page');
        $this->assertDatabaseHas('audit_logs', ['action' => 'domain.added']);
    }

    public function test_dns_not_ready_is_reported(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);

        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'nodns.example.com'])
            ->assertCreated()->assertJsonPath('data.dns.status', 'missing')->assertJsonPath('data.dns.expected_ip', '203.0.113.10');
    }

    public function test_invalid_and_reserved_domains_are_rejected(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);

        foreach (['not a domain', '*.example.com', '127.0.0.1', 'example.com:8080', 'http://a/b', '-bad.example.com', 'localhost', 'app.localhost'] as $bad) {
            $this->postJson('/api/v1/projects/shop/domains', ['hostname' => $bad])->assertStatus(422);
        }
        // The dashboard's own hostname is reserved.
        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'cloud.example.com'])->assertStatus(422)
            ->assertJsonPath('message', 'This hostname is used by the PrivateCloud dashboard itself.');
        $this->assertSame(0, Domain::query()->count());
        $this->assertSame(0, $this->caddy->reloads);
    }

    public function test_duplicate_domain_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);
        $this->project(['slug' => 'blog']);
        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'app.example.com'])->assertCreated();
        $this->postJson('/api/v1/projects/blog/domains', ['hostname' => 'APP.example.com'])->assertStatus(422);
    }

    public function test_caddy_failure_does_not_leave_a_half_added_domain(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);
        $this->caddy->fail = true;

        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'app.example.com'])->assertStatus(422);

        $this->assertSame(0, Domain::query()->count());
        $this->assertFileDoesNotExist(config('privatecloud.caddy.sites_dir').'/shop.caddy');
    }

    public function test_removing_domain_promotes_next_primary_and_updates_routing(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);
        $first = $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'a.example.com'])->json('data.id');
        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'b.example.com'])->assertJsonPath('data.is_primary', false);

        $this->deleteJson("/api/v1/projects/shop/domains/{$first}")->assertNoContent();

        $this->assertTrue(Domain::query()->where('hostname', 'b.example.com')->first()->is_primary);
        $this->assertStringNotContainsString('a.example.com', File::get(config('privatecloud.caddy.sites_dir').'/shop.caddy'));
    }

    public function test_internationalized_domains_are_stored_as_punycode(): void
    {
        $this->actingAsAdmin();
        $this->project(['slug' => 'shop']);
        $this->postJson('/api/v1/projects/shop/domains', ['hostname' => 'bücher.example.com'])
            ->assertCreated()
            ->assertJsonPath('data.hostname', 'xn--bcher-kva.example.com')
            ->assertJsonPath('data.unicode_hostname', 'bücher.example.com');
    }
}
