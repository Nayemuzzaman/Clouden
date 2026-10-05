<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\VolumeResource;
use App\Models\Project;
use App\Models\Volume;
use App\Services\Audit\AuditLogger;
use App\Services\Deployment\ContainerLauncher;
use App\Services\Docker\DockerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class VolumeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function all(DockerClient $docker): AnonymousResourceCollection
    {
        $volumes = Volume::query()->with('project')->orderBy('docker_name')->get();
        $this->refreshSizes($docker, $volumes);

        return VolumeResource::collection($volumes);
    }

    public function index(Project $project, DockerClient $docker): AnonymousResourceCollection
    {
        $volumes = $project->volumes()->orderBy('name')->get();
        $this->refreshSizes($docker, $volumes);

        return VolumeResource::collection($volumes);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,30}$/'],
            'mount_path' => ['required', 'string', 'max:255'],
        ]);
        if ($error = StoreProjectRequest::mountPathError($data['mount_path'])) {
            throw ValidationException::withMessages(['mount_path' => $error]);
        }
        if ($project->volumes()->where('name', $data['name'])->orWhere(fn ($q) => $q->where('project_id', $project->id)->where('mount_path', $data['mount_path']))->exists()) {
            throw ValidationException::withMessages(['name' => 'A volume with this name or mount path already exists.']);
        }
        $volume = $project->volumes()->create([
            ...$data,
            'docker_name' => ContainerLauncher::volumeName($project, $data['name']),
        ]);
        $this->audit->log('volume.created', $volume, metadata: ['project' => $project->slug, 'mount_path' => $volume->mount_path]);

        return (new VolumeResource($volume))->additional(['message' => 'Volume added. Redeploy the project to mount it.'])->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Project $project, Volume $volume, DockerClient $docker): JsonResponse
    {
        abort_unless($volume->project_id === $project->id, 404);
        $request->validate(['delete_data' => ['boolean'], 'confirm' => ['required_if:delete_data,true', 'nullable', 'string']]);
        if ($request->boolean('delete_data')) {
            if ($request->input('confirm') !== $volume->name) {
                throw ValidationException::withMessages(['confirm' => 'Type the volume name to confirm deleting its data.']);
            }
            try {
                $docker->removeVolume($volume->docker_name);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['delete_data' => 'The volume is still in use by the running application. Remove it, redeploy, then delete the data. ('.$e->getMessage().')']);
            }
        }
        $volume->delete();
        $this->audit->log('volume.deleted', $project, metadata: ['volume' => $volume->name, 'data_deleted' => $request->boolean('delete_data')]);

        return response()->json(null, 204);
    }

    /** @param Collection<int, Volume> $volumes */
    private function refreshSizes(DockerClient $docker, $volumes): void
    {
        $stale = $volumes->filter(fn (Volume $v) => ! $v->size_checked_at || $v->size_checked_at->lt(now()->subMinutes(10)));
        if ($stale->isEmpty()) {
            return;
        }
        try {
            $df = $docker->systemDf('volume');
        } catch (Throwable) {
            return;
        }
        $sizes = [];
        foreach ($df['Volumes'] ?? [] as $v) {
            $sizes[$v['Name']] = $v['UsageData']['Size'] ?? null;
        }
        foreach ($stale as $volume) {
            if (array_key_exists($volume->docker_name, $sizes) && $sizes[$volume->docker_name] >= 0) {
                $volume->update(['size_bytes' => $sizes[$volume->docker_name], 'size_checked_at' => now()]);
            }
        }
    }
}
