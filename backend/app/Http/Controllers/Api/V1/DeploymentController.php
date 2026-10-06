<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\DeploymentService;
use App\Services\Logs\LogReader;
use App\Services\Source\CommitInfo;
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

    /**
     * Deploy Latest (no body): read the head of the production branch now and
     * deploy exactly that commit; 409 "up_to_date" when production already runs
     * it (send force=true to rebuild it anyway). With commit_sha: deploy that
     * exact commit again (e.g. "Retry" after a failure).
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['commit_sha' => ['nullable', 'string', 'size:40'], 'force' => ['sometimes', 'boolean']]);
        $sha = $data['commit_sha'] ?? null;
        if ($sha !== null && ! GitRefs::isValidSha($sha)) {
            return response()->json(['message' => 'Invalid commit SHA.'], 422);
        }

        $result = $sha !== null && $project->source_type !== Project::SOURCE_IMAGE
            ? $this->deployments->deployCommit($project, $request->user(), 'manual', $this->knownCommit($project, $sha))
            : $this->deployments->deployLatest($project, $request->user(), (bool) ($data['force'] ?? false));

        return (new DeploymentResource($result->deployment->load('project')))
            ->additional(['meta' => [
                'reused' => $result->reused,
                'message' => $result->reused ? "Commit {$result->deployment->shortSha()} is already being deployed (deployment #{$result->deployment->number})." : null,
            ]])
            ->response()->setStatusCode(202);
    }

    /** Commit details already recorded for this SHA (the pipeline fills them in from GitHub otherwise). */
    private function knownCommit(Project $project, string $sha): CommitInfo
    {
        $previous = $project->deployments()->where('commit_sha', $sha)->whereNotNull('commit_message')->latest('number')->first();
        if ($previous === null && $project->repository?->latest_commit_sha === $sha) {
            return new CommitInfo($sha, $project->repository->latest_commit_message, $project->repository->latest_commit_author, $project->repository->latest_commit_at?->toIso8601String());
        }

        return new CommitInfo($sha, $previous?->commit_message, $previous?->commit_author, $previous?->commit_committed_at?->toIso8601String());
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
