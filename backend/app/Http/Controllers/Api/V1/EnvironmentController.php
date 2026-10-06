<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnvironmentVariableResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Deployment\ImageBuilder;
use App\Services\Environment\DotenvParser;
use App\Services\Environment\EnvironmentKey;
use App\Services\Environment\EnvironmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvironmentService $environment,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $variables = $project->environmentVariables()->orderBy('key')->get();
        $lastChange = $variables->max('updated_at');
        $deployedAt = $project->currentDeployment?->finished_at;

        return response()->json([
            'data' => EnvironmentVariableResource::collection($variables),
            // Docker fixes a container's environment at creation: changes need a redeploy.
            'pending_redeploy' => $deployedAt !== null && $lastChange !== null && $lastChange->gt($deployedAt),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $this->validated($request);
        if ($project->environmentVariables()->where('key', $data['key'])->exists()) {
            throw ValidationException::withMessages(['key' => 'This variable already exists. Edit it instead.']);
        }
        $this->ensureBuildArgAllowed($data['key'], (bool) ($data['available_at_build'] ?? false));
        $variable = $this->environment->set($project, $data['key'], (string) ($data['value'] ?? ''), $data['is_secret'] ?? null, false, $data['available_at_build'] ?? false);
        $this->audit->log('environment.created', $project, metadata: ['key' => $variable->key]);

        return (new EnvironmentVariableResource($variable))->response()->setStatusCode(201);
    }

    public function update(Request $request, Project $project, EnvironmentVariable $variable): EnvironmentVariableResource
    {
        $this->ensureBelongs($project, $variable);
        $data = $request->validate([
            'value' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'is_secret' => ['sometimes', 'boolean'],
            'available_at_build' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('value', $data)) {
            $variable->value = (string) ($data['value'] ?? '');
            $variable->is_system = false; // edited by hand: the platform will no longer overwrite it
        }
        if (array_key_exists('is_secret', $data)) {
            $variable->is_secret = $data['is_secret'];
        }
        if (array_key_exists('available_at_build', $data)) {
            $this->ensureBuildArgAllowed($variable->key, (bool) $data['available_at_build']);
            $variable->available_at_build = $data['available_at_build'];
        }
        $variable->save();
        $this->audit->log('environment.updated', $project, metadata: ['key' => $variable->key]);

        return new EnvironmentVariableResource($variable);
    }

    public function destroy(Project $project, EnvironmentVariable $variable): JsonResponse
    {
        $this->ensureBelongs($project, $variable);
        $variable->delete();
        $this->audit->log('environment.deleted', $project, metadata: ['key' => $variable->key]);

        return response()->json(null, 204);
    }

    public function reveal(Project $project, EnvironmentVariable $variable): JsonResponse
    {
        $this->ensureBelongs($project, $variable);
        $this->audit->log('environment.revealed', $project, metadata: ['key' => $variable->key]);

        return response()->json(['key' => $variable->key, 'value' => $variable->value]);
    }

    public function import(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:262144'],
            'overwrite' => ['boolean'],
        ]);
        $parsed = DotenvParser::parse($data['content']);
        if ($parsed['variables'] === [] && $parsed['errors'] !== []) {
            throw ValidationException::withMessages(['content' => $parsed['errors']]);
        }

        $created = $updated = $skipped = 0;
        foreach ($parsed['variables'] as $key => $value) {
            $exists = $project->environmentVariables()->where('key', $key)->exists();
            if ($exists && ! $request->boolean('overwrite')) {
                $skipped++;

                continue;
            }
            $this->environment->set($project, $key, $value);
            $exists ? $updated++ : $created++;
        }
        $this->audit->log('environment.imported', $project, metadata: ['keys' => array_keys($parsed['variables'])]);

        return response()->json(['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $parsed['errors']]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:65535'],
            'is_secret' => ['nullable', 'boolean'],
            'available_at_build' => ['nullable', 'boolean'],
        ]);
        if (! EnvironmentKey::isValid($data['key'])) {
            throw ValidationException::withMessages(['key' => 'Use letters, digits and underscores, starting with a letter or underscore.']);
        }

        return $data;
    }

    private function ensureBuildArgAllowed(string $key, bool $availableAtBuild): void
    {
        if ($availableAtBuild && ! ImageBuilder::isAllowedBuildArg($key)) {
            throw ValidationException::withMessages(['available_at_build' => "{$key} is reserved for the build tooling and cannot be passed to the build. It is still available to the running application."]);
        }
    }

    private function ensureBelongs(Project $project, EnvironmentVariable $variable): void
    {
        abort_unless($variable->project_id === $project->id, 404);
    }
}
