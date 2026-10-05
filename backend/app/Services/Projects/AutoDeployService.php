<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Server\ServerIdentity;
use App\Services\Source\GitHubClient;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns GitHub push webhooks on or off for a project. When the GitHub token has
 * permission the webhook is created automatically; otherwise the dashboard shows
 * the URL and secret to configure it by hand.
 */
class AutoDeployService
{
    public function __construct(
        private readonly ServerIdentity $identity,
        private readonly AuditLogger $audit,
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

        $repository = $project->repository;
        if (! $repository || $repository->provider !== Project::SOURCE_GITHUB) {
            return ['automatic' => false, 'error' => 'Auto deploy works with GitHub repositories. Add the webhook manually for other git hosts.'];
        }
        $url = $this->webhookUrl($project);
        if (! $url) {
            return ['automatic' => false, 'error' => 'Set PC_DASHBOARD_DOMAIN so GitHub can reach this server, then add the webhook.'];
        }
        $github = GitHubClient::forConnection();
        if (! $github->hasToken()) {
            return ['automatic' => false, 'error' => 'Connect GitHub in Settings to create the webhook automatically, or add it manually using the URL and secret shown.'];
        }
        if ($repository->webhook_id) {
            return ['automatic' => true, 'error' => null];
        }
        try {
            $repository->update(['webhook_id' => $github->createWebhook((string) $repository->full_name, $url, (string) $project->webhook_secret)]);

            return ['automatic' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['automatic' => false, 'error' => 'Auto deploy is on, but the webhook could not be created automatically ('.$e->getMessage().'). Add it manually using the URL and secret shown.'];
        }
    }

    public function disable(Project $project): void
    {
        $project->update(['auto_deploy' => false]);
        $repository = $project->repository;
        if ($repository?->webhook_id && $repository->full_name) {
            GitHubClient::forConnection()->deleteWebhook($repository->full_name, (int) $repository->webhook_id);
            $repository->update(['webhook_id' => null]);
        }
        $this->audit->log('project.auto_deploy_disabled', $project);
    }
}
