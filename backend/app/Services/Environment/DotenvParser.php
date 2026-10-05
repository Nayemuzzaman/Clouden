<?php

namespace App\Services\Environment;

/** Parses .env formatted text for bulk import. */
final class DotenvParser
{
    /**
     * @return array{variables: array<string, string>, errors: list<string>}
     */
    public static function parse(string $content): array
    {
        $variables = [];
        $errors = [];
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        for ($i = 0; $i < count($lines); $i++) {
            $raw = $lines[$i];
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                $errors[] = 'Line '.($i + 1).': expected KEY=VALUE';

                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = ltrim(substr($line, $eq + 1));
            if (! EnvironmentKey::isValid($key)) {
                $errors[] = 'Line '.($i + 1).": \"{$key}\" is not a valid variable name";

                continue;
            }

            if (str_starts_with($value, '"')) {
                // Double quoted: may span multiple lines, supports \n, \" and \\ escapes.
                $buffer = substr($value, 1);
                while (! preg_match('/(?<!\\\\)(?:\\\\\\\\)*"\s*(#.*)?$/', $buffer) && $i + 1 < count($lines)) {
                    $buffer .= "\n".$lines[++$i];
                }
                $buffer = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)"\s*(#.*)?$/', '$1', $buffer) ?? $buffer;
                $value = strtr($buffer, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
            } elseif (str_starts_with($value, "'")) {
                $end = strrpos($value, "'");
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }

            $variables[$key] = $value;
        }

        return ['variables' => $variables, 'errors' => $errors];
    }
}
