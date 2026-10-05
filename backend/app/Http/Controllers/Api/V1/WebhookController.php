<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\WebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function github(Request $request, string $uuid, WebhookHandler $handler): JsonResponse
    {
        $project = Project::query()->where('uuid', $uuid)->whereNull('deleting_at')->first();
        if (! $project || strlen($request->getContent()) > 5 * 1024 * 1024) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $headers = [];
        foreach (['x-hub-signature-256', 'x-github-event', 'x-github-delivery'] as $name) {
            $headers[$name] = $request->header($name);
        }
        $result = $handler->handle($project, $request->getContent(), $headers);

        return response()->json($result['body'], $result['status']);
    }
}
