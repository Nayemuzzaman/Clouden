<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProjectDatabase;
use App\Models\SqlQuery;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\DestructiveQueryDetector;
use App\Services\Databases\SqlRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SqlController extends Controller
{
    public function run(Request $request, ProjectDatabase $database, SqlRunner $runner, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'sql' => ['required', 'string', 'max:200000'],
            'confirm_destructive' => ['boolean'],
        ]);
        abort_unless($database->status === 'ready', 409, 'The database is not ready.');

        $warnings = DestructiveQueryDetector::analyze($data['sql']);
        if ($warnings !== [] && ! $request->boolean('confirm_destructive')) {
            return response()->json([
                'message' => 'This query looks destructive. Confirm to run it.',
                'code' => 'destructive_confirmation_required',
                'warnings' => $warnings,
            ], 409);
        }

        $result = $runner->run($database, $data['sql']);
        SqlQuery::query()->create([
            'project_database_id' => $database->id,
            'user_id' => $request->user()->id,
            'sql' => mb_substr($data['sql'], 0, 20000),
            'success' => $result['success'],
            'duration_ms' => $result['duration_ms'],
            'row_count' => $result['row_count'] ?? $result['affected_rows'],
            'error' => $result['error'] ? mb_substr($result['error'], 0, 2000) : null,
        ]);
        if ($warnings !== []) {
            $audit->log('database.destructive_sql', $database, $result['success'] ? 'success' : 'failure', ['warnings' => $warnings]);
        }

        return response()->json($result + ['warnings' => $warnings]);
    }

    public function history(ProjectDatabase $database): JsonResponse
    {
        return response()->json([
            'data' => SqlQuery::query()->where('project_database_id', $database->id)->latest('id')->limit(50)
                ->get(['id', 'sql', 'success', 'duration_ms', 'row_count', 'error', 'created_at']),
        ]);
    }
}
