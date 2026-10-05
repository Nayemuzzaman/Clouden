<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\ProjectDatabase;
use App\Models\Server;
use App\Notifications\PlatformNotification;
use App\Services\Backups\LocalBackupStorage;
use App\Services\Databases\PostgresProvisioner;
use App\Services\Process\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private ProjectDatabase $database;

    private bool $dumpFails = false;

    private bool $verifyFails = false;

    /** @var list<string> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $project = $this->project(['slug' => 'shop', 'name' => 'Shop']);
        $this->database = ProjectDatabase::query()->create([
            'server_id' => Server::local()->id, 'project_id' => $project->id, 'name' => 'shop', 'username' => 'shop',
            'password' => 'db-password', 'host' => 'apps-db', 'port' => 5432, 'status' => 'ready',
        ]);

        $this->runner->onBinary('pg_dump', function (array $cmd) {
            if ($this->dumpFails) {
                return new CommandResult(1, '', 'pg_dump: error: connection to server failed');
            }
            File::put($cmd[array_search('--file', $cmd, true) + 1], 'PGDMP-fake-dump-content');

            return new CommandResult(0, '', '');
        });
        $this->runner->on(fn ($cmd) => $cmd[0] === 'pg_restore' && in_array('--list', $cmd, true), fn () => $this->verifyFails
            ? new CommandResult(1, '', 'pg_restore: error: input file does not appear to be a valid archive')
            : new CommandResult(0, ";\n; Archive created at 2026-10-05\n", ''));

        // Real PostgreSQL behaviour is covered by DatabaseIntegrationTest.
        $this->mock(PostgresProvisioner::class, fn ($mock) => $mock->shouldReceive('terminateConnections'));

        Backup::updated(function (Backup $b) {
            if ($b->wasChanged('status')) {
                $this->statuses[] = $b->status->value;
            }
        });
    }

    public function test_backup_is_only_successful_after_it_was_verified(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid])->assertStatus(202)
            ->assertJsonPath('data.0.status', 'queued'); // the API never claims success up front

        $backup = Backup::query()->firstOrFail();
        $this->assertSame(['running', 'success'], $this->statuses);
        $this->assertSame(JobStatus::Success, $backup->status);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame(strlen('PGDMP-fake-dump-content'), $backup->size_bytes);
        $this->assertSame(hash('sha256', 'PGDMP-fake-dump-content'), $backup->checksum_sha256);
        $this->assertStringStartsWith('databases/shop/', $backup->path);

        // The database password is passed via environment, never on the command line.
        $dump = $this->runner->commands[0];
        $this->assertNotContains('db-password', $dump['command']);
        $this->assertArrayHasKey('PGPASSWORD', $dump['env']);
    }

    public function test_failed_dump_is_marked_failed_and_leaves_no_file(): void
    {
        $this->actingAsAdmin();
        $this->dumpFails = true;

        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid])->assertStatus(202);

        $backup = Backup::query()->firstOrFail();
        $this->assertSame(JobStatus::Failed, $backup->status);
        $this->assertStringContainsString('connection to server failed', $backup->error);
        $this->assertNotContains('success', $this->statuses);
        $this->assertSame([], File::allFiles(config('privatecloud.backups.local_path')));
        $this->assertDatabaseHas('notifications', ['type' => PlatformNotification::class]);
    }

    public function test_unverifiable_backup_is_marked_failed(): void
    {
        $this->actingAsAdmin();
        $this->verifyFails = true;

        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid])->assertStatus(202);

        $this->assertSame(JobStatus::Failed, Backup::query()->first()->status);
        $this->assertStringContainsString('verification failed', Backup::query()->first()->error);
    }

    public function test_volume_backup_uses_an_isolated_helper_container(): void
    {
        $this->actingAsAdmin();
        $volume = $this->database->project->volumes()->create(['name' => 'uploads', 'mount_path' => '/app/uploads', 'docker_name' => 'pc-vol-shop-uploads']);
        $this->docker->onWait = function (array $spec) {
            $bind = collect($spec['HostConfig']['Mounts'])->firstWhere('Target', '/backup');
            File::put($bind['Source'].'/'.basename($spec['Cmd'][2]), gzencode('tar-content'));

            return 0;
        };

        $this->postJson('/api/v1/backups', ['volume_id' => $volume->id])->assertStatus(202);

        $backup = Backup::query()->firstOrFail();
        $this->assertSame(JobStatus::Success, $backup->status, (string) $backup->error);
        $this->assertStringStartsWith('volumes/shop/uploads/', $backup->path);
        $this->assertSame([], array_filter(array_keys($this->docker->containers), fn ($n) => str_contains($n, 'helper')), 'helper removed');
    }

    public function test_restore_requires_typed_confirmation_and_recent_password(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid]);
        $backup = Backup::query()->firstOrFail();

        $this->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'shop'])->assertStatus(423);
        $this->withConfirmedPassword()->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'wrong'])->assertJsonValidationErrors('confirm');
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_restore_takes_a_safety_backup_first(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid]);
        $backup = Backup::query()->firstOrFail();

        $response = $this->withConfirmedPassword()->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'shop'])->assertStatus(202);

        $operation = Operation::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame(JobStatus::Success, $operation->status, (string) $operation->error);
        $safety = Backup::query()->where('trigger', 'pre_restore')->firstOrFail();
        $this->assertSame(JobStatus::Success, $safety->status);

        $restore = collect($this->runner->commands)->first(fn ($c) => $c['command'][0] === 'pg_restore' && in_array('--clean', $c['command'], true));
        $this->assertNotNull($restore);
        $this->assertContains('--single-transaction', $restore['command']);
        $this->assertContains('--role', $restore['command']);
        $dumpIndex = collect($this->runner->commands)->search(fn ($c) => $c['command'][0] === 'pg_dump' && str_contains(implode(' ', $c['command']), 'shop'));
        $this->assertNotFalse($dumpIndex);
    }

    public function test_restore_is_aborted_when_safety_backup_fails(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid]);
        $backup = Backup::query()->firstOrFail();
        $this->dumpFails = true;

        $response = $this->withConfirmedPassword()->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'shop'])->assertStatus(202);

        $operation = Operation::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame(JobStatus::Failed, $operation->status);
        $this->assertStringContainsString('safety backup', $operation->error);
        $this->assertNull(collect($this->runner->commands)->first(fn ($c) => in_array('--clean', $c['command'], true)), 'nothing was overwritten');
    }

    public function test_restore_refuses_corrupted_file(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid]);
        $backup = Backup::query()->firstOrFail();
        File::put(config('privatecloud.backups.local_path').'/'.$backup->path, 'tampered');

        $response = $this->withConfirmedPassword()->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'shop']);

        $this->assertStringContainsString('checksum mismatch', Operation::query()->where('uuid', $response->json('data.id'))->first()->error);
    }

    public function test_cannot_start_two_backups_of_the_same_database(): void
    {
        $this->actingAsAdmin();
        Bus::fake();
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid])->assertStatus(202);
        $this->postJson('/api/v1/backups', ['database' => $this->database->uuid])->assertStatus(422);
    }

    public function test_summary_warns_about_local_only_storage(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/backups/summary')->assertOk()->assertJsonPath('off_server', false)
            ->assertJsonPath('warning', fn ($w) => str_contains($w, 'do not protect you if the server itself is lost'));
    }

    public function test_scheduled_backups_respect_retention(): void
    {
        $project = $this->database->project;
        $project->update(['backup_schedule' => 'daily', 'backup_time' => '00:00', 'backup_retention' => 2]);
        foreach (range(1, 3) as $i) {
            $this->travel(1)->days();
            $this->artisan('privatecloud:scheduled-backups')->assertSuccessful();
        }

        $this->assertSame(2, Backup::query()->where('trigger', 'scheduled')->count());
        $this->assertCount(2, File::allFiles(config('privatecloud.backups.local_path')));
    }

    public function test_local_storage_rejects_path_traversal(): void
    {
        $storage = new LocalBackupStorage(config('privatecloud.backups.local_path'));
        foreach (['../etc/passwd', '/etc/passwd', 'a/../../b', "a\0b", 'a b'] as $key) {
            try {
                $storage->localPath($key);
                $this->fail("Accepted {$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
