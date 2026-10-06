<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseResource;
use App\Models\Project;
use App\Models\ProjectDatabase;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\Identifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DatabaseController extends Controller
{
    public function __construct(
        private readonly DatabaseService $databases,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $databases = ProjectDatabase::query()->with(['project', 'backups' => fn ($q) => $q->latest()->limit(1)])->orderBy('name')->get();
        foreach ($databases as $database) {
            if ($database->status === 'ready') {
                $this->databases->refreshSize($database);
            }
        }

        return DatabaseResource::collection($databases);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'project' => ['nullable', 'string', 'exists:projects,slug'],
        ]);
        $project = isset($data['project']) ? Project::query()->where('slug', $data['project'])->first() : null;
        $database = $this->databases->create($data['name'], $project);

        return (new DatabaseResource($database))->response()->setStatusCode(201);
    }

    /** Create the database for a project with a name derived from the project. */
    public function storeForProject(Request $request, Project $project): JsonResponse
    {
        $name = $request->input('name') ?: Identifier::fromSlug($project->slug);
        $database = $this->databases->create((string) $name, $project);

        return (new DatabaseResource($database))
            ->additional(['message' => 'Database created and connection variables (DB_HOST, DB_DATABASE, DATABASE_URL, ...) were added to the project. Redeploy to apply them.'])
            ->response()->setStatusCode(201);
    }

    public function show(ProjectDatabase $database): DatabaseResource
    {
        if ($database->status === 'ready') {
            $this->databases->refreshSize($database);
        }

        return new DatabaseResource($database->load(['project', 'backups' => fn ($q) => $q->latest()->limit(1)]));
    }

    public function reveal(ProjectDatabase $database): JsonResponse
    {
        $this->audit->log('database.credentials_revealed', $database);

        return response()->json([
            'password' => $database->password,
            'connection_string' => $database->connectionUrl(true),
        ]);
    }

    public function resetCredentials(ProjectDatabase $database): JsonResponse
    {
        $database = $this->databases->resetCredentials($database);

        return response()->json([
            'message' => $database->project
                ? 'Password changed and the project\'s DB_PASSWORD / DATABASE_URL were updated. Redeploy the project so it uses the new password.'
                : 'Password changed.',
        ]);
    }

    public function retry(ProjectDatabase $database): DatabaseResource
    {
        return new DatabaseResource($this->databases->retry($database));
    }

    public function destroy(Request $request, ProjectDatabase $database): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if ($request->input('confirm') !== $database->name) {
            throw ValidationException::withMessages(['confirm' => 'Type the database name exactly to confirm deletion.']);
        }
        $this->databases->delete($database);

        return response()->json(null, 204);
    }
}
