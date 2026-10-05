<?php

namespace App\Services\Source;

use App\Models\Project;
use App\Models\Repository;
use App\Services\Process\CommandRunner;
use Illuminate\Support\Facades\File;

/**
 * Resolves the latest commit of a project's branch and materializes an exact
 * commit into a build directory.
 */
class SourceFetcher
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function latestCommit(Repository $repository): CommitInfo
    {
        if ($repository->provider === Project::SOURCE_GITHUB) {
            return GitHubClient::forConnection()->commit((string) $repository->full_name, $repository->branch);
        }

        return new CommitInfo($this->lsRemote($repository->url, $repository->branch));
    }

    /**
     * Download the given commit (or the branch head when $sha is null) into $directory.
     *
     * @param  callable(string): void  $log
     */
    public function fetch(Repository $repository, ?string $sha, string $directory, callable $log): CommitInfo
    {
        File::ensureDirectoryExists($directory, 0750);

        return $repository->provider === Project::SOURCE_GITHUB
            ? $this->fetchFromGitHub($repository, $sha, $directory, $log)
            : $this->fetchWithGit($repository, $sha, $directory, $log);
    }

    /** @param callable(string): void $log */
    private function fetchFromGitHub(Repository $repository, ?string $sha, string $directory, callable $log): CommitInfo
    {
        $github = GitHubClient::forConnection();
        $commit = $github->commit((string) $repository->full_name, $sha ?? $repository->branch);
        $log("Resolved {$repository->full_name}@{$repository->branch} to commit {$commit->shortSha()}");

        $archive = $directory.'.tar.gz';
        try {
            $log('Downloading source archive from GitHub');
            $github->downloadTarball((string) $repository->full_name, $commit->sha, $archive, (int) config('privatecloud.deploy.clone_timeout'));
            $result = $this->runner->run(
                ['tar', '-xzf', $archive, '-C', $directory, '--strip-components=1', '--no-same-owner'],
                timeout: (int) config('privatecloud.deploy.clone_timeout'),
            );
            if (! $result->successful()) {
                throw new SourceException('Could not extract the repository archive: '.trim($result->errorOutput));
            }
        } finally {
            File::delete($archive);
        }

        return $commit;
    }

    /** @param callable(string): void $log */
    private function fetchWithGit(Repository $repository, ?string $sha, string $directory, callable $log): CommitInfo
    {
        $error = GitRefs::validateGitUrl($repository->url, (bool) config('privatecloud.deploy.allow_insecure_git'));
        if ($error !== null) {
            throw new SourceException($error);
        }
        if (! GitRefs::isValidBranch($repository->branch)) {
            throw new SourceException('Invalid branch name.');
        }

        $timeout = (int) config('privatecloud.deploy.clone_timeout');
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1'];
        $git = ['git', '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never', '-c', 'core.symlinks=true'];

        $log("Cloning {$repository->url} (branch {$repository->branch})");
        $clone = $this->runner->run(
            [...$git, 'clone', '--depth', '50', '--branch', $repository->branch, '--single-branch', '--no-tags', '--', $repository->url, $directory],
            env: $env,
            timeout: $timeout,
            onLine: fn (string $s, string $line) => $line !== '' ? $log($line) : null,
        );
        if (! $clone->successful()) {
            throw new SourceException($this->describeGitError($clone->errorOutput, $clone->timedOut));
        }

        if ($sha !== null) {
            if (! GitRefs::isValidSha($sha)) {
                throw new SourceException('Invalid commit SHA.');
            }
            $checkout = $this->runner->run([...$git, 'checkout', '--detach', $sha], cwd: $directory, env: $env, timeout: 60);
            if (! $checkout->successful()) {
                $fetch = $this->runner->run([...$git, 'fetch', '--depth', '1', 'origin', $sha], cwd: $directory, env: $env, timeout: $timeout);
                $checkout = $fetch->successful()
                    ? $this->runner->run([...$git, 'checkout', '--detach', $sha], cwd: $directory, env: $env, timeout: 60)
                    : $fetch;
                if (! $checkout->successful()) {
                    throw new SourceException("Commit {$sha} is not available in the remote repository.");
                }
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
            throw new SourceException($error);
        }
        if (! GitRefs::isValidBranch($branch)) {
            throw new SourceException('Invalid branch name.');
        }

        $result = $this->runner->run(
            ['git', '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never', 'ls-remote', '--heads', '--', $url, 'refs/heads/'.$branch],
            env: ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1'],
            timeout: 60,
        );
        if (! $result->successful()) {
            throw new SourceException($this->describeGitError($result->errorOutput, $result->timedOut));
        }
        if (! preg_match('/^([0-9a-f]{40})\s/m', $result->output, $m)) {
            throw new SourceException("Branch \"{$branch}\" was not found in the repository.");
        }

        return $m[1];
    }

    private function describeGitError(string $stderr, bool $timedOut): string
    {
        if ($timedOut) {
            return 'Timed out while downloading the repository.';
        }
        $stderr = trim($stderr);

        return match (true) {
            str_contains($stderr, 'Remote branch') && str_contains($stderr, 'not found') => 'The branch does not exist in the repository.',
            str_contains($stderr, 'Authentication failed'), str_contains($stderr, 'could not read Username') => 'The repository requires authentication. Use the GitHub source with a connected GitHub account for private repositories.',
            str_contains($stderr, 'not found'), str_contains($stderr, 'does not exist') => 'Repository not found. Check the URL.',
            str_contains($stderr, 'Could not resolve host') => 'The repository host could not be resolved. Check the URL and the server\'s DNS.',
            default => 'Git failed: '.mb_substr($stderr, -500),
        };
    }
}
