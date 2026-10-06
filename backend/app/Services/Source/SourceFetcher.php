<?php

namespace App\Services\Source;

use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Process\CommandRunner;
use Illuminate\Support\Facades\File;

/**
 * Resolves the head commit of a project's production branch and materializes
 * an exact commit into a build directory.
 *
 * GitHub repositories are downloaded as the archive of one exact commit through
 * the GitHub API: no git history, no .git directory, and the token travels only
 * in the Authorization header of the API request. Public repositories are read
 * without any credential; the saved token is used for private repositories (and
 * as a fallback when anonymous access is refused or rate limited).
 *
 * Every call records the outcome on the repository (access_status), so the
 * dashboard can say "GitHub connection needs attention" or "branch deleted"
 * without asking GitHub again.
 */
class SourceFetcher
{
    public function __construct(private readonly CommandRunner $runner) {}

    /** Head commit of the production branch; also refreshes visibility and the "latest commit" shown in the UI. */
    public function latestCommit(Repository $repository): CommitInfo
    {
        try {
            if ($repository->isGitHub()) {
                $commit = $this->withGitHub($repository, function (GitHubClient $github) use ($repository) {
                    try {
                        return $github->branch((string) $repository->full_name, $repository->branch);
                    } catch (SourceException $e) {
                        if ($e->kind === SourceException::NOT_FOUND && $this->repositoryVisible($github, $repository)) {
                            throw new SourceException("The branch \"{$repository->branch}\" does not exist in {$repository->full_name}. It may have been deleted or renamed.", SourceException::BRANCH_MISSING);
                        }

                        throw $e;
                    }
                });
            } else {
                $commit = new CommitInfo($this->lsRemote($repository->url, $repository->branch));
            }
        } catch (SourceException $e) {
            $this->recordFailure($repository, $e);

            throw $e;
        }

        $sameCommit = $repository->latest_commit_sha === $commit->sha;
        $repository->update([
            'latest_commit_sha' => $commit->sha,
            'latest_commit_message' => $commit->title() ?? ($sameCommit ? $repository->latest_commit_message : null),
            'latest_commit_author' => $commit->author ?? ($sameCommit ? $repository->latest_commit_author : null),
            'latest_commit_at' => $commit->committedAt ?? ($sameCommit ? $repository->latest_commit_at : null),
            'last_checked_at' => now(),
            'last_check_error' => null,
            'access_status' => Repository::ACCESS_OK,
        ]);

        return $commit;
    }

    /**
     * Verify that the repository is reachable and the branch exists before it
     * is connected to a project. Throws with a message for the administrator.
     *
     * @return array{commit: CommitInfo, visibility: ?string}
     */
    public function verify(string $provider, string $fullNameOrUrl, string $branch): array
    {
        if ($provider !== Project::SOURCE_GITHUB) {
            return ['commit' => new CommitInfo($this->lsRemote($fullNameOrUrl, $branch)), 'visibility' => null];
        }
        $probe = new Repository(['provider' => Project::SOURCE_GITHUB, 'full_name' => $fullNameOrUrl, 'url' => 'https://github.com/'.$fullNameOrUrl.'.git', 'branch' => $branch]);

        return $this->withGitHub($probe, function (GitHubClient $github) use ($fullNameOrUrl, $branch) {
            $visibility = $github->visibility($fullNameOrUrl); // 404 here: repository not found / not authorized
            try {
                $commit = $github->branch($fullNameOrUrl, $branch);
            } catch (SourceException $e) {
                if ($e->kind === SourceException::NOT_FOUND) {
                    throw new SourceException("The branch \"{$branch}\" does not exist in {$fullNameOrUrl}.", SourceException::BRANCH_MISSING);
                }

                throw $e;
            }

            return ['commit' => $commit, 'visibility' => $visibility];
        });
    }

    /**
     * Download exactly $sha into $directory. The commit that was materialized is
     * verified to be $sha; a moving branch reference is never used here.
     *
     * @param  callable(string): void  $log
     */
    public function fetch(Repository $repository, string $sha, string $directory, callable $log): CommitInfo
    {
        if (! GitRefs::isValidSha($sha)) {
            throw new SourceException('Invalid commit SHA.', SourceException::INVALID);
        }
        File::ensureDirectoryExists($directory, 0750);

        try {
            $commit = $repository->isGitHub()
                ? $this->fetchFromGitHub($repository, $sha, $directory, $log)
                : $this->fetchWithGit($repository, $sha, $directory, $log);
        } catch (SourceException $e) {
            $this->recordFailure($repository, $e);

            throw $e;
        }
        if ($commit->sha !== $sha) {
            throw new SourceException("Requested commit {$sha} but received {$commit->sha}; the deployment was stopped.", SourceException::OTHER);
        }

        $token = GithubConnection::current()?->token;
        SourceInspector::inspect($directory, $token ? [$token] : []);
        $repository->update(['access_status' => Repository::ACCESS_OK, 'last_check_error' => null]);

        return $commit;
    }

