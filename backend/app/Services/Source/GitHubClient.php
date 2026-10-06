<?php

namespace App\Services\Source;

use App\Models\GithubConnection;
use App\Models\Setting;
use App\Services\Notifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GitHub REST API client. The token is read from encrypted storage on the
 * server, sent only in the Authorization header of requests to the GitHub API
 * (never in a URL, a command line, a file or a git configuration), and never
 * returned to the browser.
 *
 * GitHub is not hammered when it refuses requests: after a rate-limit response
 * every call fails fast until the reset time, and after the saved token was
 * rejected calls with it fail fast for a few minutes (reconnecting GitHub in
 * Settings, or "Check connection", clears that immediately).
 */
class GitHubClient
{
    public const TOKEN_REJECTED_SETTING = 'github.token_rejected_at';

    private const AUTH_BACKOFF_KEY = 'privatecloud:github:auth-backoff';

    private const AUTH_BACKOFF_SECONDS = 300;

    /** @param bool $storedToken the token comes from the saved connection (not one being verified) */
    public function __construct(
        private readonly ?string $token = null,
        private readonly bool $storedToken = false,
        private readonly bool $ignoreAuthBackoff = false,
    ) {}

    public static function forConnection(?GithubConnection $connection = null): self
    {
        $connection ??= GithubConnection::current();

        return new self($connection?->token, $connection !== null);
    }

    /** Unauthenticated client: public repositories are read without sending any credential. */
    public static function anonymous(): self
    {
        return new self;
    }

    /** The saved token, ignoring the back-off after a rejection (for an explicit "Check connection"). */
    public static function forConnectionCheck(): self
    {
        $connection = GithubConnection::current();

        return new self($connection?->token, $connection !== null, ignoreAuthBackoff: true);
    }

    public static function clearBackoff(): void
    {
        Cache::forget(self::AUTH_BACKOFF_KEY);
        Cache::forget(self::rateLimitKey(true));
        Cache::forget(self::rateLimitKey(false));
    }

    /** Unix time until which GitHub asked us to stop sending requests, if any. */
    public static function rateLimitedUntil(bool $authenticated): ?int
    {
        $until = Cache::get(self::rateLimitKey($authenticated));

        return is_int($until) && $until > time() ? $until : null;
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

    /** "public" or "private". */
    public function visibility(string $fullName): string
    {
        $data = $this->repository($fullName);

        return ($data['private'] ?? false) ? 'private' : 'public';
    }

    /** The head commit of a branch (404 → SourceException kind not_found). */
    public function branch(string $fullName, string $branch): CommitInfo
    {
        $this->assertFullName($fullName);
        if (! GitRefs::isValidBranch($branch)) {
            throw new SourceException('Invalid branch name.', SourceException::INVALID);
        }
        $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/branches/'.self::encodePath($branch)), 'contents')->json();
        $commit = $data['commit'] ?? [];

        return new CommitInfo(
            sha: (string) ($commit['sha'] ?? ''),
            message: $commit['commit']['message'] ?? null,
            author: $commit['commit']['author']['name'] ?? ($commit['author']['login'] ?? null),
            committedAt: $commit['commit']['author']['date'] ?? null,
        );
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
        $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/commits/'.self::encodePath($ref)), 'contents')->json();

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
        $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/contents', ['ref' => $ref]), 'contents')->json();

        return array_values(array_map(fn ($f) => (string) $f['name'], is_array($data) ? $data : []));
    }

    /** Download the tar.gz archive of an exact commit to $destination. */
    public function downloadTarball(string $fullName, string $sha, string $destination, int $timeout = 300): void
    {
        $this->assertFullName($fullName);
        if (! GitRefs::isValidSha($sha)) {
            throw new SourceException('Invalid commit SHA.', SourceException::INVALID);
        }
        // GitHub answers with a redirect to codeload.github.com; the HTTP client
        // drops the Authorization header when a redirect leaves the API host.
        $this->send(fn (PendingRequest $r) => $r->timeout($timeout)->sink($destination)->get('/repos/'.$fullName.'/tarball/'.$sha), 'contents');
    }

    public function createWebhook(string $fullName, string $url, string $secret): int
    {
        $this->assertFullName($fullName);
        $data = $this->send(fn (PendingRequest $r) => $r->post('/repos/'.$fullName.'/hooks', [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]), 'hooks')->json();

        return (int) $data['id'];
    }

    /**
     * The webhook with this id, or null when it no longer exists.
     *
     * @return array{active: bool, url: ?string, events: list<string>, last_response: array{code: ?int, status: ?string, message: ?string}}|null
     */
    public function webhook(string $fullName, int $hookId): ?array
    {
        $this->assertFullName($fullName);
        try {
            $data = $this->send(fn (PendingRequest $r) => $r->get('/repos/'.$fullName.'/hooks/'.$hookId), 'hooks')->json();
        } catch (SourceException $e) {
            if ($e->kind === SourceException::NOT_FOUND) {
                return null;
            }

            throw $e;
        }

        return [
            'active' => (bool) ($data['active'] ?? false),
            'url' => $data['config']['url'] ?? null,
            'events' => array_values(array_map('strval', $data['events'] ?? [])),
            'last_response' => [
                'code' => isset($data['last_response']['code']) ? (int) $data['last_response']['code'] : null,
                'status' => $data['last_response']['status'] ?? null,
                'message' => $data['last_response']['message'] ?? null,
            ],
        ];
    }

    /**
     * Delete exactly the webhook PrivateCloud created (by id; other hooks of the
     * repository are never touched). A webhook that no longer exists counts as
     * deleted; any other failure is thrown so the caller can report the orphan.
     */
    public function deleteWebhook(string $fullName, int $hookId): void
    {
        $this->assertFullName($fullName);
        try {
            $this->send(fn (PendingRequest $r) => $r->delete('/repos/'.$fullName.'/hooks/'.$hookId), 'hooks');
        } catch (SourceException $e) {
            if ($e->kind !== SourceException::NOT_FOUND) {
                throw $e;
            }
        }
    }

    /** Remember that the saved token stopped working (expired/revoked) and tell the administrator once. */
    private function recordRejectedToken(): void
    {
        try {
            if (Setting::get(self::TOKEN_REJECTED_SETTING) === null) {
                Setting::put(self::TOKEN_REJECTED_SETTING, now()->toIso8601String());
                app(Notifier::class)->notify('github.token_rejected', 'GitHub token rejected', 'GitHub rejected the saved access token (expired or revoked). Deployments from GitHub fail until you connect a new token in Settings.', 'error', '/settings');
            }
        } catch (\Throwable) {
            // Reporting must never hide the original error.
        }
    }

    private function assertFullName(string $fullName): void
    {
        if (! GitRefs::isValidFullName($fullName)) {
            throw new SourceException('Repository must be in the form owner/name.', SourceException::INVALID);
        }
    }

    /** Branch names may contain "/", which GitHub expects unencoded in the path. */
    private static function encodePath(string $ref): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }

