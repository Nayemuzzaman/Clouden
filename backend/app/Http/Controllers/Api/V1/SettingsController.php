<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GithubConnection;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\Backups\BackupStorage;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Server\ServerIdentity;
use App\Services\Source\GitHubClient;
use App\Services\Source\GitRefs;
use App\Services\Source\SourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(ServerIdentity $identity, BackupStorage $storage): JsonResponse
    {
        $github = GithubConnection::current();

        return response()->json([
            'name' => config('privatecloud.name'),
            'dashboard_domain' => config('privatecloud.dashboard_domain'),
            'webhook_base_url' => $identity->webhookBaseUrl(),
            'public_ipv4' => $identity->publicIpv4(),
            'https' => config('privatecloud.caddy.auto_https') !== 'off',
            'thresholds' => MetricsCollector::thresholds(),
            'metrics_retention_days' => (int) config('privatecloud.monitoring.retention_days'),
            'backup_storage' => ['driver' => $storage->name(), 'off_server' => $storage->isOffServer()],
            'github' => $github ? [
                'connected' => true,
                'login' => $github->account_login,
                'name' => $github->account_name,
                'avatar_url' => $github->avatar_url,
                'scopes' => $github->scopes,
                'last_verified_at' => $github->last_verified_at?->toIso8601String(),
                'token_type' => $github->token_type,
                'token_rejected_at' => Setting::get(GitHubClient::TOKEN_REJECTED_SETTING),
                'rate_limited_until' => ($until = GitHubClient::rateLimitedUntil(true)) ? gmdate('c', $until) : null,
            ] : ['connected' => false],
        ]);
    }

    public function updateThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cpu' => ['required', 'integer', 'min:10', 'max:100'],
            'memory' => ['required', 'integer', 'min:10', 'max:100'],
            'disk' => ['required', 'integer', 'min:10', 'max:100'],
        ]);
        Setting::put('monitoring.thresholds', $data);
        $this->audit->log('settings.thresholds_updated', metadata: $data);

        return response()->json(['thresholds' => MetricsCollector::thresholds()]);
    }

    public function updateServer(Request $request): JsonResponse
    {
        $data = $request->validate(['public_ipv4' => ['nullable', 'ipv4'], 'public_ipv6' => ['nullable', 'ipv6']]);
        Server::local()->update($data);
        $this->audit->log('settings.server_updated', metadata: $data);

        return response()->json(['message' => 'Saved.']);
    }

    public function connectGithub(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9_]+$/']]);
        try {
            $user = (new GitHubClient($data['token']))->authenticatedUser();
        } catch (Throwable $e) {
            $this->audit->log('github.connect', null, 'failure');

            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        GithubConnection::query()->delete();
        GitHubClient::clearBackoff();
        GithubConnection::query()->create([
            'user_id' => $request->user()->id,
            'account_login' => $user['login'],
            'account_name' => $user['name'],
            'avatar_url' => $user['avatar_url'],
            'token' => $data['token'],
            'token_type' => str_starts_with($data['token'], 'github_pat_') ? 'fine_grained' : 'classic',
            'scopes' => $user['scopes'],
            'last_verified_at' => now(),
        ]);
        Cache::forget('privatecloud:github-repos');
        Setting::query()->whereKey(GitHubClient::TOKEN_REJECTED_SETTING)->delete();
        // Access problems caused by the old token are re-checked with the new one on the next fetch.
        Repository::query()->whereIn('access_status', [Repository::ACCESS_AUTH_FAILED, Repository::ACCESS_NO_ACCESS, Repository::ACCESS_NO_CONTENTS])
            ->update(['access_status' => null, 'last_check_error' => null]);
        $this->audit->log('github.connect', null, 'success', ['login' => $user['login']], $user['login']);

        return response()->json(['connected' => true, 'login' => $user['login']]);
    }

    /** "Check connection": verify the saved token now (ignoring the back-off after a rejection). */
    public function checkGithub(): JsonResponse
    {
        $connection = GithubConnection::current();
        if ($connection === null) {
            return response()->json(['message' => 'GitHub is not connected.'], 422);
        }
        try {
            $user = GitHubClient::forConnectionCheck()->authenticatedUser();
        } catch (SourceException $e) {
            $this->audit->log('github.check', null, 'failure', ['reason' => $e->kind]);

            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'code' => 'source_'.$e->kind], 422);
        }
        $connection->update(['last_verified_at' => now(), 'scopes' => $user['scopes'], 'account_name' => $user['name'], 'avatar_url' => $user['avatar_url']]);
        Setting::query()->whereKey(GitHubClient::TOKEN_REJECTED_SETTING)->delete();
        GitHubClient::clearBackoff();
        Repository::query()->where('access_status', Repository::ACCESS_AUTH_FAILED)->update(['access_status' => null, 'last_check_error' => null]);
        $this->audit->log('github.check', null, 'success', ['login' => $user['login']], $user['login']);

        return response()->json(['ok' => true, 'login' => $user['login'], 'message' => 'GitHub accepted the saved token ('.$user['login'].').']);
    }

    public function disconnectGithub(): JsonResponse
    {
        GithubConnection::query()->delete();
        GitHubClient::clearBackoff();
        Cache::forget('privatecloud:github-repos');
        Setting::query()->whereKey(GitHubClient::TOKEN_REJECTED_SETTING)->delete();
        $this->audit->log('github.disconnect');

        return response()->json(['connected' => false]);
    }

    public function repositories(Request $request): JsonResponse
    {
        if ($request->boolean('refresh')) {
            Cache::forget('privatecloud:github-repos');
        }
        $client = GitHubClient::forConnection();
        if (! $client->hasToken()) {
            return response()->json(['data' => [], 'connected' => false]);
        }
        $repos = Cache::remember('privatecloud:github-repos', 300, fn () => $client->repositories());

        return response()->json(['data' => $repos, 'connected' => true]);
    }

    public function branches(string $owner, string $repo): JsonResponse
    {
        $fullName = $owner.'/'.$repo;
        if (! GitRefs::isValidFullName($fullName)) {
            throw ValidationException::withMessages(['repository' => 'Invalid repository name.']);
        }
        $client = GitHubClient::forConnection();
        $info = $client->repository($fullName);

        return response()->json([
            'default_branch' => $info['default_branch'] ?? 'main',
            'private' => (bool) ($info['private'] ?? false),
            'branches' => $client->branches($fullName),
        ]);
    }
}
