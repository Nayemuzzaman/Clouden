<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Models\Project;
use App\Services\Docker\DockerClient;
use App\Services\Instance;
use Illuminate\Http\JsonResponse;
use Throwable;

/** Read-only overview of the containers on this server. */
class ContainerController extends Controller
{
    public function index(DockerClient $docker, Instance $instance): JsonResponse
    {
        try {
            $containers = $docker->listContainers();
        } catch (Throwable $e) {
            return response()->json(['data' => [], 'message' => 'Docker is not reachable: '.$e->getMessage()], 200);
        }

        $projects = Project::query()->get(['id', 'name', 'slug', 'current_deployment_id'])->keyBy('id');
        $usage = Metric::query()->whereNotNull('project_id')->where('recorded_at', '>', now()->subMinutes(3))->orderBy('recorded_at')->get()->keyBy('project_id');

        $rows = [];
        foreach ($containers as $c) {
            $labels = $c['Labels'] ?? [];
            if (($labels['privatecloud.helper'] ?? null) === 'true') {
                continue;
            }
            $projectId = isset($labels['privatecloud.project']) && $instance->owns($labels) ? (int) $labels['privatecloud.project'] : null;
            $project = $projectId ? $projects->get($projectId) : null;
            $isPlatform = str_starts_with(ltrim((string) ($c['Names'][0] ?? ''), '/'), 'privatecloud-');
            if (! $project && ! $isPlatform && ($labels['privatecloud.managed'] ?? null) !== 'true') {
                $kind = 'other';
            } else {
                $kind = $project ? 'project' : 'platform';
            }
            $metric = $project ? $usage->get($project->id) : null;

            $rows[] = [
                'id' => substr((string) $c['Id'], 0, 12),
                'name' => ltrim((string) ($c['Names'][0] ?? ''), '/'),
                'image' => $c['Image'] ?? null,
                'state' => $c['State'] ?? null,
                'status' => $c['Status'] ?? null,
                'created_at' => isset($c['Created']) ? date(DATE_ATOM, (int) $c['Created']) : null,
                'ports' => array_values(array_unique(array_map(fn ($p) => ($p['PublicPort'] ?? null) ? $p['PublicPort'].'→'.$p['PrivatePort'] : (string) $p['PrivatePort'], $c['Ports'] ?? []))),
                'kind' => $kind,
                'project' => $project ? ['name' => $project->name, 'slug' => $project->slug] : null,
                'cpu_percent' => $metric?->cpu_percent,
                'memory_used_bytes' => $metric?->memory_used_bytes,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['kind'] !== 'project', $a['name']] <=> [$b['kind'] !== 'project', $b['name']]);

        return response()->json(['data' => $rows]);
    }
}
