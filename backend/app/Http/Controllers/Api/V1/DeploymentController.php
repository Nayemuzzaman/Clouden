<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\DeploymentService;
use App\Services\Logs\LogReader;
use App\Services\Source\GitRefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class DeploymentController extends Controller
{
    public function __construct(private readonly DeploymentService $deployments) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        return DeploymentResource::collection(
            $project->deployments()->with(['project', 'initiator', 'rollbackOf'])->orderByDesc('number')->paginate($perPage)
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['commit_sha' => ['nullable', 'string', 'size:40']]);
        if (! empty($data['commit_sha']) && ! GitRefs::isValidSha($data['commit_sha'])) {
            return response()->json(['message' => 'Invalid commit SHA.'], 422);
        }
        $deployment = $this->deployments->deploy($project, $request->user(), 'manual', $data['commit_sha'] ?? null);

        return (new DeploymentResource($deployment->load('project')))->response()->setStatusCode(202);
    }

    public function redeploy(Request $request, Project $project): JsonResponse
    {
        $deployment = $this->deployments->redeploy($project, $request->user());

        return (new DeploymentResource($deployment->load('project')))->response()->setStatusCode(202);
    }

    public function show(Project $project, Deployment $deployment): DeploymentResource
    {
        return new DeploymentResource($deployment->load(['project', 'initiator', 'rollbackOf']));
    }

    /** Incremental log polling: pass the last seen id as after_id. */
    public function logs(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        $after = (int) $request->query('after_id', 0);
        $limit = max(1, min(2000, (int) $request->query('limit', 1000)));
        $lines = $deployment->logs()->where('id', '>', $after)->orderBy('id')->limit($limit)
            ->get(['id', 'stream', 'level', 'line', 'logged_at']);

        return response()->json([
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'stream' => $l->stream, 'level' => $l->level, 'message' => $l->line,
                'timestamp' => $l->logged_at?->toIso8601ZuluString('millisecond'),
            ]),
            'status' => $deployment->fresh()->status->value,
            'has_more' => $lines->count() === $limit,
        ]);
    }

    public function containerLogs(Request $request, Project $project, Deployment $deployment, LogReader $reader): JsonResponse
    {
        if (! $deployment->container_name) {
            return response()->json(['lines' => [], 'message' => 'This deployment did not start a container.']);
        }
        try {
            $lines = $reader->containerLogs($project, $deployment->container_name, (int) $request->query('tail', 500));
        } catch (Throwable) {
            return response()->json(['lines' => [], 'message' => 'The container of this deployment has been removed, so its logs are no longer available.']);
        }

        return response()->json(['lines' => $lines]);
    }

    public function rollback(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        $rollback = $this->deployments->rollback($project, $deployment, $request->user());

        return (new DeploymentResource($rollback->load('project')))->response()->setStatusCode(202);
    }

    public function cancel(Project $project, Deployment $deployment): JsonResponse
    {
        if (! $this->deployments->cancel($deployment)) {
            return response()->json(['message' => 'This deployment has already finished.'], 409);
        }

        return response()->json(['message' => $deployment->fresh()->status->value === 'cancelled'
            ? 'Deployment cancelled.'
            : 'Cancellation requested. The deployment stops at the next safe point; the live version is not affected.']);
    }
}
