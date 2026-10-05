<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the platform database from growing without bound: build logs of old
 * deployments, webhook deliveries, read notifications, finished operations,
 * SQL history, removed-container records and old audit entries are deleted
 * after their retention period. Deployment records themselves are kept, and
 * the logs of each project's live deployment are never removed.
 */
class PruneHistory extends Command
{
    protected $signature = 'privatecloud:prune-history';

    protected $description = 'Delete old logs and history records from the platform database';

    public function handle(): int
    {
        $days = (int) config('privatecloud.retention.history_days');
        $cutoff = now()->subDays(max(7, $days));
        $live = Project::query()->whereNotNull('current_deployment_id')->pluck('current_deployment_id');

        $counts = [
            'deployment log lines' => DB::table('deployment_logs')
                ->whereIn('deployment_id', DB::table('deployments')->select('id')->where('finished_at', '<', $cutoff)->whereNotIn('id', $live))
                ->delete(),
            // Kept as long as the audit log: their payload hashes block replayed deliveries.
            'webhook deliveries' => DB::table('webhook_events')->where('created_at', '<', now()->subDays(max(30, (int) config('privatecloud.retention.audit_days'))))->delete(),
            'notifications' => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', $cutoff)->delete(),
            'operations' => DB::table('operations')->whereNotNull('finished_at')->where('finished_at', '<', $cutoff)->delete(),
            'SQL history entries' => DB::table('sql_query_history')->where('created_at', '<', $cutoff)->delete(),
            'removed container records' => DB::table('containers')->whereNotNull('removed_at')->where('removed_at', '<', $cutoff)->delete(),
            'audit entries' => DB::table('audit_logs')->where('created_at', '<', now()->subDays(max(30, (int) config('privatecloud.retention.audit_days'))))->delete(),
        ];
        foreach ($counts as $what => $count) {
            $this->line("Deleted {$count} {$what}.");
        }

        return self::SUCCESS;
    }
}