    private static function rateLimitKey(bool $authenticated): string
    {
        return 'privatecloud:github:rate-limited:'.($authenticated ? 'token' : 'anonymous');
    }

    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        return $response->status() === 403 && ($response->header('X-RateLimit-Remaining') === '0'
            || $response->header('Retry-After') !== ''
            || str_contains(strtolower((string) $response->json('message')), 'rate limit'));
    }

    private function rememberRateLimit(Response $response): int
    {
        $retryAfter = (int) $response->header('Retry-After');
        $reset = (int) $response->header('X-RateLimit-Reset');
        $until = match (true) {
            $retryAfter > 0 => time() + $retryAfter,
            $reset > time() => $reset,
            default => time() + 60,
        };
        $until = min($until, time() + 3600);
        Cache::put(self::rateLimitKey($this->hasToken()), $until, $until - time() + 1);

        return $until;
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

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  string  $purpose  "contents" or "hooks": selects the permission named in a 403 message
     */
    private function send(callable $call, string $purpose = 'metadata'): Response
    {
        if (($until = self::rateLimitedUntil($this->hasToken())) !== null) {
            throw new SourceException('GitHub API rate limit reached. PrivateCloud pauses GitHub requests until '.gmdate('H:i', $until).' UTC.', SourceException::RATE_LIMITED);
        }
        if ($this->storedToken && $this->hasToken() && ! $this->ignoreAuthBackoff && Cache::has(self::AUTH_BACKOFF_KEY)) {
            throw new SourceException('GitHub rejected the saved access token (expired or revoked). Reconnect GitHub in Settings.', SourceException::AUTH);
        }

        try {
            $response = $call($this->client());
        } catch (ConnectionException) {
            throw new SourceException('GitHub could not be reached. Check the server\'s internet connection and try again.', SourceException::UNAVAILABLE);
        }

        if ($response->successful()) {
            if ($this->storedToken && $this->hasToken()) {
                Cache::forget(self::AUTH_BACKOFF_KEY);
            }

            return $response;
        }
        if ($response->status() === 401 && $this->storedToken && $this->hasToken()) {
            $this->recordRejectedToken();
            Cache::put(self::AUTH_BACKOFF_KEY, true, self::AUTH_BACKOFF_SECONDS);
        }
        if ($this->isRateLimited($response)) {
            $until = $this->rememberRateLimit($response);

            throw new SourceException('GitHub API rate limit reached. PrivateCloud pauses GitHub requests until '.gmdate('H:i', $until).' UTC'
                .($this->hasToken() ? '.' : '; connecting a GitHub token in Settings raises the limit.'), SourceException::RATE_LIMITED);
        }

        $status = $response->status();
        [$message, $kind] = match (true) {
            $status === 401 => ['GitHub rejected the access token (expired or revoked). Reconnect GitHub in Settings.', SourceException::AUTH],
            $status === 403 && $purpose === 'contents' => ['The GitHub token cannot read this repository\'s code. A fine-grained token needs the "Contents: Read" repository permission for it.', SourceException::FORBIDDEN],
            $status === 403 && $purpose === 'hooks' => ['The GitHub token cannot manage webhooks of this repository. A fine-grained token needs the "Webhooks: Read and write" repository permission.', SourceException::FORBIDDEN],
            $status === 403 => ['The GitHub token does not have permission for this action.', SourceException::FORBIDDEN],
            $status === 404 => [$this->hasToken()
                ? 'Repository, branch or commit not found, or the GitHub token cannot access it.'
                : 'Repository, branch or commit not found. Private repositories require connecting GitHub in Settings.', SourceException::NOT_FOUND],
            $status === 422 => ['GitHub rejected the request: '.($response->json('errors.0.message') ?? $response->json('message') ?? 'validation failed'), SourceException::INVALID],
            $response->serverError() => ['GitHub is temporarily unavailable (HTTP '.$status.'). Try again shortly.', SourceException::UNAVAILABLE],
            default => ['GitHub request failed (HTTP '.$status.').', SourceException::OTHER],
        };

        throw new SourceException($message, $kind);
    }
}
