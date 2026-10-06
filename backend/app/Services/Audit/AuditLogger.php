<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records important administrator and system actions. Metadata is scrubbed so
 * secret values can never be written to the audit trail.
 */
class AuditLogger
{
    private const SENSITIVE = '/(password|secret|token|value|credential|private|authorization|cookie|dsn|connection_string)/i';

    /** @param array<string, mixed> $metadata */
    public function log(string $action, ?Model $resource = null, string $result = 'success', array $metadata = [], ?string $label = null, ?int $userId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_id' => $resource?->getKey() !== null && is_numeric($resource->getKey()) ? (int) $resource->getKey() : null,
            'resource_label' => $label ?? $this->labelFor($resource),
            'result' => $result,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'metadata' => $metadata === [] ? null : $this->scrub($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    private function labelFor(?Model $resource): ?string
    {
        if (! $resource) {
            return null;
        }
        foreach (['name', 'hostname', 'email', 'key', 'label'] as $attribute) {
            $value = $resource->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }
}
