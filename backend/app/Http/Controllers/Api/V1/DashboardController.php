<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Backup;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Metric;
use App\Models\Project;
use App\Services\Monitoring\HostMetrics;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Monitoring\ServiceHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(HostMetrics $host, ServiceHealth $services): JsonResponse
    {
        $projects = Project::query()->with(['domains', 'currentDeployment', 'latestDeployment', 'repository'])->orderBy('name')->get();
        $usage = $this->latestProjectUsage();

        return response()->json([
            'name' => config('privatecloud.name'),
            'server' => $host->snapshot(),
            'thresholds' => MetricsCollector::thresholds(),
            'services' => $services->check(),
            'projects' => $projects->map(fn (Project $p) => [
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status->value,
                'domain' => $p->primaryDomain()?->hostname,
                'cpu_percent' => $usage[$p->id]['cpu_percent'] ?? null,
                'memory_used_bytes' => $usage[$p->id]['memory_used_bytes'] ?? null,
                'memory_limit_mb' => $p->memory_limit_mb,
                'repository' => $p->repository->full_name ?? $p->repository->url ?? $p->image,
                'latest_deployment' => $p->latestDeployment ? [
                    'number' => $p->latestDeployment->number,
                    'status' => $p->latestDeployment->status->value,
                    'commit' => $p->latestDeployment->shortSha(),
                    'finished_at' => $p->latestDeployment->finished_at?->toIso8601String(),
                    'created_at' => $p->latestDeployment->created_at?->toIso8601String(),
                ] : null,
            ]),
            'recent_deployments' => Deployment::query()->with(['project', 'initiator', 'rollbackOf'])->latest('id')->limit(8)->get()
                ->map(fn (Deployment $d) => [...(new DeploymentResource($d))->resolve(), 'project' => ['name' => $d->project->name, 'slug' => $d->project->slug]]),
            'backups' => [
                'last_success' => Backup::query()->where('status', JobStatus::Success->value)->latest('finished_at')->value('finished_at'),
                'failed_last_24h' => Backup::query()->where('status', JobStatus::Failed->value)->where('created_at', '>', now()->subDay())->count(),
            ],
            'domains' => [
                'total' => Domain::query()->count(),
                'problems' => Domain::query()->whereIn('cert_status', ['failed'])->orWhereIn('dns_status', ['missing', 'mismatch'])->count(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 1) {
            return response()->json(['projects' => [], 'domains' => [], 'deployments' => []]);
        }
        $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';

        $deployments = Deployment::query()->with('project')
            ->when(ctype_digit(ltrim($q, '#')), fn ($query) => $query->where('number', (int) ltrim($q, '#')),
                fn ($query) => $query->where(fn ($w) => $w->whereRaw('lower(commit_sha) like ?', [$like])->orWhereRaw('lower(commit_message) like ?', [$like])))
            ->latest('id')->limit(8)->get();

        return response()->json([
            'projects' => Project::query()->whereRaw('lower(name) like ?', [$like])->orWhereRaw('slug like ?', [$like])->limit(8)->get(['name', 'slug', 'status']),
            'domains' => Domain::query()->with('project:id,name,slug')->whereRaw('hostname like ?', [$like])->limit(8)->get()
                ->map(fn (Domain $d) => ['hostname' => $d->hostname, 'project' => ['name' => $d->project->name, 'slug' => $d->project->slug]]),
            'deployments' => $deployments->map(fn (Deployment $d) => [
                'id' => $d->id, 'number' => $d->number, 'status' => $d->status->value, 'commit' => $d->shortSha(),
                'message' => $d->commit_message, 'project' => ['name' => $d->project->name, 'slug' => $d->project->slug],
            ]),
        ]);
    }

    /** @return array<int, array{cpu_percent: float|null, memory_used_bytes: int|null}> */
    private function latestProjectUsage(): array
    {
        $rows = Metric::query()->whereNotNull('project_id')->where('recorded_at', '>', now()->subMinutes(3))
            ->orderBy('recorded_at')->get(['project_id', 'cpu_percent', 'memory_used_bytes']);
        $usage = [];
        foreach ($rows as $row) {
            $usage[$row->project_id] = ['cpu_percent' => $row->cpu_percent, 'memory_used_bytes' => $row->memory_used_bytes];
        }

        return $usage;
    }
}
