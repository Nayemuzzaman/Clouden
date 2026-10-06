<?php

namespace App\Services\Server;

use App\Models\Server;

class ServerIdentity
{
    /** @return list<string> */
    public function publicIps(): array
    {
        $server = Server::local();
        $ips = array_filter([
            config('privatecloud.public_ipv4') ?: $server->public_ipv4,
            config('privatecloud.public_ipv6') ?: $server->public_ipv6,
        ]);

        return array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }

    public function publicIpv4(): ?string
    {
        return config('privatecloud.public_ipv4') ?: Server::local()->public_ipv4;
    }

    public function webhookBaseUrl(): ?string
    {
        $url = config('privatecloud.public_url');
        if (! $url && config('privatecloud.dashboard_domain')) {
            $url = 'https://'.config('privatecloud.dashboard_domain');
        }

        return $url ? rtrim((string) $url, '/') : null;
    }
}
