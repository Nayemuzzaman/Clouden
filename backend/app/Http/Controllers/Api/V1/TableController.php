<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProjectDatabase;
use App\Services\Audit\AuditLogger;
use App\Services\Databases\ColumnType;
use App\Services\Databases\DatabaseConnector;
use App\Services\Databases\TableBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TableController extends Controller
{
    public function __construct(
        private readonly DatabaseConnector $connector,
        private readonly AuditLogger $audit,
    ) {}

    public function schemas(ProjectDatabase $database): JsonResponse
    {
        return response()->json(['schemas' => $this->browser($database)->schemas(), 'column_types' => ColumnType::common(), 'default_expressions' => ColumnType::DEFAULT_EXPRESSIONS]);
    }

    public function index(Request $request, ProjectDatabase $database): JsonResponse
    {
        return response()->json(['tables' => $this->browser($database)->tables($this->schema($request))]);
    }

    public function show(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        return response()->json($this->browser($database)->describe($this->schema($request), $table));
    }

    public function store(Request $request, ProjectDatabase $database): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            ...$this->columnRules('columns.*.'),
            'columns.*.primary' => ['boolean'],
        ]);
        $this->browser($database)->createTable($this->schema($request), $data['name'], $data['columns']);
        $this->audit->log('database.table_created', $database, metadata: ['table' => $data['name']]);

        return response()->json(['message' => 'Table created.'], 201);
    }

    public function destroy(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if ($request->input('confirm') !== $table) {
            throw ValidationException::withMessages(['confirm' => 'Type the table name exactly to confirm.']);
        }
        $this->browser($database)->dropTable($this->schema($request), $table);
        $this->audit->log('database.table_dropped', $database, metadata: ['table' => $table]);

        return response()->json(null, 204);
    }

    public function rows(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort' => ['nullable', 'string', 'max:63'],
            'direction' => ['nullable', 'in:asc,desc'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*.column' => ['required', 'string', 'max:63'],
            'filters.*.operator' => ['required', Rule::in(TableBrowser::FILTER_OPERATORS)],
            'filters.*.value' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->browser($database)->rows(
            $this->schema($request), $table, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50),
            $data['sort'] ?? null, $data['direction'] ?? 'asc', $data['filters'] ?? [],
        ));
    }

    public function insertRow(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $data = $request->validate(['values' => ['present', 'array', 'max:500'], 'values.*' => ['nullable']]);
        $row = $this->browser($database)->insert($this->schema($request), $table, $this->stringValues($data['values']));
        $this->audit->log('database.row_inserted', $database, metadata: ['table' => $table]);

        return response()->json(['row' => $row], 201);
    }

    public function updateRow(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $data = $request->validate([
            'primary_key' => ['required', 'array', 'min:1'],
            'values' => ['required', 'array', 'min:1', 'max:500'],
            'values.*' => ['nullable'],
        ]);
        $row = $this->browser($database)->update($this->schema($request), $table, $this->stringValues($data['primary_key']), $this->stringValues($data['values']));
        $this->audit->log('database.row_updated', $database, metadata: ['table' => $table]);

        return response()->json(['row' => $row]);
    }

    public function deleteRow(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $data = $request->validate(['primary_key' => ['required', 'array', 'min:1']]);
        $this->browser($database)->delete($this->schema($request), $table, $this->stringValues($data['primary_key']));
        $this->audit->log('database.row_deleted', $database, metadata: ['table' => $table]);

        return response()->json(null, 204);
    }

    public function addColumn(Request $request, ProjectDatabase $database, string $table): JsonResponse
    {
        $data = $request->validate($this->columnRules(''));
        $this->browser($database)->addColumn($this->schema($request), $table, $data);
        $this->audit->log('database.column_added', $database, metadata: ['table' => $table, 'column' => $data['name']]);

        return response()->json(['message' => 'Column added.'], 201);
    }

    public function updateColumn(Request $request, ProjectDatabase $database, string $table, string $column): JsonResponse
    {
        $data = $request->validate([
            'rename_to' => ['nullable', 'string', 'max:63'],
            'nullable' => ['nullable', 'boolean'],
            'default_expression' => ['nullable', Rule::in(ColumnType::DEFAULT_EXPRESSIONS)],
            'default_value' => ['nullable', 'string', 'max:1000'],
            'drop_default' => ['boolean'],
        ]);
        $this->browser($database)->alterColumn($this->schema($request), $table, $column, $data);
        $this->audit->log('database.column_updated', $database, metadata: ['table' => $table, 'column' => $column]);

        return response()->json(['message' => 'Column updated.']);
    }

    public function dropColumn(Request $request, ProjectDatabase $database, string $table, string $column): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if ($request->input('confirm') !== $column) {
            throw ValidationException::withMessages(['confirm' => 'Type the column name exactly to confirm.']);
        }
        $this->browser($database)->dropColumn($this->schema($request), $table, $column);
        $this->audit->log('database.column_dropped', $database, metadata: ['table' => $table, 'column' => $column]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function columnRules(string $prefix): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:63'],
            $prefix.'type' => ['required', 'string', 'max:40'],
            $prefix.'nullable' => ['boolean'],
            $prefix.'default_expression' => ['nullable', Rule::in(ColumnType::DEFAULT_EXPRESSIONS)],
            $prefix.'default_value' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private function stringValues(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[(string) $key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                default => (string) $value,
            };
        }

        return $result;
    }

    private function schema(Request $request): string
    {
        return (string) ($request->query('schema') ?: $request->input('schema') ?: 'public');
    }

    private function browser(ProjectDatabase $database): TableBrowser
    {
        abort_unless($database->status === 'ready', 409, 'The database is not ready.');

        return new TableBrowser($this->connector->pdo($database));
    }
}
