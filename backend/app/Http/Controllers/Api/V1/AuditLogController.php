<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('user')->latest('id');
        if ($action = $request->query('action')) {
            $query->where('action', 'like', addcslashes((string) $action, '%_\\').'%');
        }
        if (in_array($request->query('result'), ['success', 'failure'], true)) {
            $query->where('result', $request->query('result'));
        }

        return AuditLogResource::collection($query->paginate(max(1, min(100, (int) $request->query('per_page', 50)))));
    }
}
