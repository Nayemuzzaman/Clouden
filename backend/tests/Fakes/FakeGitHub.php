<?php

namespace Tests\Fakes;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * In-memory model of the GitHub REST API subset PrivateCloud uses, for feature
 * tests (simulation, not github.com). Repositories have commits with file trees,
 * branches and visibility; tokens can be revoked or lack permissions; every
 * request is recorded (with whether it carried a credential).
 *
 * Health checks of application containers (http://pc-<slug>-<n>:<port>/...) are
 * answered from the commit the container runs: a commit whose files contain
 * "HEALTH" => "fail" answers 500.
 */
class FakeGitHub
{
    /** @var array<string, array{private: bool, default_branch: string, branches: array<string, string>, commits: array<string, array{message: string, author: string, date: string, files: array<string, string>}>, hooks: array<int, array{url: string, secret: string, events: list<string>}>}> */
    public array $repos = [];

    /** @var array<string, array{revoked: bool, repos: list<string>|null, contents: bool, hooks: bool}> */
    public array $tokens = [];

    /** @var list<array{method: string, path: string, token: ?string, status?: int}> */
    public array $requests = [];

    public ?string $lastTarballSha = null;

    public ?int $rateLimitedUntil = null;

    public bool $down = false;

    private int $nextHookId = 100;

    /** @var callable(string): ?string container name → commit sha it runs */
    public $containerCommit;

    public function __construct()
    {
        $this->containerCommit = fn (string $container) => null;
    }

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    public function addRepo(string $fullName, bool $private, string $branch = 'main'): self
    {
        $this->repos[$fullName] = ['private' => $private, 'default_branch' => $branch, 'branches' => [], 'commits' => [], 'hooks' => []];

        return $this;
    }

    /** @param array{repos?: list<string>|null, contents?: bool, hooks?: bool, revoked?: bool} $access */
    public function addToken(string $token, array $access = []): self
    {
        $this->tokens[$token] = ['revoked' => $access['revoked'] ?? false, 'repos' => $access['repos'] ?? null, 'contents' => $access['contents'] ?? true, 'hooks' => $access['hooks'] ?? true];

        return $this;
    }

    /** Create a commit on a branch (fast-forward) and return its SHA. @param array<string, string> $files */
    public function commit(string $fullName, string $branch, array $files, string $message): string
    {
        $sha = bin2hex(random_bytes(20));
        $parent = $this->repos[$fullName]['branches'][$branch] ?? null;
        $tree = $parent ? $this->repos[$fullName]['commits'][$parent]['files'] : [];
        $this->repos[$fullName]['commits'][$sha] = ['message' => $message, 'author' => 'Dev Eloper', 'date' => now()->toIso8601ZuluString(), 'files' => [...$tree, ...$files]];
        $this->repos[$fullName]['branches'][$branch] = $sha;

        return $sha;
    }

    /** @return array<string, string> files of a commit */
    public function files(string $fullName, string $sha): array
    {
        return $this->repos[$fullName]['commits'][$sha]['files'] ?? [];
    }

    /** A GitHub-shaped push payload. @return array<string, mixed> */
    public function pushPayload(string $fullName, string $ref, string $before, string $after, bool $deleted = false): array
    {
        $commit = $this->repos[$fullName]['commits'][$after] ?? null;

        return [
            'ref' => $ref,
            'before' => $before,
            'after' => $after,
            'created' => $before === str_repeat('0', 40),
            'deleted' => $deleted,
            'forced' => false,
            'repository' => ['full_name' => $fullName, 'private' => $this->repos[$fullName]['private'] ?? false],
            'pusher' => ['name' => 'dev'],
            'head_commit' => $commit ? ['id' => $after, 'message' => $commit['message'], 'timestamp' => $commit['date'], 'author' => ['name' => $commit['author']]] : null,
        ];
    }

    /** @return list<array{method: string, path: string, token: ?string}> */
    public function requestsTo(string $pathFragment): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_contains($r['path'], $pathFragment)));
    }

    private function handle(Request $request): PromiseInterface
    {
        $url = $request->url();
        if (preg_match('#^http://(pc-[a-z0-9-]+-\d+):\d+(/.*)?$#', $url, $m)) {
            $sha = ($this->containerCommit)($m[1]);
            foreach ($this->repos as $repo) {
                if ($sha !== null && isset($repo['commits'][$sha])) {
                    return Http::response('ok', ($repo['commits'][$sha]['files']['HEALTH'] ?? 'ok') === 'fail' ? 500 : 200);
                }
            }

            return Http::response('ok', 200);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $auth = $request->header('Authorization')[0] ?? null;
        $token = $auth ? preg_replace('/^(Bearer|token)\s+/i', '', $auth) : null;
        $this->requests[] = ['method' => $request->method(), 'path' => $path.($query ? '?'.$query : ''), 'token' => $token];

        if ($this->down) {
            return $this->json(['message' => 'Server Error'], 502);
        }
        if ($this->rateLimitedUntil !== null && $this->rateLimitedUntil > time()) {
            return $this->json(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $this->rateLimitedUntil]);
        }
        $grant = null;
        if ($token !== null) {
            $grant = $this->tokens[$token] ?? null;
            if ($grant === null || $grant['revoked']) {
                return $this->json(['message' => 'Bad credentials'], 401);
            }
        }

        if ($path === '/user') {
            return $token ? $this->json(['login' => 'dev', 'name' => 'Dev', 'avatar_url' => null], 200, ['X-OAuth-Scopes' => '']) : $this->json(['message' => 'Requires authentication'], 401);
        }
        if ($path === '/user/repos') {
            $list = [];
            foreach ($this->repos as $name => $repo) {
                if ($this->canSee($name, $grant)) {
                    $list[] = ['full_name' => $name, 'private' => $repo['private'], 'default_branch' => $repo['default_branch'], 'description' => null, 'pushed_at' => null];
                }
            }

            return $this->json($token ? $list : []);
        }
        if (! preg_match('#^/repos/([^/]+/[^/]+)(/.*)?$#', $path, $m)) {
            return $this->json(['message' => 'Not Found'], 404);
        }
        $name = $m[1];
        $rest = $m[2] ?? '';
        if (! isset($this->repos[$name]) || ! $this->canSee($name, $grant)) {
            return $this->json(['message' => 'Not Found'], 404);
        }
        $repo = &$this->repos[$name];
        $needsContents = $rest !== '' && ! str_starts_with($rest, '/hooks');
        if ($needsContents && $repo['private'] && $grant !== null && ! $grant['contents']) {
            return $this->json(['message' => 'Resource not accessible by personal access token'], 403);
        }

        if ($rest === '') {
            return $this->json(['full_name' => $name, 'private' => $repo['private'], 'visibility' => $repo['private'] ? 'private' : 'public', 'default_branch' => $repo['default_branch']]);
        }
        if (preg_match('#^/branches/(.+)$#', $rest, $b)) {
            $branch = rawurldecode($b[1]);
            $sha = $repo['branches'][$branch] ?? null;
            if ($sha === null) {
                return $this->json(['message' => 'Branch not found'], 404);
            }

            return $this->json(['name' => $branch, 'commit' => $this->commitJson($repo['commits'][$sha], $sha)]);
        }
        if ($rest === '/branches') {
            return $this->json(array_map(fn ($b) => ['name' => $b], array_keys($repo['branches'])));
        }
        if (preg_match('#^/commits/(.+)$#', $rest, $c)) {
            $ref = rawurldecode($c[1]);
            $sha = $repo['branches'][$ref] ?? $ref;
            if (! isset($repo['commits'][$sha])) {
                return $this->json(['message' => 'No commit found for SHA: '.$ref], 422);
            }

            return $this->json($this->commitJson($repo['commits'][$sha], $sha));
        }
        if (preg_match('#^/tarball/([0-9a-f]{40})$#', $rest, $t)) {
            if (! isset($repo['commits'][$t[1]])) {
                return $this->json(['message' => 'Not Found'], 404);
            }
            $this->lastTarballSha = $t[1];

            $this->requests[array_key_last($this->requests)]['status'] = 200;

            return Http::response('fake-archive-of-'.$t[1], 200);
        }
        if ($rest === '/contents') {
            parse_str($query, $q);
            $sha = $repo['branches'][$q['ref'] ?? $repo['default_branch']] ?? null;

            return $this->json(array_map(fn ($f) => ['name' => $f, 'type' => 'file'], array_keys($sha ? $repo['commits'][$sha]['files'] : [])));
        }
        if (str_starts_with($rest, '/hooks')) {
            if ($grant === null || ! $grant['hooks']) {
                return $this->json(['message' => 'Resource not accessible by personal access token'], 403);
            }
            if ($rest === '/hooks' && $request->method() === 'POST') {
                $id = $this->nextHookId++;
                $repo['hooks'][$id] = ['url' => $request['config']['url'], 'secret' => $request['config']['secret'], 'events' => $request['events']];

                return $this->json(['id' => $id, 'active' => true], 201);
            }
            if (preg_match('#^/hooks/(\d+)$#', $rest, $h)) {
                $id = (int) $h[1];
                if (! isset($repo['hooks'][$id])) {
                    return $this->json(['message' => 'Not Found'], 404);
                }
                if ($request->method() === 'DELETE') {
                    unset($repo['hooks'][$id]);

                    return Http::response('', 204);
                }

                return $this->json(['id' => $id, 'active' => true, 'events' => $repo['hooks'][$id]['events'], 'config' => ['url' => $repo['hooks'][$id]['url']], 'last_response' => ['code' => null, 'status' => 'unused', 'message' => null]]);
            }
        }

        return $this->json(['message' => 'Not Found'], 404);
    }

    /** @param array{revoked: bool, repos: list<string>|null, contents: bool, hooks: bool}|null $grant */
    private function canSee(string $name, ?array $grant): bool
    {
        if (! $this->repos[$name]['private']) {
            return true;
        }

        return $grant !== null && ($grant['repos'] === null || in_array($name, $grant['repos'], true));
    }

    /**
     * @param  array{message: string, author: string, date: string, files: array<string, string>}  $commit
     * @return array<string, mixed>
     */
    private function commitJson(array $commit, string $sha): array
    {
        return ['sha' => $sha, 'commit' => ['message' => $commit['message'], 'author' => ['name' => $commit['author'], 'date' => $commit['date']]], 'author' => ['login' => Str::slug($commit['author'])]];
    }

    /** @param array<string, string> $headers */
    private function json(mixed $data, int $status = 200, array $headers = []): PromiseInterface
    {
        if ($this->requests !== []) {
            $this->requests[array_key_last($this->requests)]['status'] ??= $status;
        }

        return Http::response($data, $status, $headers);
    }
}
