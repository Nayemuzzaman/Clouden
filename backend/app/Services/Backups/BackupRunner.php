<?php

namespace App\Services\Backups;

use App\Enums\JobStatus;
use App\Models\Backup;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\PostgresProvisioner;
use App\Services\Docker\HelperContainer;
use App\Services\Notifier;
use App\Services\Process\CommandRunner;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Produces a backup file and only marks the backup successful after the file has
 * been verified (pg_restore --list for dumps, tar -t for archives) and
 * checksummed.
 */
class BackupRunner
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly HelperContainer $helper,
        private readonly BackupStorage $storage,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
        private readonly PostgresProvisioner $provisioner,
    ) {}

    public function run(Backup $backup): Backup
    {
        $backup->refresh();
        if ($backup->status !== JobStatus::Queued) {
            return $backup;
        }
        $backup->update(['status' => JobStatus::Running, 'started_at' => now(), 'storage' => $this->storage->name()]);

        $key = null;
        try {
            $this->ensureDiskSpace($backup);
            $key = $backup->type === Backup::TYPE_DATABASE ? $this->dumpDatabase($backup) : $this->archiveVolume($backup);
            $path = $this->storage->stagingPath($key);
            $this->verify($backup, $path);

            $size = (int) filesize($path);
            $checksum = hash_file('sha256', $path) ?: null;
            $this->storage->store($key);

            $backup->update([
                'status' => JobStatus::Success,
                'path' => $key,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'verified_at' => now(),
                'finished_at' => now(),
                'error' => null,
            ]);
            $this->audit->log('backup.succeeded', $backup, metadata: ['type' => $backup->type, 'trigger' => $backup->trigger], label: $backup->label, userId: $backup->initiated_by);
            if ($backup->trigger !== 'pre_restore') {
                $this->notifier->notify('backup.succeeded', 'Backup completed', "{$backup->label} backup completed (".self::humanSize($size).').', 'success', '/backups');
            }
        } catch (Throwable $e) {
            if ($key !== null) {
                try {
                    $this->storage->delete($key);
                } catch (Throwable) {
                    // partial file may not exist
                }
            }
            $backup->update(['status' => JobStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $this->audit->log('backup.failed', $backup, 'failure', ['type' => $backup->type], $backup->label, $backup->initiated_by);
            $this->notifier->notify('backup.failed', 'Backup failed', "{$backup->label}: ".mb_substr($e->getMessage(), 0, 200), 'error', '/backups');
        }

        return $backup->fresh();
    }

    private function dumpDatabase(Backup $backup): string
    {
        $database = $backup->database;
        if ($database === null) {
            throw new RuntimeException('The database no longer exists.');
        }
        $key = sprintf('databases/%s/%s-%s.dump', $database->name, now()->format('Ymd-His'), Str::substr($backup->uuid, 0, 8));
        $path = $this->storage->stagingPath($key);
        $config = config('privatecloud.apps_db');

        $result = $this->runner->run([
            'pg_dump', '--format=custom', '--compress=6', '--no-owner', '--no-privileges',
            '--host', (string) $config['host'], '--port', (string) $database->port,
            '--username', (string) $config['admin_username'], '--dbname', $database->name,
            '--file', $path,
        ], env: ['PGPASSWORD' => (string) $config['admin_password'], 'PGCONNECT_TIMEOUT' => '10'], timeout: (int) config('privatecloud.backups.timeout'));

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump failed: '.self::tail($result->errorOutput ?: $result->output));
        }

        return $key;
    }

    private function archiveVolume(Backup $backup): string
    {
        $volume = $backup->volume;
        if ($volume === null) {
            throw new RuntimeException('The volume no longer exists.');
        }
        $slug = $volume->project->slug;
        $file = sprintf('%s-%s.tar.gz', now()->format('Ymd-His'), Str::substr($backup->uuid, 0, 8));
        $key = sprintf('volumes/%s/%s/%s', $slug, $volume->name, $file);
        $path = $this->storage->stagingPath($key);

        $result = $this->helper->run(
            ['tar', '-czf', '/backup/'.$file, '-C', '/source', '.'],
            [
                ['Type' => 'volume', 'Source' => $volume->docker_name, 'Target' => '/source', 'ReadOnly' => true],
                ['Type' => 'bind', 'Source' => dirname($path), 'Target' => '/backup'],
            ],
            (int) config('privatecloud.backups.timeout'),
        );
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Archiving the volume failed: '.self::tail($result['output']));
        }

        return $key;
    }

    /**
     * Refuse to start when the backup would leave less than the configured free
     * space. The expected size is the current (uncompressed) size of the
     * database or volume, an upper bound for the compressed backup.
     */
    private function ensureDiskSpace(Backup $backup): void
    {
        $root = $this->storage->stagingPath('.disk-check');
        $free = @disk_free_space(dirname($root));
        if ($free === false) {
            return;
        }
        $expected = match ($backup->type) {
            Backup::TYPE_DATABASE => $backup->database ? ($this->provisioner->size($backup->database->name) ?? 0) : 0,
            default => (int) ($backup->volume->size_bytes ?? 0),
        };
        $required = (int) config('privatecloud.backups.min_free_disk_mb') * 1024 * 1024 + $expected;
        if ($free < $required) {
            throw new RuntimeException(sprintf(
                'Not enough free disk space for this backup (%s free, about %s needed). Delete old backups or copy them off the server, then try again.',
                self::humanSize((int) $free),
                self::humanSize($required),
            ));
        }
    }

    private function verify(Backup $backup, string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('The backup file is missing or empty.');
        }
        $command = $backup->type === Backup::TYPE_DATABASE ? ['pg_restore', '--list', $path] : ['tar', '-tzf', $path];
        $result = $this->runner->run($command, timeout: 600);
        if (! $result->successful()) {
            throw new RuntimeException('Backup verification failed: '.self::tail($result->errorOutput));
        }
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    private static function tail(string $text): string
    {
        return mb_substr(trim($text), -800);
    }
}
