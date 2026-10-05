<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Http\Resources\OperationResource;
use App\Models\Backup;
use App\Models\Project;
use App\Models\ProjectDatabase;
use App\Models\Volume;
use App\Services\Audit\AuditLogger;
use App\Services\Backups\BackupService;
use App\Services\Backups\BackupStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Backup::query()->with(['project', 'database', 'volume'])->latest('id');
        if ($slug = $request->query('project')) {
            $query->whereHas('project', fn ($q) => $q->where('slug', $slug));
        }
        if (in_array($request->query('type'), [Backup::TYPE_DATABASE, Backup::TYPE_VOLUME], true)) {
            $query->where('type', $request->query('type'));
        }
        if (in_array($request->query('status'), ['queued', 'running', 'success', 'failed'], true)) {
            $query->where('status', $request->query('status'));
        }

        return BackupResource::collection($query->paginate(max(1, min(100, (int) $request->query('per_page', 25)))));
    }

    public function summary(BackupStorage $storage): JsonResponse
    {
        $last = fn (string $type) => Backup::query()->where('type', $type)->where('status', JobStatus::Success->value)->latest('finished_at')->value('finished_at');

        return response()->json([
            'last_database_backup' => $last(Backup::TYPE_DATABASE),
            'last_volume_backup' => $last(Backup::TYPE_VOLUME),
            'total_size_bytes' => (int) Backup::query()->where('status', JobStatus::Success->value)->sum('size_bytes'),
            'count' => Backup::query()->where('status', JobStatus::Success->value)->count(),
            'running' => Backup::query()->whereIn('status', ['queued', 'running'])->count(),
            'storage' => $storage->name(),
            'off_server' => $storage->isOffServer(),
            'warning' => $storage->isOffServer() ? null : 'Backups are stored on this server only. They do not protect you if the server itself is lost. Copy them off the server regularly (see docs/backups.md).',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'database' => ['nullable', 'string', 'exists:project_databases,uuid'],
            'volume_id' => ['nullable', 'integer', 'exists:volumes,id'],
            'project' => ['nullable', 'string', 'exists:projects,slug'],
        ]);
        $user = $request->user();

        $created = match (true) {
            ! empty($data['database']) => [$this->backups->backupDatabase(ProjectDatabase::query()->where('uuid', $data['database'])->firstOrFail(), $user)],
            ! empty($data['volume_id']) => [$this->backups->backupVolume(Volume::query()->findOrFail($data['volume_id']), $user)],
            ! empty($data['project']) => $this->backups->backupProject(Project::query()->where('slug', $data['project'])->firstOrFail(), $user),
            default => throw ValidationException::withMessages(['database' => 'Choose what to back up.']),
        };
        if ($created === []) {
            throw ValidationException::withMessages(['project' => 'This project has no database or volume to back up.']);
        }

        return response()->json(['data' => BackupResource::collection(collect($created))], 202);
    }

    public function show(Backup $backup): BackupResource
    {
        return new BackupResource($backup->load(['project', 'database', 'volume']));
    }

    public function destroy(Backup $backup): JsonResponse
    {
        $this->backups->delete($backup);

        return response()->json(null, 204);
    }

    public function restore(Request $request, Backup $backup): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        $expected = $backup->database->name ?? $backup->volume->name;
        if ($expected === null || $request->input('confirm') !== $expected) {
            throw ValidationException::withMessages(['confirm' => 'Type the '.($backup->type === Backup::TYPE_DATABASE ? 'database' : 'volume').' name exactly to confirm the restore.']);
        }

        return (new OperationResource($this->backups->restore($backup, $request->user())))->response()->setStatusCode(202);
    }

    public function download(Backup $backup, BackupStorage $storage, AuditLogger $audit): BinaryFileResponse
    {
        abort_unless($backup->status === JobStatus::Success && $backup->path, 404);
        $path = $storage->localPath($backup->path);
        abort_unless(is_file($path), 404, 'The backup file is missing.');
        $audit->log('backup.downloaded', $backup, label: $backup->label);

        return response()->download($path, basename($path));
    }
}
