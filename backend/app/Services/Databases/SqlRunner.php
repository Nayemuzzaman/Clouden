<?php

namespace App\Services\Databases;

use App\Models\ProjectDatabase;
use PgSql\Result;

/**
 * Executes administrator SQL against an application database using the
 * database's own role. Uses the native pgsql extension so multi-statement
 * scripts run exactly as typed (no client-side placeholder parsing).
 */
class SqlRunner
{
    public function __construct(private readonly DatabaseConnector $connector) {}

    /**
     * @return array{success: bool, columns: list<string>, rows: list<list<mixed>>, row_count: int|null, affected_rows: int|null, truncated: bool, duration_ms: int, error: string|null, command: string|null, statements: int}
     */
    public function run(ProjectDatabase $database, string $sql): array
    {
        $conn = $this->connector->native($database);
        $maxRows = (int) config('privatecloud.apps_db.sql_max_rows');
        $started = hrtime(true);

        try {
            if (! pg_send_query($conn, $sql)) {
                return $this->error('Could not send the query.', $started);
            }

            $last = null;
            $statements = 0;
            $error = null;
            while (($result = pg_get_result($conn)) !== false) {
                $statements++;
                $status = pg_result_status($result);
                if ($status === PGSQL_FATAL_ERROR || $status === PGSQL_BAD_RESPONSE || $status === PGSQL_NONFATAL_ERROR) {
                    $error = $this->formatError($result);
                    pg_free_result($result);

                    continue;
                }
                if ($last !== null) {
                    pg_free_result($last);
                }
                $last = $result;
            }
            $duration = (int) round((hrtime(true) - $started) / 1e6);

            if ($error !== null) {
                if ($last !== null) {
                    pg_free_result($last);
                }

                return $this->error($error, $started, $statements);
            }
            if ($last === null) {
                return ['success' => true, 'columns' => [], 'rows' => [], 'row_count' => 0, 'affected_rows' => null, 'truncated' => false, 'duration_ms' => $duration, 'error' => null, 'command' => null, 'statements' => $statements];
            }

            $columns = [];
            $fieldCount = pg_num_fields($last);
            for ($i = 0; $i < $fieldCount; $i++) {
                $columns[] = pg_field_name($last, $i);
            }
            $total = pg_num_rows($last);
            $rows = [];
            for ($r = 0; $r < min($total, $maxRows); $r++) {
                $rows[] = pg_fetch_row($last, $r);
            }
            $affected = pg_result_status($last) === PGSQL_COMMAND_OK ? pg_affected_rows($last) : null;
            $command = $fieldCount === 0 ? 'OK' : null;
            pg_free_result($last);

            return [
                'success' => true,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => $fieldCount > 0 ? $total : null,
                'affected_rows' => $affected,
                'truncated' => $total > $maxRows,
                'duration_ms' => $duration,
                'error' => null,
                'command' => $command,
                'statements' => $statements,
            ];
        } finally {
            pg_close($conn);
        }
    }

    private function formatError(Result $result): string
    {
        $message = (string) pg_result_error_field($result, PGSQL_DIAG_MESSAGE_PRIMARY);
        $detail = pg_result_error_field($result, PGSQL_DIAG_MESSAGE_DETAIL);
        $hint = pg_result_error_field($result, PGSQL_DIAG_MESSAGE_HINT);
        $position = pg_result_error_field($result, PGSQL_DIAG_STATEMENT_POSITION);

        $text = 'ERROR: '.$message;
        if ($detail) {
            $text .= "\nDETAIL: ".$detail;
        }
        if ($hint) {
            $text .= "\nHINT: ".$hint;
        }
        if ($position) {
            $text .= "\nPosition: ".$position;
        }

        return $text;
    }

    /** @return array{success: bool, columns: list<string>, rows: list<list<mixed>>, row_count: int|null, affected_rows: int|null, truncated: bool, duration_ms: int, error: string|null, command: string|null, statements: int} */
    private function error(string $message, int|float $started, int $statements = 0): array
    {
        return [
            'success' => false, 'columns' => [], 'rows' => [], 'row_count' => null, 'affected_rows' => null, 'truncated' => false,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6), 'error' => $message, 'command' => null, 'statements' => $statements,
        ];
    }
}
