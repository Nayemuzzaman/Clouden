<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Logs\LogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogController extends Controller
{
    public function __construct(private readonly LogReader $reader) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:app,proxy'],
            'tail' => ['nullable', 'integer', 'min:1', 'max:'.config('privatecloud.logs.max_lines')],
            'since' => ['nullable', 'regex:/^\d{10}(\.\d{1,9})?$/'],
        ]);

        return response()->json($this->read($project, $data['type'] ?? 'app', (int) ($data['tail'] ?? 300), $data['since'] ?? null));
    }

    public function download(Request $request, Project $project): StreamedResponse
    {
        $type = $request->query('type') === 'proxy' ? 'proxy' : 'app';
        $result = $this->read($project, $type, (int) config('privatecloud.logs.download_max_lines'), null);
        $filename = $project->slug.'-'.$type.'-'.now()->format('Ymd-His').'.log';

        return response()->streamDownload(function () use ($result) {
            foreach ($result['lines'] as $line) {
                echo ($line['timestamp'] ?? '').' '.$line['message']."\n";
            }
        }, $filename, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return array{lines: list<array<string, mixed>>, message: ?string, container: ?string} */
    private function read(Project $project, string $type, int $tail, ?string $since): array
    {
        if ($type === 'proxy') {
            return ['lines' => $this->reader->proxyLogs($project, $tail), 'message' => null, 'container' => null];
        }
        $container = $project->currentDeployment?->container_name;
        if (! $container) {
            return ['lines' => [], 'message' => 'The application has not been deployed yet.', 'container' => null];
        }
        try {
            return ['lines' => $this->reader->containerLogs($project, $container, $tail, $since), 'message' => null, 'container' => $container];
        } catch (Throwable $e) {
            return ['lines' => [], 'message' => 'Logs are not available: '.$e->getMessage(), 'container' => $container];
        }
    }
}
