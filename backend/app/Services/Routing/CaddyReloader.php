<?php

namespace App\Services\Routing;

interface CaddyReloader
{
    /**
     * Ask Caddy to load the current configuration. Caddy validates the config and
     * keeps serving the previous one if it is invalid.
     *
     * @return array{success: bool, output: string}
     */
    public function reload(): array;
}
