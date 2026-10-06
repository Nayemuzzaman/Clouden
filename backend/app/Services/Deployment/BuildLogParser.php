<?php

namespace App\Services\Deployment;

/**
 * Turns raw BuildKit "--progress=plain" output into an explanation a person can
 * act on: which Dockerfile step failed and the most relevant error line.
 * Heuristic by nature; the full log is always kept alongside.
 */
final class BuildLogParser
{
    private const ERROR_HINTS = '/(error|ERR!|not found|cannot|can\'t|failed|fatal|exception|denied|no such file|undefined|unable)/i';

    /** @param list<string> $lines */
    public static function explain(array $lines): BuildFailure
    {
        $steps = [];
        $failedStep = null;

        foreach ($lines as $line) {
            if (preg_match('/^#(\d+) \[[^\]]*\] (.+)$/', $line, $m)) {
                $steps[$m[1]] = trim($m[2]);
            }
            if (preg_match('/^#(\d+) ERROR: /', $line, $m)) {
                $failedStep = $m[1];
            }
        }

        $joined = implode("\n", $lines);

        if (preg_match('/failed to (?:read|solve: failed to read) dockerfile: open (\S+): no such file/i', $joined, $m)
            || str_contains($joined, 'Cannot locate specified Dockerfile')) {
            return new BuildFailure('No Dockerfile was found at the configured path. Add a Dockerfile to the repository or change the Dockerfile path in project settings.', 'Read Dockerfile');
        }
        if (preg_match('/dockerfile parse error[^\n]*/i', $joined, $m)) {
            return new BuildFailure('The Dockerfile has a syntax error: '.trim($m[0]), 'Parse Dockerfile');
        }
        if (stripos($joined, 'no space left on device') !== false) {
            return new BuildFailure('The server ran out of disk space during the build. Free up space (old images, backups) and try again.');
        }

        if ($failedStep !== null) {
            $output = [];
            foreach ($lines as $line) {
                if (preg_match('/^#'.$failedStep.' (?:\d+\.\d+ )?(.*)$/', $line, $m)) {
                    $content = $m[1];
                    if (preg_match('/^(\[.*\]|DONE|CACHED|ERROR: process|sha256:)/', $content)) {
                        continue;
                    }
                    $output[] = $content;
                }
            }
            $output = array_values(array_filter($output, fn ($l) => trim($l) !== ''));
            $excerpt = implode("\n", array_slice($output, -20));
            $summary = self::mostRelevantLine($output) ?? self::finalError($lines) ?? 'The build step failed.';
            $step = isset($steps[$failedStep]) ? preg_replace('/^RUN /', '', $steps[$failedStep]) : null;

            return new BuildFailure($summary, $step, $excerpt !== '' ? $excerpt : null);
        }

        return new BuildFailure(self::finalError($lines) ?? 'The Docker image could not be built.', null, implode("\n", array_slice($lines, -20)) ?: null);
    }

    /** @param list<string> $lines */
    private static function mostRelevantLine(array $lines): ?string
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (preg_match(self::ERROR_HINTS, $line) && ! preg_match('/^npm (ERR!|error) (A complete log|code|path|command|errno|syscall)/', $line)) {
                return mb_substr($line, 0, 500);
            }
        }

        return $lines !== [] ? mb_substr(trim(end($lines)), 0, 500) : null;
    }

    /** @param list<string> $lines */
    private static function finalError(array $lines): ?string
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (str_starts_with(ltrim($lines[$i]), 'ERROR:')) {
                return mb_substr(trim(substr(ltrim($lines[$i]), 6)), 0, 500);
            }
        }

        return null;
    }
}
