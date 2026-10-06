<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Projects\ProductionReconciler;
use App\Services\Projects\SyncStatus;
use Illuminate\Console\Command;

/**
 * Shows, per project, the production branch head, the recorded production
 * deployment, the running container and the Caddy route, and every mismatch
 * between them. Read-only; privatecloud:reconcile applies the safe repair.
 */
class ProductionStatus extends Command
{
    protected $signature = 'privatecloud:production-status {project? : project slug} {--json : machine-readable output}';

    protected $description = 'Compare branch head, recorded production, running container and routing';

    public function handle(ProductionReconciler $reconciler): int
    {
        $query = Project::query()->whereNull('deleting_at')->orderBy('slug');
        if ($slug = $this->argument('project')) {
            $query->where('slug', $slug);
        }
        $reports = [];
        foreach ($query->with(['repository', 'currentDeployment', 'latestDeployment', 'domains'])->get() as $project) {
            $reports[] = [...$reconciler->inspect($project), 'sync' => SyncStatus::for($project)];
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($reports === []) {
            $this->warn('No matching project.');

            return self::FAILURE;
        }
        $problems = 0;
        foreach ($reports as $r) {
            $this->line(sprintf('<options=bold>%s</>  sync: %s  production: %s  branch head: %s  route: %s',
                $r['project'], $r['sync']['state'] ?? 'n/a', substr((string) $r['production']['sha'], 0, 7) ?: '-',
                substr((string) $r['desired_sha'], 0, 7) ?: '-', $r['route']['actual'] ?? '-'));
            foreach ($r['findings'] as $f) {
                $this->line("  [{$f['severity']}] {$f['code']}: {$f['message']}");
                $problems += $f['severity'] === 'error' ? 1 : 0;
            }
        }

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
