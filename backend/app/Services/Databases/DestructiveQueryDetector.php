<?php

namespace App\Services\Databases;

/**
 * Best-effort detection of clearly destructive SQL so the editor can ask for
 * explicit confirmation. This is a safety net, not a guarantee: dynamic SQL,
 * functions and DO blocks can still modify data without being detected.
 */
final class DestructiveQueryDetector
{
    /** @return list<string> human readable warnings */
    public static function analyze(string $sql): array
    {
        $warnings = [];
        foreach (self::statements(self::stripLiteralsAndComments($sql)) as $statement) {
            $s = strtoupper(trim(preg_replace('/\s+/', ' ', $statement) ?? ''));
            if ($s === '') {
                continue;
            }
            // Ignore a leading WITH ... clause for DELETE/UPDATE detection.
            $core = preg_replace('/^WITH\b.*?\)\s*(?=(DELETE|UPDATE|INSERT|SELECT)\b)/', '', $s) ?? $s;

            if (preg_match('/^DROP\s+(\w+(?:\s+\w+)?)/', $core, $m)) {
                $warnings[] = 'DROP '.strtolower($m[1]).' permanently removes the object and its data.';
            } elseif (str_starts_with($core, 'TRUNCATE')) {
                $warnings[] = 'TRUNCATE deletes every row in the table.';
            } elseif (str_starts_with($core, 'ALTER')) {
                $warnings[] = 'ALTER changes the structure of the database and may lock or rewrite tables.';
            } elseif (preg_match('/^DELETE\s+FROM\b/', $core) && ! preg_match('/\bWHERE\b/', $core)) {
                $warnings[] = 'DELETE without WHERE removes every row in the table.';
            } elseif (preg_match('/^UPDATE\b/', $core) && ! preg_match('/\bWHERE\b/', $core)) {
                $warnings[] = 'UPDATE without WHERE changes every row in the table.';
            } elseif (preg_match('/^(GRANT|REVOKE)\b/', $core)) {
                $warnings[] = 'GRANT/REVOKE changes database permissions.';
            }
        }

        return array_values(array_unique($warnings));
    }

    /** Replace string literals, dollar-quoted bodies, quoted identifiers and comments with spaces. */
    public static function stripLiteralsAndComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $out .= ' ';
            } elseif ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $out .= ' ';
            } elseif ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $i = $j;
                $out .= $c === '"' ? 'X' : "''";
            } elseif ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $i = $end === false ? $len : $end + strlen($tag) - 1;
                $out .= "''";
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
    }
}
