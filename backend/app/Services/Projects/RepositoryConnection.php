<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Audit\AuditLogger;
use App\Services\Source\CommitInfo;
use App\Services\Source\SourceException;
use App\Services\Source\SourceFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Connects a project to a repository and production branch, or changes them
 * safely on an existing project:
 *  - the repository must be readable and the branch must exist (checked first;
 *    nothing changes when GitHub says no);
 *  - deployments still waiting for the old source are superseded, the live
 *    deployment keeps running until the next successful deployment;
 *  - an intentional-rollback hold is cleared (it referred to the old branch);
 *  - when the repository changes, PrivateCloud's webhook moves with it.
 */
class RepositoryConnection
{
    public function __construct(
        private readonly SourceFetcher $fetcher,
        private readonly AutoDeployService $autoDeploy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Check a repository/branch before it is connected. Definitive refusals
     * (not found, no access, branch missing, token rejected) throw a validation
     * error; GitHub being unreachable or rate limited returns a warning instead.
     *
     * @return array{commit: ?CommitInfo, visibility: ?string, warning: ?string}
     */
    public function check(string $provider, string $source, string $branch): array
    {
        try {
            $result = $this->fetcher->verify($provider, $source, $branch);

            return [...$result, 'warning' => null];
        } catch (SourceException $e) {
            if ($e->isTransient()) {
                return ['commit' => null, 'visibility' => null, 'warning' => 'The repository could not be checked right now: '.$e->getMessage()];
            }
            $field = $e->kind === SourceException::BRANCH_MISSING ? 'branch' : ($provider === Project::SOURCE_GITHUB ? 'repository' : 'repository_url');

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /**
     * @param  array{repository?: string, repository_url?: string, branch?: string}  $changes
     * @return list<string> warnings for the administrator
     */
    public function change(Project $project, array $changes): array
    {
        $repository = $project->repository;
        if ($repository === null) {
            return [];
        }
        $isGitHub = $repository->isGitHub();
        $newFullName = $isGitHub ? ($changes['repository'] ?? $repository->full_name) : null;
        $newUrl = $isGitHub ? 'https://github.com/'.$newFullName.'.git' : ($changes['repository_url'] ?? $repository->url);
        $newBranch = $changes['branch'] ?? $repository->branch;

        $repositoryChanged = $isGitHub ? strcasecmp((string) $newFullName, (string) $repository->full_name) !== 0 : $newUrl !== $repository->url;
        if (! $repositoryChanged && $newBranch === $repository->branch) {
            return [];
        }

        $check = $this->check($repository->provider, $isGitHub ? (string) $newFullName : $newUrl, $newBranch);
        $warnings = $check['warning'] ? [$check['warning']] : [];
        $old = ['repository' => $repository->displayName(), 'branch' => $repository->branch];
        $oldRepository = $repositoryChanged ? $repository->replicate() : null;
        $oldHookId = $repository->webhook_id;

        DB::transaction(function () use ($project, $repository, $newFullName, $newUrl, $newBranch, $check, $repositoryChanged) {
            $commit = $check['commit'];
            $repository->update([
                'full_name' => $newFullName,
                'url' => $newUrl,
                'branch' => $newBranch,
                'visibility' => $check['visibility'] ?? ($repositoryChanged ? null : $repository->visibility),
                'latest_commit_sha' => $commit?->sha,
                'latest_commit_message' => $commit?->title(),
                'latest_commit_author' => $commit?->author,
                'latest_commit_at' => $commit?->committedAt,
                'last_checked_at' => $commit ? now() : null,
                'last_check_error' => null,
                'access_status' => $commit ? Repository::ACCESS_OK : null,
                ...($repositoryChanged ? ['webhook_id' => null, 'webhook_status' => null, 'webhook_error' => null, 'webhook_url' => null] : []),
            ]);
            $project->update(['rolled_back_at' => null, 'rollback_hold_sha' => null]);
            Deployment::query()->where('project_id', $project->id)->where('type', Deployment::TYPE_DEPLOY)
                ->where('status', DeploymentStatus::Queued->value)->whereNull('started_at')
                ->update(['status' => DeploymentStatus::Superseded->value, 'finished_at' => now(), 'failure_reason' => 'The repository or production branch changed before it started.']);
        });

        if ($repositoryChanged && $oldRepository !== null && $oldHookId) {
            $oldRepository->webhook_id = $oldHookId;
            if (($warning = $this->autoDeploy->removeWebhook($project, $oldRepository)) !== null) {
                $warnings[] = $warning;
            }
        }
        if ($repositoryChanged && $project->auto_deploy) {
            $result = $this->autoDeploy->ensureWebhook($project->fresh());
            if ($result['error']) {
                $warnings[] = $result['error'];
            }
        }

        $this->audit->log('project.repository_changed', $project, metadata: [
            'from' => $old, 'to' => ['repository' => $isGitHub ? $newFullName : $newUrl, 'branch' => $newBranch],
        ]);

        return $warnings;
    }
}
