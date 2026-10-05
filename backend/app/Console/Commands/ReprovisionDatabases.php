<?php

namespace App\Console\Commands;

use App\Models\ProjectDatabase;
use App\Services\Databases\PostgresProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-creates the role and database of every recorded application database on the
 * applications PostgreSQL server. Idempotent: existing roles get their stored
 * password back, existing databases are left as they are. Used after restoring the
 * platform database onto a new server (see docs/backups.md).
 */
class ReprovisionDatabases extends Command
{
    protected $signature = 'privatecloud:reprovision-databases';

    protected $description = 'Re-create application database roles and databases from the platform records';

    public function handle(PostgresProvisioner $provisioner): int
    {
        $failed = 0;
        foreach (ProjectDatabase::query()->orderBy('name')->get() as $database) {
            try {
                $existed = $provisioner->exists($database->name);
                $provisioner->create($database->name, $database->username, $database->password);
                $database->update(['status' => 'ready', 'last_error' => null]);
                $this->info(sprintf('%-30s %s', $database->name, $existed ? 'ok (already existed, password re-applied)' : 'created (empty — restore a backup into it)'));
            } catch (Throwable $e) {
                $failed++;
                $database->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
                $this->error(sprintf('%-30s %s', $database->name, $e->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
