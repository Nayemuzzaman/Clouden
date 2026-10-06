<?php

namespace App\Http\Requests;

use App\Services\Monitoring\HostMetrics;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Validation shared by project creation and the settings page. */
trait ProjectSettingsRules
{
    /** @return array<string, mixed> */
    protected function settingsRules(): array
    {
        return [
            'dockerfile_path' => ['sometimes', 'string', 'max:255'],
            'build_context' => ['sometimes', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'memory_limit_mb' => ['sometimes', 'integer', 'min:64', 'max:65536'],
            'cpu_limit' => ['sometimes', 'numeric', 'min:0.1', 'max:64'],
            'health_check_type' => ['sometimes', Rule::in(['http', 'container'])],
            'health_check_path' => ['sometimes', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9\/._~%!$&\'()*+,;=:@?-]*$/'],
            'health_check_status_min' => ['sometimes', 'integer', 'min:100', 'max:599'],
            'health_check_status_max' => ['sometimes', 'integer', 'min:100', 'max:599', 'gte:health_check_status_min'],
            'health_check_timeout' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'health_check_retries' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'health_check_interval' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'image_retention' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'backup_schedule' => ['sometimes', Rule::in(['off', 'daily', 'weekly'])],
            'backup_time' => ['sometimes', 'date_format:H:i'],
            'backup_retention' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }

    protected function validateSettings(Validator $validator): void
    {
        foreach (['dockerfile_path', 'build_context'] as $field) {
            $value = $this->input($field);
            if ($value !== null && (str_contains((string) $value, '..') || str_starts_with((string) $value, '/') || str_contains((string) $value, "\0") || ! preg_match('#^[A-Za-z0-9._/-]+$#', (string) $value))) {
                $validator->errors()->add($field, 'Use a relative path inside the repository (no "..").');
            }
        }
        $memory = $this->input('memory_limit_mb');
        $total = app(HostMetrics::class)->memory()['total'] ?? null;
        if ($memory !== null && $total !== null && (int) $memory * 1024 * 1024 > $total) {
            $validator->errors()->add('memory_limit_mb', 'The memory limit is larger than the server\'s total memory.');
        }
    }

    public static function mountPathError(string $path): ?string
    {
        if (! str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0") || ! preg_match('#^/[A-Za-z0-9._/-]+$#', $path)) {
            return 'Use an absolute path inside the container, such as /app/storage.';
        }
        $normalized = rtrim($path, '/') ?: '/';
        $systemDirs = ['/', '/bin', '/boot', '/dev', '/etc', '/lib', '/lib64', '/proc', '/run', '/sbin', '/sys', '/usr', '/var/run'];
        if (in_array($normalized, $systemDirs, true) || preg_match('#^/(proc|sys|dev)/#', $normalized)) {
            return "Mounting a volume at {$normalized} would break the container.";
        }

        return null;
    }
}
