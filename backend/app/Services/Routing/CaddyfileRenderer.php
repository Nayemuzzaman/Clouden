<?php

namespace App\Services\Routing;

use App\Models\Project;
use App\Services\Domains\DomainValidator;

/** Renders the Caddyfile snippet for one project. Pure function of its inputs. */
final class CaddyfileRenderer
{
    /**
     * @param  list<string>  $hostnames  already validated hostnames
     * @param  string|null  $upstream  "container:port", or null when the application is not running
     */
    public function render(Project $project, array $hostnames, ?string $upstream, bool $autoHttps, string $logsDir): string
    {
        $hostnames = array_values(array_filter($hostnames, fn ($h) => DomainValidator::isSafeHostname($h)));
        if ($hostnames === []) {
            return '';
        }

        $addresses = implode(', ', array_map(fn ($h) => $autoHttps ? $h : 'http://'.$h, $hostnames));
        $slug = preg_replace('/[^a-z0-9-]/', '', $project->slug);

        $body = $upstream !== null && preg_match('/^[a-z0-9][a-z0-9_.-]*:\d{1,5}$/', $upstream)
            ? "\treverse_proxy {$upstream}"
            : "\theader Content-Type \"text/html; charset=utf-8\"\n\trespond \"<!doctype html><title>Not running</title><body style=\\\"font-family:system-ui;padding:4rem;text-align:center;color:#334155\\\"><h1>This application is not running</h1><p>It may be stopped or still deploying.</p></body>\" 503";

        return <<<CADDY
        # Managed by PrivateCloud for project "{$slug}". Manual edits are overwritten.
        {$addresses} {
        \tencode zstd gzip
        \tlog {
        \t\toutput file {$logsDir}/{$slug}.access.log {
        \t\t\troll_size 10MiB
        \t\t\troll_keep 3
        \t\t}
        \t\tformat json
        \t}
        {$body}
        }

        CADDY;
    }
}
