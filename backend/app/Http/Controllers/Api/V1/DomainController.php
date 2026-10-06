<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\Project;
use App\Services\Domains\DomainService;
use App\Services\Server\ServerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DomainController extends Controller
{
    public function __construct(private readonly DomainService $domains) {}

    public function all(): AnonymousResourceCollection
    {
        return DomainResource::collection(Domain::query()->with('project')->orderBy('hostname')->get());
    }

    public function index(Project $project, ServerIdentity $identity): JsonResponse
    {
        return response()->json([
            'data' => DomainResource::collection($project->domains()->orderByDesc('is_primary')->orderBy('hostname')->get()),
            'server_ip' => $identity->publicIpv4(),
            'https_enabled' => $this->domains->httpsEnabled(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $request->validate(['hostname' => ['required', 'string', 'max:253']]);
        $domain = $this->domains->add($project, (string) $request->input('hostname'));

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function destroy(Project $project, Domain $domain): JsonResponse
    {
        abort_unless($domain->project_id === $project->id, 404);
        $this->domains->remove($domain);

        return response()->json(null, 204);
    }

    public function check(Project $project, Domain $domain): DomainResource
    {
        abort_unless($domain->project_id === $project->id, 404);

        return new DomainResource($this->domains->check($domain));
    }

    public function primary(Project $project, Domain $domain): DomainResource
    {
        abort_unless($domain->project_id === $project->id, 404);
        $this->domains->makePrimary($domain);

        return new DomainResource($domain->fresh());
    }
}
