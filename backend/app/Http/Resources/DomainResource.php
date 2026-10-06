<?php

namespace App\Http\Resources;

use App\Models\Domain;
use App\Services\Server\ServerIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Domain */
class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'unicode_hostname' => function_exists('idn_to_utf8') ? (idn_to_utf8($this->hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $this->hostname) : $this->hostname,
            'is_primary' => $this->is_primary,
            'project' => $this->whenLoaded('project', fn () => ['name' => $this->project->name, 'slug' => $this->project->slug]),
            'dns' => [
                'status' => $this->dns_status,
                'records' => $this->dns_records,
                'expected_ip' => app(ServerIdentity::class)->publicIpv4(),
                'checked_at' => $this->dns_checked_at?->toIso8601String(),
            ],
            'certificate' => [
                'status' => $this->cert_status,
                'error' => $this->cert_error,
                'expires_at' => $this->cert_expires_at?->toIso8601String(),
                'checked_at' => $this->cert_checked_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
