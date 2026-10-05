<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\WebhookEvent;
use App\Services\Deployment\DeploymentService;
use App\Services\Source\GitRefs;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Validates and processes GitHub push webhooks.
 *
 * - The HMAC-SHA256 signature is verified with a constant-time comparison
 *   before the payload is parsed.
 * - Each delivery ID is stored with a unique index, so GitHub redeliveries and
 *   duplicate requests cannot create a second deployment.
 * - Pushes to other branches are ignored.
 */
class WebhookHandler
{
    public function __construct(private readonly DeploymentService $deployments) {}

    /**
     * @param  array<string, string|null>  $headers  lower-cased header names
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(Project $project, string $payload, array $headers): array
    {
        $secret = (string) $project->webhook_secret;
        $signature = (string) ($headers['x-hub-signature-256'] ?? '');
        if ($secret === '' || $signature === '' || ! hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature)) {
            return ['status' => 401, 'body' => ['message' => 'Invalid signature.']];
        }

        $event = (string) ($headers['x-github-event'] ?? '');
        $delivery = (string) ($headers['x-github-delivery'] ?? '');
        if ($delivery === '' || strlen($delivery) > 100) {
            return ['status' => 400, 'body' => ['message' => 'Missing delivery id.']];
        }

        $data = json_decode($payload, true);
        $ref = is_array($data) ? ($data['ref'] ?? null) : null;
        $sha = is_array($data) ? ($data['after'] ?? null) : null;

        try {
            $record = WebhookEvent::query()->create([
                'project_id' => $project->id,
                'provider' => 'github',
                'delivery_id' => $delivery,
                'event' => mb_substr($event, 0, 48),
                'ref' => is_string($ref) ? mb_substr($ref, 0, 255) : null,
                'commit_sha' => is_string($sha) && GitRefs::isValidSha($sha) ? $sha : null,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 200, 'body' => ['status' => 'duplicate', 'message' => 'This delivery was already processed.']];
        }

        $ignore = function (string $reason) use ($record) {
            $record->update(['status' => 'ignored', 'reason' => $reason]);

            return ['status' => 200, 'body' => ['status' => 'ignored', 'message' => $reason]];
        };

        if ($event === 'ping') {
            return $ignore('Webhook connected.');
        }
        if ($event !== 'push') {
            return $ignore("Event \"{$event}\" is not used.");
        }
        if (! is_array($data)) {
            return $ignore('Payload is not valid JSON.');
        }

        $repository = $project->repository;
        if ($repository?->full_name && isset($data['repository']['full_name'])
            && strcasecmp($data['repository']['full_name'], $repository->full_name) !== 0) {
            return $ignore('Push is for a different repository.');
        }
        if ($ref !== 'refs/heads/'.$repository?->branch) {
            return $ignore("Push to {$ref} ignored; this project deploys {$repository?->branch}.");
        }
        if (! empty($data['deleted'])) {
            return $ignore('Branch deletion ignored.');
        }
        if (! is_string($sha) || ! GitRefs::isValidSha($sha)) {
            return $ignore('Push has no commit.');
        }

        $head = $data['head_commit'] ?? [];
        $repository->update([
            'latest_commit_sha' => $sha,
            'latest_commit_message' => isset($head['message']) ? mb_substr(strtok((string) $head['message'], "\n") ?: '', 0, 500) : null,
            'latest_commit_author' => $head['author']['name'] ?? null,
            'latest_commit_at' => $head['timestamp'] ?? now(),
            'last_checked_at' => now(),
        ]);

        if (! $project->auto_deploy) {
            return $ignore('Auto deploy is turned off for this project.');
        }

        try {
            $deployment = $this->deployments->deploy($project, null, 'webhook', $sha);
        } catch (Throwable $e) {
            $record->update(['status' => 'rejected', 'reason' => mb_substr($e->getMessage(), 0, 255)]);

            return ['status' => 409, 'body' => ['status' => 'rejected', 'message' => $e->getMessage()]];
        }
        $record->update(['status' => 'accepted', 'deployment_id' => $deployment->id]);

        return ['status' => 202, 'body' => ['status' => 'queued', 'deployment' => $deployment->number]];
    }
}
