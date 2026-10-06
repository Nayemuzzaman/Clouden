<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Models\Server;
use App\Services\Audit\AuditLogger;
use App\Services\Monitoring\HostMetrics;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Monitoring\ServiceHealth;
use App\Services\Server\ServerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ServerController extends Controller
{
    public function metrics(HostMetrics $host, ServerIdentity $identity): JsonResponse
    {
        return response()->json([
            ...$host->snapshot(),
            'public_ipv4' => $identity->publicIpv4(),
            'thresholds' => MetricsCollector::thresholds(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $since = ProjectActionController::rangeStart((string) $request->query('range', '6h'));
        $points = Metric::query()->where('server_id', Server::local()->id)->whereNull('project_id')
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->get(['cpu_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes', 'load_1', 'net_rx_bytes', 'net_tx_bytes', 'recorded_at']);

        return response()->json(['points' => $points->map(fn (Metric $m) => [
            't' => $m->recorded_at->toIso8601String(),
            'cpu' => $m->cpu_percent,
            'memory' => $m->memory_used_bytes,
            'memory_total' => $m->memory_total_bytes,
            'disk' => $m->disk_used_bytes,
            'disk_total' => $m->disk_total_bytes,
            'load' => $m->load_1,
            'rx' => $m->net_rx_bytes,
            'tx' => $m->net_tx_bytes,
        ])]);
    }

    public function services(ServiceHealth $health): JsonResponse
    {
        return response()->json(['data' => $health->check()]);
    }

    public function cleanup(AuditLogger $audit): JsonResponse
    {
        Artisan::queue('privatecloud:cleanup');
        $audit->log('server.cleanup');

        return response()->json(['message' => 'Cleanup started. Old build cache and unused images are being removed in the background.'], 202);
    }
}
