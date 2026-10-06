<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_log_in_and_fetch_profile(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_and_audited_without_the_password(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $log = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame('failure', $log->result);
        $this->assertStringNotContainsString('wrong-password', json_encode($log->toArray()));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1'])->assertStatus(429);
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'a@b.c', 'password' => 'x'])->assertNotFound();
        $this->postJson('/register')->assertStatus(405);
        $this->assertSame(0, User::query()->count());
    }

    public function test_management_api_requires_authentication(): void
    {
        foreach (['/api/v1/dashboard', '/api/v1/projects', '/api/v1/databases', '/api/v1/backups', '/api/v1/server/metrics'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_password_confirmation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct-horse-battery-1'])->assertOk()->assertJsonPath('confirmed', true);
    }

    public function test_change_password_requires_current_password_and_strength(): void
    {
        $user = $this->actingAsAdmin();

        $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', ['current_password' => 'correct-horse-battery-1', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/auth/password', ['current_password' => 'correct-horse-battery-1', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertOk();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_admin_cli_command_creates_a_single_admin(): void
    {
        $this->artisan('privatecloud:admin', ['--email' => 'admin@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', 'Sup3r-secret-pass')
            ->expectsQuestion('Confirm password', 'Sup3r-secret-pass')
            ->assertSuccessful();
        $this->assertTrue(User::query()->where('email', 'admin@example.com')->first()->isAdmin());

        // A second administrator cannot be created from the CLI in V1.
        $this->artisan('privatecloud:admin', ['--email' => 'other@example.com'])->assertFailed();
    }
}
