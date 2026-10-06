<?php

namespace App\Http\Resources;

use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Secret values are never included; non-secret values are shown in full.
 * Secrets are revealed one at a time through a password-confirmed endpoint.
 *
 * @mixin EnvironmentVariable
 */
class EnvironmentVariableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->is_secret ? null : $this->value,
            'is_secret' => $this->is_secret,
            'is_system' => $this->is_system,
            'available_at_build' => $this->available_at_build,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
