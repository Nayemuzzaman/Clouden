<?php

namespace App\Services\Projects;

use App\Enums\DeploymentStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Deployment\DeploymentService;
use App\Services\Source\CommitInfo;
use App\Services\Source\GitRefs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Validates and processes GitHub push webhooks: a push to the production
 * branch becomes a deployment of exactly the pushed commit.
 *
 * - The HMAC-SHA256 signature of the raw body is verified with a constant-time
 *   comparison before the payload is parsed. Rejections are written to the
 *   audit log (event, delivery id, reason; never the body, headers or secret),
 *   at most a few per hour per project so forged requests cannot flood it.
 * - Each delivery ID is stored with a unique index, so GitHub redeliveries and
 *   duplicate requests cannot create a second deployment. The SHA-256 of the
 *   signed body is unique per project too, so a captured delivery replayed
 *   with a different (unsigned) delivery ID is rejected as well.
 * - Only "push" events to refs/heads/<production branch> deploy. Other
 *   branches, tags and branch deletions are recorded and ignored.
 * - A push for a commit that is already being deployed reuses that deployment;
 *   a push of the commit production already runs does nothing.
 * - A delivery that arrives after a newer push of the same branch (GitHub does
 *   not guarantee ordering) is ignored, so production never moves backwards.
 * - After an intentional rollback, a delivery for the commit production was
 *   rolled back from does not redeploy it; any NEW commit does.
 */
class WebhookHandler
{
    private const REJECTION_AUDITS_PER_HOUR = 10;

