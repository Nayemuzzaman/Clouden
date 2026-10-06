<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Models\Project;
use App\Models\WebhookEvent;
use App\Services\Deployment\FrameworkDetector;
use App\Services\Monitoring\ContainerStats;
use App\Services\Projects\AutoDeployService;
use App\Services\Projects\ProductionReconciler;
use App\Services\Projects\ProjectRuntime;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SyncStatus;
use App\Services\Source\GitHubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProjectActionController extends Controller
{
    public function __construct(private readonly ProjectRuntime $runtime) {}

    public function start(Project $project): JsonResponse
    {
        $this->runtime->start($project);

        return response()->json(['status' => $project->fresh()->status->value]);
    }

    public function stop(Project $project): JsonResponse
    {
        $this->runtime->stop($project);

        return response()->json(['status' => $project->fresh()->status->value]);
    }

    public function restart(Project $project): JsonResponse
    {
        $this->runtime->restart($project);

        return response()->json(['status' => $project->fresh()->status->value]);
    }

    public function container(Project $project, ContainerStats $stats): JsonResponse
    {
        $container = $this->runtime->inspect($project->load('currentDeployment'));
        $usage = $container && $container['running'] ? $stats->forContainer($container['name']) : null;

        return response()->json([
            'container' => $container,
            'usage' => $usage,
            'limits' => ['memory_bytes' => $project->memory_limit_mb * 1024 * 1024, 'cpu' => $project->cpu_limit],
        ]);
    }

    public function metrics(Request $request, Project $project): JsonResponse
    {
        $since = self::rangeStart((string) $request->query('range', '6h'));

        return response()->json([
            'points' => Metric::query()->where('project_id', $project->id)->where('recorded_at', '>=', $since)->orderBy('recorded_at')
                ->get(['cpu_percent', 'memory_used_bytes', 'memory_total_bytes', 'net_rx_bytes', 'net_tx_bytes', 'recorded_at'])
                ->map(fn (Metric $m) => [
                    't' => $m->recorded_at->toIso8601String(),
                    'cpu' => $m->cpu_percent,
                    'memory' => $m->memory_used_bytes,
                    'memory_limit' => $m->memory_total_bytes,
                    'rx' => $m->net_rx_bytes,
                    'tx' => $m->net_tx_bytes,
                ]),
        ]);
    }

    public function refreshCommit(Project $project, ProjectService $projects): JsonResponse
    {
        $projects->refreshCommit($project);
        $repository = $project->repository->fresh();

        return response()->json(['sync' => SyncStatus::for($project->fresh(['repository', 'currentDeployment', 'latestDeployment'])), 'latest_commit' => [
            'sha' => $repository->latest_commit_sha,
            'short_sha' => substr((string) $repository->latest_commit_sha, 0, 7),
            'message' => $repository->latest_commit_message,
            'author' => $repository->latest_commit_author,
            'committed_at' => $repository->latest_commit_at?->toIso8601String(),
        ]]);
    }

    public function detect(Project $project): JsonResponse
    {
        $repository = $project->repository;
        if (! $repository || $repository->provider !== Project::SOURCE_GITHUB) {
            return response()->json(['framework' => null, 'has_dockerfile' => null, 'message' => 'Detection is available for GitHub repositories.']);
        }
        $files = GitHubClient::forConnection()->rootFiles((string) $repository->full_name, $repository->branch);

        return response()->json(FrameworkDetector::detect($files));
    }

    public function autoDeploy(Request $request, Project $project, AutoDeployService $autoDeploy): JsonResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $message = $request->boolean('enabled')
            ? $autoDeploy->enable($project)['error']
            : $autoDeploy->disable($project);

        return response()->json([
            'auto_deploy' => $project->fresh()->auto_deploy,
            'webhook_url' => $autoDeploy->webhookUrl($project),
            'webhook_installed' => (bool) $project->repository?->fresh()?->webhook_id,
            'message' => $message,
        ]);
    }

    public function webhook(Project $project, AutoDeployService $autoDeploy): JsonResponse
    {
        $repository = $project->repository;

        return response()->json([
            'url' => $autoDeploy->webhookUrl($project),
            'content_type' => 'application/json',
            'events' => ['push'],
            'installed' => (bool) $repository?->webhook_id,
            'status' => $repository?->webhook_status,
            'error' => $repository?->webhook_error,
            'last_delivery_at' => $repository?->webhook_last_delivery_at?->toIso8601String(),
            'recent_events' => WebhookEvent::query()->where('project_id', $project->id)->latest('id')->limit(10)
                ->get(['delivery_id', 'event', 'repository', 'ref', 'commit_sha', 'status', 'reason', 'deployment_id', 'created_at']),
        ]);
    }

    /** Re-check the GitHub webhook (and re-create it if it was deleted in GitHub). */
    public function checkWebhook(Project $project, AutoDeployService $autoDeploy): JsonResponse
    {
        if (! $project->auto_deploy) {
            return response()->json(['message' => 'Auto deploy is off for this project.'], 422);
        }
        $result = $autoDeploy->ensureWebhook($project);
        $repository = $project->repository?->fresh();

        return response()->json([
            'installed' => (bool) $repository?->webhook_id,
            'status' => $repository?->webhook_status,
            'message' => $result['error'] ?? 'The GitHub webhook is installed and points at this server.',
        ], $result['error'] ? 422 : 200);
    }

    /** What is live, what the branch head is, and any mismatch with Docker and routing. */
    public function production(Project $project, ProductionReconciler $reconciler): JsonResponse
    {
        $project->load(['repository', 'currentDeployment', 'latestDeployment', 'domains']);

        return response()->json([...$reconciler->inspect($project), 'sync' => SyncStatus::for($project)]);
    }

    public function revealWebhookSecret(Project $project): JsonResponse
    {
        return response()->json(['secret' => $project->webhook_secret]);
    }

    public function rotateWebhookSecret(Project $project): JsonResponse
    {
        $project->update(['webhook_secret' => Str::random(40)]);

        return response()->json(['message' => 'Webhook secret rotated. Update it in GitHub (or toggle auto deploy off and on to recreate the webhook).']);
    }

    public static function rangeStart(string $range): Carbon
    {
        return match ($range) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '3d' => now()->subDays(3),
            '7d' => now()->subDays(7),
            default => now()->subHours(6),
        };
    }
}
