<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\OperationResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\ProjectService;
use App\Services\Routing\CaddyConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    private const RELATIONS = ['repository', 'domains', 'currentDeployment.project', 'latestDeployment.project', 'latestDeployment.initiator', 'databases.backups', 'volumes'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(Project::query()->with(self::RELATIONS)->orderBy('name')->get());
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $result = $this->projects->create($request->validated(), $request->user());

        return (new ProjectResource($result['project']->load(self::RELATIONS)))
            ->additional(['warnings' => $result['warnings']])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->load(self::RELATIONS));
    }

    public function update(UpdateProjectRequest $request, Project $project, CaddyConfigurator $caddy): ProjectResource
    {
        $data = $request->validated();
        $repositoryFields = array_intersect_key($data, array_flip(['branch', 'repository', 'repository_url']));
        $projectFields = array_diff_key($data, $repositoryFields);

        if ($repositoryFields !== [] && $project->repository) {
            $updates = [];
            if (isset($repositoryFields['branch'])) {
                $updates['branch'] = $repositoryFields['branch'];
            }
            if (isset($repositoryFields['repository']) && $project->source_type === Project::SOURCE_GITHUB) {
                $updates['full_name'] = $repositoryFields['repository'];
                $updates['url'] = 'https://github.com/'.$repositoryFields['repository'].'.git';
            }
            if (isset($repositoryFields['repository_url']) && $project->source_type === Project::SOURCE_GIT) {
                $updates['url'] = $repositoryFields['repository_url'];
            }
            if ($updates !== []) {
                $project->repository->update([...$updates, 'latest_commit_sha' => null, 'latest_commit_message' => null, 'latest_commit_author' => null]);
            }
        }

        $portChanged = isset($projectFields['port']) && (int) $projectFields['port'] !== $project->port;
        $project->update($projectFields);
        if ($portChanged) {
            $caddy->sync(); // routes to container:port
        }
        $this->audit->log('project.updated', $project, metadata: ['fields' => array_keys($data)]);

        return new ProjectResource($project->fresh()->load(self::RELATIONS));
    }

    public function deletionImpact(Project $project): JsonResponse
    {
        return response()->json($this->projects->deletionImpact($project));
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'delete_databases' => ['boolean'],
            'delete_volumes' => ['boolean'],
            'delete_backups' => ['boolean'],
        ]);
        if ($data['confirm'] !== $project->name) {
            throw ValidationException::withMessages(['confirm' => 'Type the project name exactly to confirm deletion.']);
        }

        $operation = $this->projects->requestDeletion($project, [
            'delete_databases' => (bool) ($data['delete_databases'] ?? false),
            'delete_volumes' => (bool) ($data['delete_volumes'] ?? false),
            'delete_backups' => (bool) ($data['delete_backups'] ?? false),
        ], $request->user());

        return (new OperationResource($operation))->response()->setStatusCode(202);
    }
}