    public function __construct(
        private readonly DeploymentService $deployments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $headers  lower-cased header names
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(Project $project, string $payload, array $headers): array
    {
        $event = mb_substr((string) ($headers['x-github-event'] ?? ''), 0, 48);
        $delivery = (string) ($headers['x-github-delivery'] ?? '');

        $secret = (string) $project->webhook_secret;
        $signature = (string) ($headers['x-hub-signature-256'] ?? '');
        if ($secret === '' || $signature === '' || ! hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature)) {
            $this->auditRejection($project, $event, $delivery, $signature === '' ? 'missing signature' : 'invalid signature');

            return ['status' => 401, 'body' => ['message' => 'Invalid signature.']];
        }
        if ($delivery === '' || strlen($delivery) > 100) {
            return ['status' => 400, 'body' => ['message' => 'Missing delivery id.']];
        }

        $data = json_decode($payload, true);
        $data = is_array($data) ? $data : null;
        $ref = is_string($data['ref'] ?? null) ? mb_substr($data['ref'], 0, 255) : null;
        $sha = $data['after'] ?? null;
        $before = $data['before'] ?? null;
        $repositoryName = is_string($data['repository']['full_name'] ?? null) ? mb_substr($data['repository']['full_name'], 0, 255) : null;

        try {
            $record = WebhookEvent::query()->create([
                'project_id' => $project->id,
                'provider' => 'github',
                'delivery_id' => $delivery,
                'payload_sha256' => hash('sha256', $payload),
                'event' => $event,
                'repository' => $repositoryName,
                'ref' => $ref,
                'before_sha' => is_string($before) && GitRefs::isValidSha($before) ? $before : null,
                'commit_sha' => is_string($sha) && GitRefs::isValidSha($sha) ? $sha : null,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 200, 'body' => ['status' => 'duplicate', 'message' => 'This delivery was already processed.']];
        }

        $repository = $project->repository;
        $repository?->update(['webhook_last_delivery_at' => now(), 'webhook_status' => $repository->webhook_id ? Repository::WEBHOOK_ACTIVE : Repository::WEBHOOK_MANUAL]);

        $ignore = function (string $reason) use ($record) {
            $record->update(['status' => 'ignored', 'reason' => mb_substr($reason, 0, 255)]);

            return ['status' => 200, 'body' => ['status' => 'ignored', 'message' => $reason]];
        };

        if ($event === 'ping') {
            return $ignore('Webhook connected.');
        }
        if ($event !== 'push') {
            return $ignore("Event \"{$event}\" is not used.");
        }
        if ($data === null) {
            return $ignore('Payload is not valid JSON.');
        }
        if ($repository === null) {
            return $ignore('This project does not deploy from a repository.');
        }
        if ($repository->full_name && $repositoryName !== null && strcasecmp($repositoryName, $repository->full_name) !== 0) {
            return $ignore('Push is for a different repository.');
        }
        if (is_string($ref) && str_starts_with($ref, 'refs/tags/')) {
            return $ignore('Tag pushes are not deployed; this project deploys the branch '.$repository->branch.'.');
        }
        if ($ref !== 'refs/heads/'.$repository->branch) {
            return $ignore("Push to {$ref} ignored; this project deploys {$repository->branch}.");
        }
        if (! empty($data['deleted'])) {
            $repository->update(['access_status' => Repository::ACCESS_BRANCH_MISSING, 'last_check_error' => "The branch {$repository->branch} was deleted on GitHub.", 'last_checked_at' => now()]);

            return $ignore("The production branch {$repository->branch} was deleted. Current production keeps running; choose an existing branch in Settings.");
        }
        if (! is_string($sha) || ! GitRefs::isValidSha($sha)) {
            return $ignore('Push has no commit.');
        }

        // GitHub may deliver pushes out of order: if a delivery already received
        // moved the branch away from this commit (and was not the push that
        // this one reverts, i.e. a force-push back), this one is stale.
        $beforeSha = is_string($before) && GitRefs::isValidSha($before) ? $before : null;
        $newer = WebhookEvent::query()->where('project_id', $project->id)->whereKeyNot($record->id)
            ->where('ref', $ref)->where('before_sha', $sha)->whereIn('status', ['accepted', 'ignored', 'duplicate'])
            ->when($beforeSha !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('commit_sha')->orWhere('commit_sha', '!=', $beforeSha)))
            ->exists();
        if ($newer) {
            return $ignore('A newer push to '.$repository->branch.' was already received; this older commit is not deployed.');
        }

        $head = is_array($data['head_commit'] ?? null) ? $data['head_commit'] : [];
        $commit = new CommitInfo(
            sha: $sha,
            message: isset($head['message']) && is_string($head['message']) ? $head['message'] : null,
            author: is_string($head['author']['name'] ?? null) ? $head['author']['name'] : null,
            committedAt: is_string($head['timestamp'] ?? null) ? $head['timestamp'] : null,
        );
        $repository->update([
            'latest_commit_sha' => $sha,
            'latest_commit_message' => $commit->title(),
            'latest_commit_author' => $commit->author,
            'latest_commit_at' => $commit->committedAt ?? now(),
            'last_checked_at' => now(),
            // A push proves the branch exists again; access problems are re-checked by the next fetch.
            'access_status' => $repository->access_status === Repository::ACCESS_BRANCH_MISSING ? Repository::ACCESS_OK : $repository->access_status,
        ]);

        if (! $project->auto_deploy) {
            return $ignore('Auto deploy is turned off for this project.');
        }
        if ($project->rollback_hold_sha === $sha) {
            return $ignore('Production was intentionally rolled back from this commit, so it is not redeployed automatically. Push a new commit or click Deploy Latest.');
        }
        $production = $project->currentDeployment;
        if ($production?->commit_sha === $sha && $production->status === DeploymentStatus::Success && ! $project->hasActiveDeployment()) {
            return $ignore('This commit is already live.');
        }

        try {
            $result = $this->deployments->deployCommit($project, null, 'webhook', $commit, $delivery);
        } catch (Throwable $e) {
            $record->update(['status' => 'rejected', 'reason' => mb_substr($e->getMessage(), 0, 255)]);

            return ['status' => 409, 'body' => ['status' => 'rejected', 'message' => $e->getMessage()]];
        }
        $deployment = $result->deployment;
        if ($result->reused) {
            $record->update(['status' => 'duplicate', 'deployment_id' => $deployment->id, 'reason' => "Commit already being deployed by deployment #{$deployment->number}."]);

            return ['status' => 200, 'body' => ['status' => 'duplicate', 'message' => "Commit already being deployed by deployment #{$deployment->number}.", 'deployment' => $deployment->number]];
        }
        $record->update(['status' => 'accepted', 'deployment_id' => $deployment->id]);

        return ['status' => 202, 'body' => ['status' => 'queued', 'deployment' => $deployment->number]];
    }

    private function auditRejection(Project $project, string $event, string $delivery, string $reason): void
    {
        $key = 'webhook-rejections:'.$project->id;
        if (RateLimiter::tooManyAttempts($key, self::REJECTION_AUDITS_PER_HOUR)) {
            return;
        }
        RateLimiter::hit($key, 3600);
        $this->audit->log('webhook.rejected', $project, 'failure', [
            'project' => $project->slug,
            'event' => preg_replace('/[^a-z_]/', '', strtolower($event)) ?: null,
            'delivery' => preg_match('/^[A-Za-z0-9-]{1,64}$/', $delivery) ? $delivery : null,
            'reason' => $reason,
        ], $project->name);
    }
}
