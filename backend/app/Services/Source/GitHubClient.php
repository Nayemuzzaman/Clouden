<?php

namespace App\Services\Source;

use App\Models\GithubConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GitHub REST API client. The token is read from encrypted storage on the
 * server and never returned to the browser.
 */
class GitHubClient
{
    public function __construct(private readonly ?string $token = null) {}

    public static function forConnection(?GithubConnection $connection = null): self
    {
        $connection ??= GithubConnection::current();

        return new self($connection?->token);
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /** @return array{login: string, name: ?string, avatar_url: ?string, scopes: ?string} */
    public function authenticatedUser(): array
    {
        $response = $this->send(fn (PendingRequest $r) => $r->get('/user'));
        $data = $response->json();

        return [
            'login' => (string) $data['login'],
            'name' => $data['name'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'scopes' => $response->header('X-OAuth-Scopes') ?: null,
        ];
    }

    /** @return list<array{full_name: string, private: bool, default_branch: string, description: ?string, updated_at: ?string}> */
    public function repositories(int $maxPages = 5): array
    {
        $repos = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $batch = $this->send(fn (PendingRequest $r) => $r->get('/user/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ]))->json();
            foreach ($batch as $repo) {
                $repos[] = [
                    'full_name' => $repo['full_name'],
                    'private' => (bool) $repo['private'],
                    'default_branch' => $repo['default_branch'] ?? 'main',
                    'description' => $repo['description'] ?? null,
                    'updated_at' => $repo['pushed_at'] ?? $repo['updated_at'] ?? null,
                ];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $repos;
    }

    /** @return array<string, mixed> */
    public function repository(string $fullName): array
    {
        $this->assertFullName($fullName);

        return $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName))->json();
    }

    /** @return list<string> */
    public function branches(string $fullName): array
    {
        $this->assertFullName($fullName);
        $branches = [];
        for ($page = 1; $page <= 5; $page++) {
            $batch = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/branches', ['per_page' => 100, 'page' => $page]))->json();
            foreach ($batch as $branch) {
                $branches[] = (string) $branch['name'];
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $branches;
    }

    public function commit(string $fullName, string $ref): CommitInfo
    {
        $this->assertFullName($fullName);
        $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/commits/'.rawurlencode($ref)))->json();

        return new CommitInfo(
            sha: (string) $data['sha'],
            message: $data['commit']['message'] ?? null,
            author: $data['commit']['author']['name'] ?? ($data['author']['login'] ?? null),
            committedAt: $data['commit']['author']['date'] ?? null,
        );
    }

    /** @return list<string> file names in the repository root */
    public function rootFiles(string $fullName, string $ref): array
    {
        $this->assertFullName($fullName);
        $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/contents', ['ref' => $ref]))->json();

        return array_values(array_map(fn ($f) => (string) $f['name'], is_array($data) ? $data : []));
    }

    /** Download the tar.gz archive of an exact commit to $destination. */
    public function downloadTarball(string $fullName, string $sha, string $destination, int $timeout = 300): void
    {
        $this->assertFullName($fullName);
        if (! GitRefs::isValidSha($sha)) {
            throw new SourceException('Invalid commit SHA.');
        }
        $this->send(fn (PendingRequest $r) => $r->timeout($timeout)->sink($destination)->get('/repos/'.$fullName.'/tarball/'.$sha));
    }

    public function createWebhook(string $fullName, string $url, string $secret): int
    {
        $this->assertFullName($fullName);
        $data = $this->send(fn (PendingRequest $r) => $r->post('/repos/'.$fullName.'/hooks', [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]))->json();

        return (int) $data['id'];
    }

    public function deleteWebhook(string $fullName, int $hookId): void
    {
        $this->assertFullName($fullName);
        try {
            $this->send(fn (PendingRequest $r) => $r->delete('/repos/'.$fullName.'/hooks/'.$hookId));
        } catch (SourceException) {
            // Already removed or no longer accessible: nothing else to clean up.
        }
    }

    private function assertFullName(string $fullName): void
    {
        if (! GitRefs::isValidFullName($fullName)) {
            throw new SourceException('Repository must be in the form owner/name.');
        }
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('privatecloud.github.api_url'), '/'))
            ->timeout((int) config('privatecloud.github.timeout'))
            ->connectTimeout(10)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => config('privatecloud.name'),
            ]);

        return $this->hasToken() ? $request->withToken((string) $this->token) : $request;
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->client());
        } catch (ConnectionException) {
            throw new SourceException('GitHub could not be reached. Check the server\'s internet connection and try again.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw new SourceException(match (true) {
            $response->status() === 401 => 'GitHub rejected the access token. Reconnect GitHub in Settings.',
            $response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'GitHub API rate limit reached. Connect a GitHub token in Settings or try again later.',
            $response->status() === 403 => 'The GitHub token does not have permission for this action.',
            $response->status() === 404 => $this->hasToken()
                ? 'Repository, branch or commit not found, or the GitHub token cannot access it.'
                : 'Repository, branch or commit not found. Private repositories require connecting GitHub in Settings.',
            $response->status() === 422 => 'GitHub rejected the request: '.($response->json('errors.0.message') ?? $response->json('message') ?? 'validation failed'),
            $response->serverError() => 'GitHub is temporarily unavailable (HTTP '.$response->status().'). Try again shortly.',
            default => 'GitHub request failed (HTTP '.$response->status().').',
        });
    }
}