    /**
     * Run $call against GitHub with the least credential that works: public
     * repositories (and repositories not checked yet) anonymously, falling back
     * to the saved token if GitHub refuses or rate limits anonymous access;
     * repositories known to be private with the saved token.
     *
     * @template T
     *
     * @param  callable(GitHubClient): T  $call
     * @return T
     */
    public function withGitHub(Repository $repository, callable $call): mixed
    {
        $stored = GitHubClient::forConnection();
        // Known-private repositories go straight to the token; public (or not yet known) ones are tried anonymously first.
        $anonymousFirst = ! $stored->hasToken() || $repository->visibility !== 'private';
        if (! $anonymousFirst) {
            return $call($stored);
        }

        try {
            return $call(GitHubClient::anonymous());
        } catch (SourceException $e) {
            if (! $stored->hasToken() || ! in_array($e->kind, [SourceException::NOT_FOUND, SourceException::RATE_LIMITED, SourceException::FORBIDDEN], true)) {
                throw $e;
            }
            $result = $call($stored);
            if ($e->kind === SourceException::NOT_FOUND && $repository->exists) {
                $repository->update(['visibility' => 'private']); // only the token can see it now
            }

            return $result;
        }
    }

    /** Refresh the recorded visibility (public/private) of a GitHub repository. */
    public function refreshVisibility(Repository $repository): ?string
    {
        if (! $repository->isGitHub()) {
            return null;
        }
        $visibility = $this->withGitHub($repository, fn (GitHubClient $github) => $github->visibility((string) $repository->full_name));
        $repository->update(['visibility' => $visibility]);

        return $visibility;
    }

    /** Store why GitHub/git refused, for the dashboard. Never stores credentials (messages are fixed texts). */
    public function recordFailure(Repository $repository, SourceException $e): void
    {
        if (! $repository->exists) {
            return;
        }
        $status = match ($e->kind) {
            SourceException::AUTH => Repository::ACCESS_AUTH_FAILED,
            SourceException::FORBIDDEN => Repository::ACCESS_NO_CONTENTS,
            SourceException::BRANCH_MISSING => Repository::ACCESS_BRANCH_MISSING,
            SourceException::NOT_FOUND => Repository::ACCESS_NO_ACCESS,
            SourceException::RATE_LIMITED => Repository::ACCESS_RATE_LIMITED,
            SourceException::UNAVAILABLE => Repository::ACCESS_UNREACHABLE,
            default => null,
        };
        if ($status === null) {
            return;
        }
        $repository->update(['access_status' => $status, 'last_check_error' => mb_substr($e->getMessage(), 0, 1000), 'last_checked_at' => now()]);
    }

    private function repositoryVisible(GitHubClient $github, Repository $repository): bool
    {
        try {
            $github->repository((string) $repository->full_name);

            return true;
        } catch (SourceException) {
            return false;
        }
    }

    /** @param callable(string): void $log */
    private function fetchFromGitHub(Repository $repository, string $sha, string $directory, callable $log): CommitInfo
    {
        $fullName = (string) $repository->full_name;
        $timeout = (int) config('privatecloud.deploy.clone_timeout');
        $archive = $directory.'.tar.gz';

        return $this->withGitHub($repository, function (GitHubClient $github) use ($fullName, $sha, $directory, $archive, $timeout, $log) {
            $commit = $github->commit($fullName, $sha);
            $log("Downloading {$fullName} at commit {$commit->shortSha()}".($github->hasToken() ? '' : ' (public repository, no credentials used)'));
            try {
                $github->downloadTarball($fullName, $commit->sha, $archive, $timeout);
                File::cleanDirectory($directory);
                $result = $this->runner->run(
                    ['tar', '-xzf', $archive, '-C', $directory, '--strip-components=1', '--no-same-owner', '--no-same-permissions'],
                    timeout: $timeout,
                );
                if (! $result->successful()) {
                    throw new SourceException('Could not extract the repository archive: '.mb_substr(trim($result->errorOutput), 0, 500));
                }
            } finally {
                File::delete($archive);
            }

            return $commit;
        });
    }

