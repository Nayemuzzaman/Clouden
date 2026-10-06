<?php

namespace App\Services\Backups;

use App\Enums\JobStatus;
use App\Models\Backup;
use App\Models\Operation;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\PostgresProvisioner;
use App\Services\Docker\DockerClient;
use App\Services\Docker\HelperContainer;
use App\Services\Notifier;
use App\Services\Process\CommandRunner;
use RuntimeException;
use Throwable;

/**
 * Restores a verified backup. Before anything is overwritten, a fresh
 * "pre_restore" safety backup of the current state is taken; if that safety
 * backup fails the restore does not start.
 */
class BackupRestorer
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly HelperContainer $helper,
        private readonly DockerClient $docker,
        private readonly BackupStorage $storage,
        private readonly BackupService $backups,
        private readonly BackupRunner $backupRunner,
        private readonly PostgresProvisioner $provisioner,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function run(Operation $operation): void
    {
        /** @var Backup|null $backup */
        $backup = $operation->target;
        if ($backup === null) {
            $operation->markFailed('The backup no longer exists.');

            return;
        }
        $operation->markRunning('Verifying backup file');

        try {
            $path = $this->storage->localPath((string) $backup->path);
            if (! is_file($path)) {
                throw new RuntimeException('The backup file is missing from storage.');
            }
            if ($backup->checksum_sha256 && ! hash_equals($backup->checksum_sha256, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('The backup file is corrupted (checksum mismatch). Restore was not started.');
            }

            $operation->progress('Taking a safety backup of the current data');
            $safety = $backup->type === Backup::TYPE_DATABASE
                ? $this->backups->backupDatabase($backup->database, null, 'pre_restore', dispatch: false)
                : $this->backups->backupVolume($backup->volume, null, 'pre_restore', dispatch: false);
            $safety = $this->backupRunner->run($safety);
            if ($safety->status !== JobStatus::Success) {
                throw new RuntimeException('The safety backup of the current data failed, so nothing was restored: '.$safety->error);
            }
            $operation->update(['meta' => ['safety_backup' => $safety->uuid]]);

            $backup->type === Backup::TYPE_DATABASE ? $this->restoreDatabase($backup, $path, $operation) : $this->restoreVolume($backup, $path, $operation);

            $operation->markSucceeded('Restore completed. A safety backup of the previous data was kept.');
            $this->audit->log('backup.restored', $backup, metadata: ['type' => $backup->type], label: $backup->label, userId: $operation->initiated_by);
            $this->notifier->notify('backup.restored', 'Restore completed', "{$backup->label} was restored from the backup of ".$backup->created_at?->toDayDateTimeString().'.', 'success', '/backups');
        } catch (Throwable $e) {
            $operation->markFailed(mb_substr($e->getMessage(), 0, 2000));
            $this->audit->log('backup.restore_failed', $backup, 'failure', ['type' => $backup->type], $backup->label, $operation->initiated_by);
            $this->notifier->notify('backup.restore_failed', 'Restore failed', "{$backup->label}: ".mb_substr($e->getMessage(), 0, 200), 'error', '/backups');
        }
    }

    private function restoreDatabase(Backup $backup, string $path, Operation $operation): void
    {
        $database = $backup->database;
        $config = config('privatecloud.apps_db');
        $operation->progress('Closing open connections');
        $this->provisioner->terminateConnections($database->name);

        $operation->progress('Restoring database');
        $result = $this->runner->run([
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error',
            '--role', $database->username,
            '--host', (string) $config['host'], '--port', (string) $database->port,
            '--username', (string) $config['admin_username'], '--dbname', $database->name,
            $path,
        ], env: ['PGPASSWORD' => (string) $config['admin_password'], 'PGCONNECT_TIMEOUT' => '10'], timeout: (int) config('privatecloud.backups.timeout'));

        if (! $result->successful()) {
            throw new RuntimeException('pg_restore failed and the database was left unchanged (the restore runs in a single transaction): '.mb_substr(trim($result->errorOutput), -800));
        }
    }

    private function restoreVolume(Backup $backup, string $path, Operation $operation): void
    {
        $volume = $backup->volume;
        $project = $volume->project;
        $container = $project?->currentDeployment?->container_name;
        $wasRunning = false;

        if ($container) {
            $info = $this->docker->inspectContainer($container);
            $wasRunning = (bool) ($info['State']['Running'] ?? false);
            if ($wasRunning) {
                $operation->progress('Stopping the application while files are restored');
                $this->docker->stopContainer($container, 15);
            }
        }

        try {
            $operation->progress('Restoring files');
            // "$1" is a positional parameter: the file name is never interpolated into the script.
            $result = $this->helper->run(
                ['sh', '-c', 'find /target -mindepth 1 -delete && tar -xzf "/backup/$1" -C /target', 'restore', basename($path)],
                [
                    ['Type' => 'volume', 'Source' => $volume->docker_name, 'Target' => '/target'],
                    ['Type' => 'bind', 'Source' => dirname($path), 'Target' => '/backup', 'ReadOnly' => true],
                ],
                (int) config('privatecloud.backups.timeout'),
            );
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException('Restoring the files failed: '.mb_substr($result['output'], -800).' A safety backup of the previous data was taken before the restore.');
            }
        } finally {
            if ($container && $wasRunning) {
                $operation->progress('Starting the application');
                $this->docker->startContainer($container);
            }
        }
    }
}
