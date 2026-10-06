<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Repository;
use App\Services\Audit\AuditLogger;
use App\Services\Notifier;
use App\Services\Server\ServerIdentity;
use App\Services\Source\GitHubClient;
use App\Services\Source\SourceException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns GitHub push webhooks on or off for a project. When the GitHub token has
 * permission ("Webhooks: Read and write") the webhook is created automatically
 * and its id is stored; otherwise the dashboard shows the URL and secret to
 * configure it by hand.
 *
 * Only the webhook PrivateCloud created (by its id) is ever modified or deleted.
 * If it cannot be deleted, it is recorded as orphaned (audit log + notification)
 * so the administrator can remove it in GitHub; it only receives 401/404 replies.
 */
class AutoDeployService
{
    public function __construct(
        private readonly ServerIdentity $identity,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function webhookUrl(Project $project): ?string
    {
        $base = $this->identity->webhookBaseUrl();

        return $base ? $base.'/api/v1/webhooks/github/'.$project->uuid : null;
    }

    /** @return array{automatic: bool, error: ?string} */
    public function enable(Project $project): array
    {
        if (! $project->webhook_secret) {
            $project->webhook_secret = Str::random(40);
        }
        $project->auto_deploy = true;
        $project->save();
        $this->audit->log('project.auto_deploy_enabled', $project);

        return $this->ensureWebhook($project);
    }

    /**
     * Make sure the GitHub webhook of a project with auto deploy exists and points
     * at this server. Creates it when missing (also when it was deleted in GitHub).
     *
     * @return array{automatic: bool, error: ?string}
     */
    public function ensureWebhook(Project $project): array
    {
        $repository = $project->repository;
        if (! $repository || ! $repository->isGitHub()) {
            return ['automatic' => false, 'error' => 'Auto deploy works with GitHub repositories. Add the webhook manually for other git hosts.'];
        }
        $url = $this->webhookUrl($project);
        if (! $url) {
            return $this->manual($repository, 'Set PC_DASHBOARD_DOMAIN so GitHub can reach this server, then add the webhook.');
        }
        $github = GitHubClient::forConnection();
        if (! $github->hasToken()) {
            return $this->manual($repository, 'Connect GitHub in Settings to create the webhook automatically, or add it manually using the URL and secret shown.');
        }

        try {
            if ($repository->webhook_id) {
                $existing = $github->webhook((string) $repository->full_name, (int) $repository->webhook_id);
                if ($existing !== null && $existing['url'] === $url) {
                    $repository->update(['webhook_status' => Repository::WEBHOOK_ACTIVE, 'webhook_error' => null, 'webhook_url' => $url]);

                    return ['automatic' => true, 'error' => null];
                }
                if ($existing !== null) {
                    $github->deleteWebhook((string) $repository->full_name, (int) $repository->webhook_id); // ours, but for an old address
                }
            }
            $id = $github->createWebhook((string) $repository->full_name, $url, (string) $project->webhook_secret);
            $repository->update(['webhook_id' => $id, 'webhook_status' => Repository::WEBHOOK_ACTIVE, 'webhook_error' => null, 'webhook_url' => $url]);
            $this->audit->log('github.webhook_created', $project, metadata: ['repository' => $repository->full_name, 'hook_id' => $id]);

            return ['automatic' => true, 'error' => null];
        } catch (Throwable $e) {
            $message = $e instanceof SourceException ? $e->getMessage() : 'unexpected error';
            $repository->update(['webhook_status' => Repository::WEBHOOK_FAILED, 'webhook_error' => mb_substr($message, 0, 1000)]);

            return ['automatic' => false, 'error' => 'Auto deploy is on, but the webhook could not be created automatically ('.$message.'). Add it manually using the URL and secret shown.'];
        }
    }

    /** @return ?string a warning when the GitHub webhook could not be removed */
    public function disable(Project $project): ?string
    {
        $project->update(['auto_deploy' => false]);
        $warning = $this->removeWebhook($project);
        $this->audit->log('project.auto_deploy_disabled', $project);

        return $warning;
    }

    /**
     * Delete the webhook PrivateCloud created for this project's repository.
     *
     * @return ?string a warning when it could not be removed (recorded as orphaned)
     */
    public function removeWebhook(Project $project, ?Repository $repository = null): ?string
    {
        $repository ??= $project->repository;
        if (! $repository?->webhook_id || ! $repository->full_name) {
            $repository?->update(['webhook_status' => null, 'webhook_error' => null]);

            return null;
        }
        $hookId = (int) $repository->webhook_id;
        try {
            GitHubClient::forConnection()->deleteWebhook($repository->full_name, $hookId);
            $repository->update(['webhook_id' => null, 'webhook_status' => null, 'webhook_error' => null, 'webhook_url' => null]);
            $this->audit->log('github.webhook_deleted', $project, metadata: ['repository' => $repository->full_name, 'hook_id' => $hookId]);

            return null;
        } catch (Throwable $e) {
            return $this->recordOrphan($project, $repository, $hookId, $e);
        }
    }

    public function recordOrphan(Project $project, Repository $repository, int $hookId, Throwable $e): string
    {
        $reason = $e instanceof SourceException ? $e->getMessage() : 'unexpected error';
        $warning = "The GitHub webhook #{$hookId} of {$repository->full_name} could not be removed ({$reason}). Delete it in the repository's Settings → Webhooks; until then it only receives error replies.";
        $repository->update(['webhook_status' => Repository::WEBHOOK_ORPHANED, 'webhook_error' => mb_substr($warning, 0, 1000)]);
        $this->audit->log('github.webhook_orphaned', $project, 'failure', ['repository' => $repository->full_name, 'hook_id' => $hookId]);
        $this->notifier->notify('github.webhook_orphaned', 'GitHub webhook not removed', $warning, 'warning', "/projects/{$project->slug}/settings");

        return $warning;
    }

    /** @return array{automatic: bool, error: ?string} */
    private function manual(Repository $repository, string $message): array
    {
        if (! $repository->webhook_id) {
            $repository->update(['webhook_status' => Repository::WEBHOOK_MANUAL, 'webhook_error' => null]);
        }

        return ['automatic' => false, 'error' => $message];
    }
}