    /** @param callable(string): void $log */
    private function fetchWithGit(Repository $repository, string $sha, string $directory, callable $log): CommitInfo
    {
        $error = GitRefs::validateGitUrl($repository->url, (bool) config('privatecloud.deploy.allow_insecure_git'));
        if ($error !== null) {
            throw new SourceException($error, SourceException::INVALID);
        }
        if (! GitRefs::isValidBranch($repository->branch)) {
            throw new SourceException('Invalid branch name.', SourceException::INVALID);
        }

        $timeout = (int) config('privatecloud.deploy.clone_timeout');
        // No credential helper, no LFS smudging, no prompts: public repositories only.
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_LFS_SKIP_SMUDGE' => '1'];
        $git = [...self::gitBase(), '-c', 'core.symlinks=true'];

        $log("Cloning {$repository->url} (branch {$repository->branch}) to check out commit ".substr($sha, 0, 7));
        $runClone = fn (array $depth) => $this->runner->run(
            [...$git, 'clone', ...$depth, '--branch', $repository->branch, '--single-branch', '--no-tags', '--', $repository->url, $directory],
            env: $env,
            timeout: $timeout,
            onLine: fn (string $s, string $line) => $line !== '' ? $log($line) : null,
        );
        $clone = $runClone(['--depth', '50']);
        if (! $clone->successful() && str_contains($clone->errorOutput, 'shallow')) {
            // Some servers (e.g. git's "dumb" HTTP transport) cannot serve shallow clones.
            $log('The server does not support shallow clones; downloading the full history');
            File::deleteDirectory($directory);
            $clone = $runClone([]);
        }
        if (! $clone->successful()) {
            throw $this->describeGitError($clone->errorOutput, $clone->timedOut);
        }

        $checkout = $this->runner->run([...$git, 'checkout', '--detach', $sha], cwd: $directory, env: $env, timeout: 60);
        if (! $checkout->successful()) {
            // Not within the last 50 commits of the branch (or force-pushed away): fetch it directly.
            $fetch = $this->runner->run([...$git, 'fetch', '--depth', '1', 'origin', $sha], cwd: $directory, env: $env, timeout: $timeout);
            $checkout = $fetch->successful()
                ? $this->runner->run([...$git, 'checkout', '--detach', $sha], cwd: $directory, env: $env, timeout: 60)
                : $fetch;
            if (! $checkout->successful()) {
                throw new SourceException("Commit {$sha} is not available in the remote repository.", SourceException::NOT_FOUND);
            }
        }

        $info = $this->runner->run([...$git, 'log', '-1', '--format=%H%x00%an%x00%cI%x00%B'], cwd: $directory, env: $env, timeout: 30);
        [$commitSha, $author, $date, $message] = array_pad(explode("\0", trim($info->output), 4), 4, null);
        File::deleteDirectory($directory.'/.git');

        return new CommitInfo((string) $commitSha, $message !== null ? trim($message) : null, $author, $date);
    }

    private function lsRemote(string $url, string $branch): string
    {
        $error = GitRefs::validateGitUrl($url, (bool) config('privatecloud.deploy.allow_insecure_git'));
        if ($error !== null) {
            throw new SourceException($error, SourceException::INVALID);
        }
        if (! GitRefs::isValidBranch($branch)) {
            throw new SourceException('Invalid branch name.', SourceException::INVALID);
        }

        $result = $this->runner->run(
            [...self::gitBase(), 'ls-remote', '--heads', '--', $url, 'refs/heads/'.$branch],
            env: ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1'],
            timeout: 60,
        );
        if (! $result->successful()) {
            throw $this->describeGitError($result->errorOutput, $result->timedOut);
        }
        if (! preg_match('/^([0-9a-f]{40})\s/m', $result->output, $m)) {
            throw new SourceException("The branch \"{$branch}\" was not found in the repository.", SourceException::BRANCH_MISSING);
        }

        return $m[1];
    }

    /**
     * git with every transport disabled except https (plus http in development).
     * git applies the same allow-list to HTTP redirects, so a repository URL cannot
     * redirect the clone to http://, file:// or another transport; system and
     * credential-helper configuration is ignored.
     *
     * @return list<string>
     */
    public static function gitBase(): array
    {
        $base = ['git', '-c', 'protocol.allow=never', '-c', 'protocol.https.allow=always', '-c', 'credential.helper=', '-c', 'http.sslVerify=true'];
        if (config('privatecloud.deploy.allow_insecure_git')) {
            array_push($base, '-c', 'protocol.http.allow=always');
        }

        return $base;
    }

    private function describeGitError(string $stderr, bool $timedOut): SourceException
    {
        if ($timedOut) {
            return new SourceException('Timed out while downloading the repository.', SourceException::UNAVAILABLE);
        }
        $stderr = trim($stderr);

        return match (true) {
            str_contains($stderr, 'Remote branch') && str_contains($stderr, 'not found') => new SourceException('The branch does not exist in the repository.', SourceException::BRANCH_MISSING),
            str_contains($stderr, 'Authentication failed'), str_contains($stderr, 'could not read Username') => new SourceException('The repository requires authentication. Use the GitHub source with a connected GitHub account for private repositories.', SourceException::AUTH),
            str_contains($stderr, 'not found'), str_contains($stderr, 'does not exist') => new SourceException('Repository not found. Check the URL.', SourceException::NOT_FOUND),
            str_contains($stderr, 'Could not resolve host') => new SourceException('The repository host could not be resolved. Check the URL and the server\'s DNS.', SourceException::UNAVAILABLE),
            default => new SourceException('Git failed: '.mb_substr($stderr, -500)),
        };
    }
}
