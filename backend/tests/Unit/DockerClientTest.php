<?php

namespace Tests\Unit;

use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DockerClientTest extends TestCase
{
    public function test_delete_parameters_are_sent_in_the_query_string(): void
    {
        // Regression: Docker ignores request bodies on DELETE, so "force" must be a query parameter
        // or running containers (e.g. a failed deployment candidate) are never removed.
        Http::fake(['http://docker/*' => Http::response('', 204)]);
        $client = new DockerClient('/var/run/docker.sock', 'v1.44');

        $client->removeContainer('pc-shop-3', force: true);
        $client->removeImage('pc-shop:1');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_starts_with($r->url(), 'http://docker/v1.44/containers/pc-shop-3?') && str_contains($r->url(), 'force=true'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/images/pc-shop%3A1?') && str_contains($r->url(), 'noprune=false'));
    }

    public function test_errors_carry_docker_message_and_status(): void
    {
        Http::fake(['http://docker/*' => Http::response(['message' => 'No such image: x'], 404)]);
        $client = new DockerClient('/var/run/docker.sock', 'v1.44');

        $this->assertNull($client->inspectImage('x'));
        try {
            $client->createContainer('c', ['Image' => 'x']);
            $this->fail('expected exception');
        } catch (DockerException $e) {
            $this->assertSame('No such image: x', $e->getMessage());
            $this->assertTrue($e->isNotFound());
        }
    }
}
