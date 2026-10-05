<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\Deployment\BuildLogParser;
use App\Services\Deployment\FrameworkDetector;
use App\Services\Docker\StreamDemuxer;
use App\Services\Monitoring\ContainerStats;
use App\Services\Routing\CaddyfileRenderer;
use PHPUnit\Framework\TestCase;

class InfrastructureParsingTest extends TestCase
{
    public function test_build_log_parser_finds_failing_step_and_error(): void
    {
        $failure = BuildLogParser::explain([
            '#6 [3/5] COPY package.json .',
            '#6 DONE 0.0s',
            '#7 [4/5] RUN npm ci',
            '#7 2.311 npm ERR! code ERESOLVE',
            '#7 2.312 npm ERR! ERESOLVE unable to resolve dependency tree',
            '#7 2.313 npm ERR! A complete log of this run can be found in: /root/.npm/x.log',
            '#7 ERROR: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1',
            'ERROR: failed to solve: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1',
        ]);
        $this->assertSame('npm ci', $failure->step);
        $this->assertSame('npm ERR! ERESOLVE unable to resolve dependency tree', $failure->summary);
        $this->assertStringContainsString('ERESOLVE', (string) $failure->excerpt);
    }

    public function test_build_log_parser_handles_missing_dockerfile_and_disk_full(): void
    {
        $this->assertStringContainsString('No Dockerfile', BuildLogParser::explain(['ERROR: failed to solve: failed to read dockerfile: open Dockerfile: no such file or directory'])->summary);
        $this->assertStringContainsString('disk space', BuildLogParser::explain(['#5 [2/2] RUN x', '#5 1.0 write /x: no space left on device', '#5 ERROR: process x'])->summary);
    }

    public function test_docker_stream_demuxing(): void
    {
        $frame = fn (int $stream, string $data) => chr($stream)."\0\0\0".pack('N', strlen($data)).$data;
        $raw = $frame(1, "hello\nwor").$frame(2, "oops\n").$frame(1, "ld\n");

        $this->assertSame([
            ['stream' => 'stdout', 'line' => 'hello'],
            ['stream' => 'stderr', 'line' => 'oops'],
            ['stream' => 'stdout', 'line' => 'world'],
        ], StreamDemuxer::lines($raw));
        $this->assertSame("hello\nworoops\nld\n", StreamDemuxer::text($raw));
    }

    public function test_container_stats_parsing(): void
    {
        $stats = ContainerStats::parse([
            'cpu_stats' => ['cpu_usage' => ['total_usage' => 3_000_000], 'system_cpu_usage' => 20_000_000, 'online_cpus' => 2],
            'precpu_stats' => ['cpu_usage' => ['total_usage' => 1_000_000], 'system_cpu_usage' => 10_000_000],
            'memory_stats' => ['usage' => 300, 'limit' => 1000, 'stats' => ['inactive_file' => 100]],
            'networks' => ['eth0' => ['rx_bytes' => 5, 'tx_bytes' => 6], 'eth1' => ['rx_bytes' => 1, 'tx_bytes' => 1]],
        ]);
        $this->assertSame(40.0, $stats['cpu_percent']);
        $this->assertSame(200, $stats['memory_used_bytes']);
        $this->assertSame(6, $stats['net_rx_bytes']);
    }

    public function test_caddyfile_rendering(): void
    {
        $project = new Project(['slug' => 'shop']);
        $renderer = new CaddyfileRenderer;

        $https = $renderer->render($project, ['shop.example.com', 'www.shop.example.com'], 'pc-shop-4:3000', true, '/var/log/caddy');
        $this->assertStringContainsString("shop.example.com, www.shop.example.com {\n", $https);
        $this->assertStringContainsString("\treverse_proxy pc-shop-4:3000\n", $https);
        $this->assertStringContainsString('/var/log/caddy/shop.access.log', $https);

        $http = $renderer->render($project, ['shop.localhost.test'], null, false, '/logs');
        $this->assertStringContainsString('http://shop.localhost.test {', $http);
        $this->assertStringContainsString('respond', $http);
        $this->assertStringContainsString('503', $http);

        // Unsafe hostnames and upstreams never reach the configuration.
        $this->assertSame('', $renderer->render($project, ["evil.com {\n}\nimport /etc/passwd"], null, true, '/logs'));
        $this->assertStringNotContainsString('reverse_proxy', $renderer->render($project, ['ok.example.com'], 'x; respond 200', true, '/logs'));
    }

    public function test_framework_detection(): void
    {
        $this->assertSame('laravel', FrameworkDetector::detect(['artisan', 'composer.json'])['framework']);
        $this->assertSame('nextjs', FrameworkDetector::detect(['package.json'], '{"dependencies":{"next":"15","react":"19"}}')['framework']);
        $this->assertSame('react', FrameworkDetector::detect(['package.json'], '{"devDependencies":{"vite":"6"},"dependencies":{"react":"19"}}')['framework']);
        $this->assertSame('python', FrameworkDetector::detect(['requirements.txt'])['framework']);
        $this->assertTrue(FrameworkDetector::detect(['Dockerfile', 'go.mod'])['has_dockerfile']);
    }
}
