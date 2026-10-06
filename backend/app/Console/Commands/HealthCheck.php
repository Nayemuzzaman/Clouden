<?php

namespace App\Console\Commands;

use App\Services\Monitoring\ServiceHealth;
use Illuminate\Console\Command;

/** Command-line view of the Server page's service health (used by scripts/validate-install.sh). */
class HealthCheck extends Command
{
    protected $signature = 'privatecloud:health {--json : Print JSON}
        {--plain : Print one "name|status|detail" line per service}';

    protected $description = 'Check Docker, both PostgreSQL servers, Caddy, Redis and the background worker';

    public function handle(ServiceHealth $health): int
    {
        $services = $health->check(fresh: true);
        if ($this->option('plain')) {
            foreach ($services as $s) {
                $this->line($s['name'].'|'.$s['status'].'|'.str_replace(["\n", '|'], [' ', '/'], (string) ($s['detail'] ?? '')));
            }
        } elseif ($this->option('json')) {
            $this->line((string) json_encode($services, JSON_PRETTY_PRINT));
        } else {
            $this->table(['Service', 'Status', 'Detail'], array_map(fn ($s) => [$s['name'], $s['status'], $s['detail'] ?? ''], $services));
        }

        $down = array_filter($services, fn ($s) => $s['status'] === 'down');

        return $down === [] ? self::SUCCESS : self::FAILURE;
    }
}
