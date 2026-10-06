<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use App\Models\WebhookEvent;
use App\Services\Deployment\DeploymentPipeline;
use App\Services\Deployment\HealthChecker;
use App\Services\Process\CommandResult;
use App\Services\Source\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeGitHub;
use Tests\TestCase;

/**
 * GitHub production branch → live deployment, against a simulated GitHub
 * (tests/Fakes/FakeGitHub: an in-memory API model, not github.com), the fake
 * Docker daemon and real Caddy site files. The real-Docker, real-git version of
 * these scenarios is .github/ci/github-flow-test.sh.
 */
class GitHubMainToLiveTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'github_pat_11FAKE0TEST0TOKEN0abcdefghijklmnopqrstuvwxyz0123456789';

    private const ZERO = '0000000000000000000000000000000000000000';

    private FakeGitHub $github;

    /** @var list<string> every build context scanned for the token */
    private array $buildContexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['privatecloud.deploy.drain_seconds' => 0]);
        $this->app->instance(HealthChecker::class, new HealthChecker($this->docker, fn () => null));

        $this->github = (new FakeGitHub)
            ->addRepo('acme/public-app', private: false)
            ->addRepo('acme/private-app', private: true)
            ->addToken(self::TOKEN)
            ->install();
        $this->github->containerCommit = fn (string $c) => Deployment::query()->where('container_name', $c)->value('commit_sha');
        foreach (['acme/public-app', 'acme/private-app'] as $repo) {
            $this->github->commit($repo, 'main', self::app('A'), 'Version A');
        }
        GithubConnection::query()->create(['account_login' => 'dev', 'token' => self::TOKEN, 'token_type' => 'fine_grained']);

        // "tar -xzf archive -C dir": materialize the exact commit GitHub served.
        $this->runner->onBinary('tar', function (array $cmd) {
            $dir = $cmd[array_search('-C', $cmd, true) + 1];
            foreach ($this->github->repos as $repo) {
                foreach ($repo['commits'][$this->github->lastTarballSha]['files'] ?? [] as $path => $content) {
                    File::ensureDirectoryExists(dirname($dir.'/'.$path));
                    File::put($dir.'/'.$path, $content);
                }
            }

            return new CommandResult(0, '', '');
        });
        // "docker build": fails for Dockerfiles containing "RUN false".
        $this->runner->onBinary('docker', function (array $cmd, ?callable $onLine) {
            $tag = $cmd[array_search('--tag', $cmd, true) + 1];
            $context = end($cmd);
            $this->buildContexts[] = $this->treeContents($context);
            if (str_contains((string) File::get($cmd[array_search('--file', $cmd, true) + 1]), 'RUN false')) {
                foreach (['#5 [2/2] RUN false', '#5 ERROR: process "/bin/sh -c false" did not complete successfully: exit code: 1'] as $line) {
                    $onLine('err', $line);
                }

                return new CommandResult(1, '', '');
            }
            $this->docker->addImage($tag);

            return new CommandResult(0, '', '');
        });
    }

    /** @return array<string, string> files of a tiny application at a given version */
    private static function app(string $version, array $extra = []): array
    {
        return ['Dockerfile' => "FROM busybox\nCMD [\"httpd\", \"-f\"]\n", 'index.html' => "version {$version}", ...$extra];
    }

    private function createProject(string $repo, bool $autoDeploy = true): Project
    {
        $this->actingAsAdmin();
        $slug = $this->postJson('/api/v1/projects', [
            'name' => Str::after($repo, '/'), 'source_type' => 'github', 'repository' => $repo, 'branch' => 'main',
            'auto_deploy' => $autoDeploy, 'domain' => Str::after($repo, '/').'.example.com', 'port' => 3000, 'health_check_path' => '/health',
            'volumes' => [['name' => 'data', 'mount_path' => '/data']],
            'environment' => [['key' => 'APP_GREETING', 'value' => 'hello'], ['key' => 'APP_SECRET', 'value' => 'app-secret-value-42', 'is_secret' => true]],
        ])->assertCreated()->json('data.slug');

        return Project::query()->where('slug', $slug)->firstOrFail();
    }

    /** Commit to the fake repository and deliver GitHub's push webhook. @return array{0: string, 1: TestResponse} */
    private function push(Project $project, array $files, string $message, string $branch = 'main'): array
    {
        $repo = (string) $project->repository->full_name;
        $before = $this->github->repos[$repo]['branches'][$branch] ?? self::ZERO;
        $sha = $this->github->commit($repo, $branch, $files, $message);

        return [$sha, $this->webhook($project, $this->github->pushPayload($repo, 'refs/heads/'.$branch, $before, $sha))];
    }

    private function webhook(Project $project, array $payload, string $event = 'push', ?string $delivery = null, ?string $secret = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $delivery ?? (string) Str::uuid(),
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? (string) $project->fresh()->webhook_secret),
            'Content-Type' => 'application/json',
        ];

        return $this->call('POST', "/api/v1/webhooks/github/{$project->uuid}", [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function liveSha(Project $project): ?string
    {
        return $project->fresh()->currentDeployment?->commit_sha;
    }

    private function sync(Project $project): array
    {
        return $this->getJson("/api/v1/projects/{$project->slug}")->assertOk()->json('data.sync');
    }

    private function treeContents(string $dir): string
    {
        $out = '';
        foreach (File::allFiles($dir, true) as $file) {
            $out .= $file->getRelativePathname()."\n".$file->getContents()."\n";
        }

        return $out;
    }

    /** The acceptance flow of the specification, for a public and a private repository. */
    private function acceptanceFlow(string $repo): Project
    {
        $project = $this->createProject($repo);
        $this->assertTrue($project->auto_deploy);
        $this->assertNotNull($project->repository->webhook_id, 'webhook registered through the API');
        $a = $project->repository->latest_commit_sha;
        $this->assertSame('out_of_sync', $this->sync($project)['state']);

        // Initial deployment of the current head of main (A).
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202)->assertJsonPath('data.commit.sha', $a);
        $this->assertSame($a, $this->liveSha($project));
        $this->assertSame('synced', $this->sync($project)['state']);

        // git push B → webhook → B live.
        [$b, $response] = $this->push($project, self::app('B'), 'Version B');
        $response->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertSame($b, $this->liveSha($project));
        $this->assertSame('synced', $this->sync($project)['state']);

        // Broken commit C: build fails, B stays live, status FAILED.
        [$c] = $this->push($project, ['Dockerfile' => "FROM busybox\nRUN false\n"], 'Broken C');
        $this->assertSame($b, $this->liveSha($project));
        $sync = $this->sync($project);
        $this->assertSame('failed', $sync['state']);
        $this->assertSame($c, $sync['desired']['sha']);
        $this->assertSame($b, $sync['production']['sha']);
        $this->assertSame('building', $sync['failed']['stage']);
        $this->assertStringContainsString('Your previous production version is still running', $sync['message']);

        // Valid D deploys.
        [$d] = $this->push($project, self::app('D'), 'Version D');
        $this->assertSame($d, $this->liveSha($project));

        // Rollback to B: B live, main stays D, intentionally rolled back.
        $bDeployment = Deployment::query()->where('project_id', $project->id)->where('commit_sha', $b)->where('status', 'success')->firstOrFail();
        $this->postJson("/api/v1/projects/{$project->slug}/deployments/{$bDeployment->id}/rollback")->assertStatus(202);
        $this->assertSame($b, $this->liveSha($project));
        $sync = $this->sync($project);
        $this->assertSame('out_of_sync', $sync['state']);
        $this->assertTrue($sync['rolled_back']);
        $this->assertSame($d, $sync['desired']['sha']);

        // A replayed/redelivered push of D must not undo the rollback.
        $replay = $this->github->pushPayload($repo, 'refs/heads/main', $c, $d);
        $replay['pusher'] = ['name' => 'redelivery'];
        $this->webhook($project, $replay)->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame($b, $this->liveSha($project));

        // Push E: auto deploy resumes.
        [$e] = $this->push($project, self::app('E'), 'Version E');
        $this->assertSame($e, $this->liveSha($project));
        $sync = $this->sync($project);
        $this->assertSame('synced', $sync['state']);
        $this->assertFalse($sync['rolled_back']);

        return $project;
    }

    // ------------------------------------------------------------- acceptance

    public function test_acceptance_flow_public_repository(): void
    {
        $project = $this->acceptanceFlow('acme/public-app');

        // Public: the code was read without any credential; only webhook management used the token.
        foreach ([...$this->github->requestsTo('/tarball/'), ...$this->github->requestsTo('/commits/'), ...$this->github->requestsTo('/branches/')] as $request) {
            $this->assertNull($request['token'], "{$request['path']} was sent with a credential");
        }
        $this->assertNotEmpty($this->github->requestsTo('/hooks'));
        $this->assertSame(self::TOKEN, $this->github->requestsTo('/hooks')[0]['token']);
        $this->assertSame('public', $project->fresh()->repository->visibility);
        $this->assertSame('public', $project->deployments()->latest('number')->first()->source_visibility);
    }

    public function test_acceptance_flow_private_repository(): void
    {
        $project = $this->acceptanceFlow('acme/private-app');

        // Only the very first check (visibility still unknown) went out anonymously, and got 404.
        $requests = $this->github->requestsTo('/repos/acme/private-app');
        $anonymous = array_filter($requests, fn ($r) => $r['token'] === null);
        $this->assertNotEmpty($requests);
        $this->assertCount(1, $anonymous);
        $this->assertSame(404, array_values($anonymous)[0]['status']);
        foreach ($requests as $request) {
            if (($request['status'] ?? 0) < 300) {
                $this->assertSame(self::TOKEN, $request['token'], "{$request['path']} answered without the token");
            }
        }
        $this->assertSame('private', $project->fresh()->repository->visibility);
        $this->assertCredentialNeverLeaked($project);
    }

    private function assertCredentialNeverLeaked(Project $project): void
    {
        $haystacks = [
            'build contexts' => implode("\n", $this->buildContexts),
            'commands' => json_encode($this->runner->commands),
            'containers' => json_encode($this->docker->containers),
            'images' => json_encode($this->docker->images),
            'deployment logs' => DB::table('deployment_logs')->pluck('line')->implode("\n"),
            'deployments' => json_encode(DB::table('deployments')->get()),
            'webhook events' => json_encode(DB::table('webhook_events')->get()),
            'audit log' => json_encode(DB::table('audit_logs')->get()),
            'repositories' => json_encode(DB::table('repositories')->get()),
            'notifications' => json_encode(DB::table('notifications')->get()),
        ];
        foreach (["/api/v1/projects/{$project->slug}", "/api/v1/projects/{$project->slug}/deployments", '/api/v1/settings',
            "/api/v1/projects/{$project->slug}/webhook", "/api/v1/projects/{$project->slug}/production", '/api/v1/audit-logs', '/api/v1/github/repositories'] as $url) {
            $haystacks["GET {$url}"] = $this->getJson($url)->assertOk()->getContent();
        }
        foreach ($haystacks as $where => $content) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $content, "GitHub token found in {$where}");
            if ($where !== 'containers') { // the application's own secret variable belongs in its container environment only
                $this->assertStringNotContainsString('app-secret-value-42', (string) $content, "secret variable found in {$where}");
            }
        }
        $this->assertNotEmpty($this->buildContexts);
    }

    // ------------------------------------------------------- exact commits

    public function test_each_deployment_builds_exactly_the_commit_it_records(): void
    {
        $project = $this->createProject('acme/private-app');
        Bus::fake([RunDeployment::class]);

        [$a] = $this->push($project, self::app('A2'), 'A2');
        $deployment = Deployment::query()->where('commit_sha', $a)->firstOrFail();
        // main moves on before the job runs (no webhook delivered yet)
        $b = $this->github->commit('acme/private-app', 'main', self::app('B2'), 'B2');

        app(DeploymentPipeline::class)->run($deployment);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Success, $deployment->status);
        $this->assertSame($a, $deployment->commit_sha);
        $this->assertSame($a, $this->github->lastTarballSha, 'archive of exactly A downloaded');
        $this->assertSame([], $this->github->requestsTo('/tarball/'.$b));
        $this->assertSame($a, $this->docker->images[$deployment->image_tag]['Config']['Labels']['privatecloud.commit'] ?? $a);
        $this->assertStringContainsString('version A2', end($this->buildContexts));
        $this->assertSame('acme/private-app', $deployment->repository);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame('A2', $deployment->commit_message);
        $this->assertNotNull($deployment->webhook_delivery_id);
    }

    public function test_rapid_pushes_build_the_right_commits_and_end_on_the_newest(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        Bus::fake([RunDeployment::class]);

        [$b] = $this->push($project, self::app('B'), 'B');
        $bDeployment = Deployment::query()->where('commit_sha', $b)->firstOrFail();
        $c = $d = null;
        // While B is building, C and D are pushed.
        $this->runner->prepend(function (array $cmd) use (&$c) {
            return basename($cmd[0]) === 'docker' && $c === null;
        }, function (array $cmd, ?callable $onLine) use ($project, &$c, &$d) {
            [$c] = $this->push($project, self::app('C'), 'C');
            [$d] = $this->push($project, self::app('D'), 'D');
            $this->docker->addImage($cmd[array_search('--tag', $cmd, true) + 1]);

            return new CommandResult(0, '', '');
        });
        app(DeploymentPipeline::class)->run($bDeployment);

        $this->assertSame($b, $this->liveSha($project), 'B finished and went live');
        $cDeployment = Deployment::query()->where('commit_sha', $c)->firstOrFail();
        $this->assertSame(DeploymentStatus::Superseded, $cDeployment->status, 'C was replaced by D before it started');
        $this->assertSame([], $this->github->requestsTo('/tarball/'.$c), 'C never fetched');

        // The worker picks up what is still waiting: exactly one deployment, for D.
        $waiting = Deployment::query()->where('project_id', $project->id)->where('status', 'queued')->get();
        $this->assertCount(1, $waiting);
        $this->assertSame($d, $waiting[0]->commit_sha);
        app(DeploymentPipeline::class)->run($waiting[0]);

        $this->assertSame($d, $this->liveSha($project));
        $this->assertSame('synced', $this->sync($project)['state']);
        foreach (Deployment::query()->where('status', 'success')->get() as $done) {
            $this->assertStringContainsString('privatecloud.commit='.$done->commit_sha, implode(' ', collect($this->runner->commandsFor('docker'))->first(fn ($cmd) => in_array($done->image_tag, $cmd, true)) ?? []));
        }
    }

    public function test_manual_deploy_latest_during_auto_deploy_reuses_the_running_deployment(): void
    {
        $project = $this->createProject('acme/public-app');
        Bus::fake([RunDeployment::class]);
        [$sha] = $this->push($project, self::app('X'), 'X');

        // Same commit requested manually while the webhook deployment waits / runs.
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202)
            ->assertJsonPath('data.commit.sha', $sha)->assertJsonPath('meta.reused', true);
        $deployment = Deployment::query()->where('commit_sha', $sha)->sole();
        $this->runner->prepend(fn (array $cmd) => basename($cmd[0]) === 'docker' && in_array('build', $cmd, true) && ! isset($this->docker->images[$cmd[array_search('--tag', $cmd, true) + 1]]), function (array $cmd) use ($project, $sha) {
            $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202)->assertJsonPath('meta.reused', true)->assertJsonPath('data.commit.sha', $sha);
            $this->docker->addImage($cmd[array_search('--tag', $cmd, true) + 1]);

            return new CommandResult(0, '', '');
        });
        app(DeploymentPipeline::class)->run($deployment);

        $this->assertSame(1, Deployment::query()->where('commit_sha', $sha)->count(), 'same SHA never deployed twice');
        $this->assertSame($sha, $this->liveSha($project));
    }

    public function test_deploy_latest_explains_when_production_is_already_current(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);

        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(409)
            ->assertJsonPath('code', 'up_to_date')
            ->assertJsonPath('commit.sha', $this->liveSha($project));
        $this->assertSame(1, $project->deployments()->count());

        // Rebuild on purpose, or redeploy with the current settings.
        $this->postJson("/api/v1/projects/{$project->slug}/deployments", ['force' => true])->assertStatus(202);
        $this->postJson("/api/v1/projects/{$project->slug}/redeploy")->assertStatus(202);
        $this->assertSame(3, $project->deployments()->where('status', 'success')->count());
    }

    // ------------------------------------------------------------ webhooks

    public function test_only_pushes_to_the_production_branch_deploy(): void
    {
        $project = $this->createProject('acme/public-app');
        foreach (['develop', 'feature/test', 'release/test', 'bugfix/x'] as $branch) {
            [, $response] = $this->push($project, self::app($branch), "on {$branch}", $branch);
            $response->assertOk()->assertJsonPath('status', 'ignored');
        }
        $tag = $this->github->pushPayload('acme/public-app', 'refs/tags/v1.0.0', self::ZERO, $project->repository->latest_commit_sha);
        $this->webhook($project, $tag)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Tag pushes are not deployed'));
        $this->assertSame(0, Deployment::query()->count());

        [$sha] = $this->push($project, self::app('main'), 'on main');
        $this->assertSame($sha, $this->liveSha($project));
        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_duplicate_and_redelivered_webhooks_create_one_deployment(): void
    {
        $project = $this->createProject('acme/public-app');
        $before = $project->repository->latest_commit_sha;
        $sha = $this->github->commit('acme/public-app', 'main', self::app('Q'), 'Q');
        $payload = $this->github->pushPayload('acme/public-app', 'refs/heads/main', $before, $sha);

        $this->webhook($project, $payload, delivery: 'delivery-1')->assertStatus(202);
        $this->webhook($project, $payload, delivery: 'delivery-1')->assertOk()->assertJsonPath('status', 'duplicate');
        $this->webhook($project, $payload, delivery: 'delivery-redelivered')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Deployment::query()->where('commit_sha', $sha)->count());
    }

    public function test_forged_webhooks_are_rejected_and_audited_without_secrets(): void
    {
        $project = $this->createProject('acme/public-app');
        $sha = $this->github->commit('acme/public-app', 'main', self::app('evil'), 'evil');
        $payload = $this->github->pushPayload('acme/public-app', 'refs/heads/main', self::ZERO, $sha);

        for ($i = 0; $i < 15; $i++) {
            $this->webhook($project, $payload, delivery: "forged-{$i}", secret: 'not-the-secret')->assertStatus(401);
        }

        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, WebhookEvent::query()->count(), 'unauthenticated payloads are not stored');
        $audits = AuditLog::query()->where('action', 'webhook.rejected')->get();
        $this->assertCount(10, $audits, 'at most 10 rejection entries per project and hour');
        $this->assertSame('invalid signature', $audits[0]->metadata['reason']);
        $this->assertSame('forged-0', $audits[0]->metadata['delivery']);
        $stored = json_encode($audits);
        $this->assertStringNotContainsString((string) $project->fresh()->webhook_secret, $stored);
        $this->assertStringNotContainsString($sha, $stored, 'payload content is not stored');
    }

    public function test_out_of_order_delivery_never_moves_production_backwards(): void
    {
        $project = $this->createProject('acme/public-app');
        $a = $project->repository->latest_commit_sha;
        $b = $this->github->commit('acme/public-app', 'main', self::app('B'), 'B');
        $c = $this->github->commit('acme/public-app', 'main', self::app('C'), 'C');

        // GitHub delivers C's push before B's.
        $this->webhook($project, $this->github->pushPayload('acme/public-app', 'refs/heads/main', $b, $c))->assertStatus(202);
        $this->webhook($project, $this->github->pushPayload('acme/public-app', 'refs/heads/main', $a, $b))->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'newer push'));

        $this->assertSame($c, $this->liveSha($project));
        $this->assertSame($c, $project->repository->fresh()->latest_commit_sha);

        // A force-push back to B is a new push and deploys B.
        $this->webhook($project, [...$this->github->pushPayload('acme/public-app', 'refs/heads/main', $c, $b), 'forced' => true])->assertStatus(202);
        $this->assertSame($b, $this->liveSha($project));
    }

    public function test_deleted_production_branch_keeps_production_and_asks_for_a_branch(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $live = $this->liveSha($project);

        $this->webhook($project, $this->github->pushPayload('acme/public-app', 'refs/heads/main', $live, self::ZERO, deleted: true))
            ->assertOk()->assertJsonPath('status', 'ignored');
        unset($this->github->repos['acme/public-app']['branches']['main']);

        $this->assertSame($live, $this->liveSha($project));
        $this->assertSame('branch_missing', $project->repository->fresh()->access_status);
        $this->assertStringContainsString('Current production remains online', $this->sync($project)['attention_message']);
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('code', 'source_branch_missing');
        $this->assertTrue($this->docker->isRunning($project->fresh()->currentDeployment->container_name));
    }

    // ----------------------------------------------------------- rollback

    public function test_rollback_is_respected_until_a_new_push_or_deploy_latest(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        [$b] = $this->push($project, self::app('B'), 'B');
        [$c] = $this->push($project, self::app('C'), 'C');
        $bDeployment = Deployment::query()->where('commit_sha', $b)->firstOrFail();

        $this->postJson("/api/v1/projects/{$project->slug}/deployments/{$bDeployment->id}/rollback")->assertStatus(202);
        $project->refresh();
        $this->assertSame($b, $this->liveSha($project));
        $this->assertSame($c, $project->rollback_hold_sha);
        $this->assertNotNull($project->rolled_back_at);

        // Deploy Latest is an explicit choice: it returns to C and clears the hold.
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202)->assertJsonPath('data.commit.sha', $c);
        $this->assertSame($c, $this->liveSha($project));
        $this->assertNull($project->fresh()->rollback_hold_sha);
        $this->assertSame('synced', $this->sync($project)['state']);
    }

    // ------------------------------------------------- failures keep production

    public function test_failed_health_check_leaves_no_candidate_and_keeps_production(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $live = $project->fresh()->currentDeployment;

        [$e] = $this->push($project, self::app('E', ['HEALTH' => 'fail']), 'E builds but is unhealthy');

        $failed = Deployment::query()->where('commit_sha', $e)->firstOrFail();
        $this->assertSame(DeploymentStatus::Failed, $failed->status);
        $this->assertSame('health_checking', $failed->failure_stage);
        $this->assertNotNull($failed->build_finished_at, 'the image was built');
        $this->assertArrayNotHasKey((string) $failed->container_name, $this->docker->containers, 'candidate removed');
        $this->assertSame([$live->container_name], array_keys(array_filter($this->docker->containers, fn ($c) => ($c['Config']['Labels']['privatecloud.project'] ?? null) === (string) $project->id)));
        $this->assertSame($live->commit_sha, $this->liveSha($project));
        $this->assertStringContainsString('reverse_proxy '.$live->container_name.':3000', File::get(config('privatecloud.caddy.sites_dir').'/'.$project->slug.'.caddy'));
        $this->assertSame('failed', $this->sync($project)['state']);
    }

    public function test_code_deployments_never_touch_volumes_and_always_apply_the_environment(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        [$b] = $this->push($project, self::app('B'), 'B');
        $this->push($project, self::app('C'), 'C');
        $bDeployment = Deployment::query()->where('commit_sha', $b)->firstOrFail();
        $this->postJson("/api/v1/projects/{$project->slug}/deployments/{$bDeployment->id}/rollback")->assertStatus(202);

        $volume = $project->volumes()->sole()->docker_name;
        $mounts = [];
        foreach ($this->docker->calls as $call) {
            $this->assertStringStartsNotWith('removeVolume', $call);
        }
        foreach (Deployment::query()->whereNotNull('container_name')->where('status', 'success')->get() as $deployment) {
            $mounts[] = $deployment->container_name;
        }
        $this->assertCount(4, $mounts);
        $this->assertArrayHasKey($volume, $this->docker->volumes, 'one persistent volume, created once');
        $container = $this->docker->containers[$project->fresh()->currentDeployment->container_name];
        $this->assertContains('APP_GREETING=hello', $container['Config']['Env']);
        $this->assertContains($volume.':/data', $container['HostConfig']['Binds'] ?? array_map(fn ($m) => $m['Source'].':'.$m['Target'], $container['HostConfig']['Mounts'] ?? []));
    }

    // --------------------------------------------------- source safety

    public function test_submodules_lfs_and_committed_tokens_are_refused_with_clear_messages(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $live = $this->liveSha($project);

        [$s] = $this->push($project, ['.gitmodules' => "[submodule \"lib\"]\n\tpath = vendor/lib\n\turl = https://github.com/acme/lib.git\n"], 'add submodule');
        $this->assertStringContainsString('Git submodules ("vendor/lib")', Deployment::query()->where('commit_sha', $s)->value('failure_reason'));

        [$l] = $this->push($project, ['.gitmodules' => '', '.gitattributes' => "*.bin filter=lfs diff=lfs merge=lfs -text\n", 'model.bin' => "version https://git-lfs.github.com/spec/v1\noid sha256:abc\nsize 123\n"], 'add lfs');
        $this->assertStringContainsString('Git LFS', Deployment::query()->where('commit_sha', $l)->value('failure_reason'));

        [$t] = $this->push($project, ['.gitattributes' => '', 'model.bin' => 'real', 'config.js' => 'const token = "'.self::TOKEN.'"'], 'oops');
        $failure = (string) Deployment::query()->where('commit_sha', $t)->value('failure_reason');
        $this->assertStringContainsString('contains the GitHub access token', $failure);
        $this->assertStringNotContainsString(self::TOKEN, $failure);

        $this->assertSame($live, $this->liveSha($project));
        $this->assertSame(0, Deployment::query()->whereIn('commit_sha', [$s, $l, $t])->whereNotNull('build_started_at')->count(), 'nothing was built');
    }

    // ------------------------------------------------ GitHub access problems

    public function test_revoked_token_fails_safely_backs_off_and_recovers_after_reconnect(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $live = $this->liveSha($project);

        $this->github->tokens[self::TOKEN]['revoked'] = true;
        [$b] = $this->push($project, self::app('B'), 'B');
        $failed = Deployment::query()->where('commit_sha', $b)->firstOrFail();
        $this->assertSame('cloning', $failed->failure_stage);
        $this->assertStringContainsString('expired or revoked', $failed->failure_reason);
        $this->assertSame($live, $this->liveSha($project));
        $this->assertSame('auth_failed', $project->repository->fresh()->access_status);
        $this->assertSame('auth_failed', $this->sync($project)['attention']);

        // No hammering: while backing off, nothing is sent to GitHub.
        $sent = count($this->github->requests);
        $this->push($project, self::app('B2'), 'B2');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('code', 'source_auth');
        $this->assertSame($sent, count($this->github->requests));

        // Reconnect with a new token: deploys again.
        $this->github->addToken('github_pat_11FAKE0NEW0TOKEN');
        $this->withConfirmedPassword()->postJson('/api/v1/settings/github', ['token' => 'github_pat_11FAKE0NEW0TOKEN'])->assertOk();
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $this->assertSame($this->github->repos['acme/private-app']['branches']['main'], $this->liveSha($project));
        $this->assertSame('ok', $project->repository->fresh()->access_status);
    }

    public function test_check_connection_reports_and_clears_a_rejected_token(): void
    {
        $this->actingAsAdmin();
        $this->github->tokens[self::TOKEN]['revoked'] = true;
        $this->postJson('/api/v1/settings/github/check')->assertStatus(422)->assertJsonPath('code', 'source_auth');
        $this->github->tokens[self::TOKEN]['revoked'] = false;
        $this->postJson('/api/v1/settings/github/check')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/api/v1/settings')->assertJsonPath('github.token_rejected_at', null)->assertJsonPath('github.token_type', 'fine_grained');
    }

    public function test_token_without_contents_permission_or_repository_access(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $live = $this->liveSha($project);
        $this->github->commit('acme/private-app', 'main', self::app('N'), 'N');

        $this->github->tokens[self::TOKEN]['contents'] = false;
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)
            ->assertJsonPath('code', 'source_forbidden')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '"Contents: Read"'));
        $this->assertSame('no_contents_permission', $project->repository->fresh()->access_status);

        $this->github->tokens[self::TOKEN] = ['revoked' => false, 'repos' => ['acme/other'], 'contents' => true, 'hooks' => true];
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('code', 'source_not_found');
        $this->assertSame('no_access', $project->repository->fresh()->access_status);
        $this->assertStringContainsString('can no longer access acme/private-app', $this->sync($project)['attention_message']);
        $this->assertSame($live, $this->liveSha($project));
    }

    public function test_rate_limit_pauses_github_requests_until_reset(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->github->rateLimitedUntil = time() + 600;

        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('code', 'source_rate_limited');
        $sent = count($this->github->requests);
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(422)->assertJsonPath('code', 'source_rate_limited');
        $this->postJson("/api/v1/projects/{$project->slug}/refresh-commit")->assertStatus(422);
        $this->assertSame($sent, count($this->github->requests), 'no requests while rate limited');
        $this->assertNotNull(GitHubClient::rateLimitedUntil(true));

        $this->github->rateLimitedUntil = null;
        $this->travel(11)->minutes();
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
    }

    public function test_public_repository_made_private_and_back(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);

        // Made private: anonymous access gets 404, the saved token still works; recorded as private.
        $this->github->repos['acme/public-app']['private'] = true;
        [$b] = $this->push($project, self::app('B'), 'B');
        $this->assertSame($b, $this->liveSha($project));
        $this->assertSame('private', $project->repository->fresh()->visibility);

        // Made public again: detected on refresh, and the code is read without credentials again.
        $this->github->repos['acme/public-app']['private'] = false;
        $this->postJson("/api/v1/projects/{$project->slug}/refresh-commit")->assertOk();
        $this->assertSame('public', $project->repository->fresh()->visibility);
        $requests = count($this->github->requests);
        [$c] = $this->push($project, self::app('C'), 'C');
        $this->assertSame($c, $this->liveSha($project));
        foreach (array_slice($this->github->requests, $requests) as $request) {
            $this->assertNull($request['token'], "{$request['path']} sent with a credential");
        }
    }

    public function test_private_repository_without_any_token_explains_how_to_connect(): void
    {
        GithubConnection::query()->delete();
        $this->actingAsAdmin();
        $this->postJson('/api/v1/projects', ['name' => 'Secret', 'source_type' => 'github', 'repository' => 'acme/private-app', 'branch' => 'main'])
            ->assertJsonValidationErrors(['repository' => 'Private repositories require connecting GitHub']);
    }

    // ----------------------------------------- repository connection changes

    public function test_changing_repository_or_branch_is_verified_and_moves_the_webhook(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $oldHook = $project->repository->fresh()->webhook_id;
        $this->github->repos['acme/public-app']['hooks'][1] = ['url' => 'https://ci.example.com/hook', 'secret' => 'x', 'events' => ['push']];
        $live = $this->liveSha($project);

        $this->patchJson("/api/v1/projects/{$project->slug}", ['branch' => 'nope'])->assertJsonValidationErrors(['branch' => 'does not exist']);
        $this->patchJson("/api/v1/projects/{$project->slug}", ['repository' => 'acme/missing'])->assertJsonValidationErrors('repository');
        $this->assertSame('acme/public-app', $project->repository->fresh()->full_name);

        $this->patchJson("/api/v1/projects/{$project->slug}", ['repository' => 'acme/private-app'])->assertOk()
            ->assertJsonPath('data.repository.full_name', 'acme/private-app')
            ->assertJsonPath('data.repository.visibility', 'private')
            ->assertJsonPath('warnings', []);
        $this->assertArrayNotHasKey($oldHook, $this->github->repos['acme/public-app']['hooks'], 'our old webhook removed');
        $this->assertArrayHasKey(1, $this->github->repos['acme/public-app']['hooks'], 'other webhooks untouched');
        $this->assertCount(1, $this->github->repos['acme/private-app']['hooks'], 'webhook created on the new repository');
        $this->assertSame($live, $this->liveSha($project), 'production keeps running until the next deployment');
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.repository_changed']);
    }

    public function test_webhook_is_recreated_when_deleted_in_github_and_orphans_are_reported(): void
    {
        $project = $this->createProject('acme/public-app');
        $hook = $project->repository->fresh()->webhook_id;
        $this->github->repos['acme/public-app']['hooks'] = [1 => ['url' => 'https://ci.example.com/hook', 'secret' => 'x', 'events' => ['push']]];

        $this->postJson("/api/v1/projects/{$project->slug}/webhook/check")->assertOk();
        $newHook = $project->repository->fresh()->webhook_id;
        $this->assertNotSame($hook, $newHook);
        $this->assertArrayHasKey($newHook, $this->github->repos['acme/public-app']['hooks']);

        // GitHub down while turning auto deploy off: the webhook is recorded as orphaned.
        $this->github->down = true;
        $this->putJson("/api/v1/projects/{$project->slug}/auto-deploy", ['enabled' => false])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, "webhook #{$newHook}") && str_contains($m, 'could not be removed'));
        $repository = $project->repository->fresh();
        $this->assertSame(Repository::WEBHOOK_ORPHANED, $repository->webhook_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'github.webhook_orphaned']);
        $this->assertArrayHasKey(1, $this->github->repos['acme/public-app']['hooks']);
    }

    public function test_webhook_endpoint_records_delivery_status(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->webhook($project, ['zen' => 'Keep it logically awesome.'], 'ping')->assertOk();
        $this->getJson("/api/v1/projects/{$project->slug}/webhook")->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('recent_events.0.event', 'ping')
            ->assertJsonPath('last_delivery_at', fn ($v) => $v !== null);
    }

    // ------------------------------------------ reconciliation & recovery

    public function test_production_check_detects_and_repairs_a_wrong_route(): void
    {
        $project = $this->createProject('acme/public-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        $site = config('privatecloud.caddy.sites_dir').'/'.$project->slug.'.caddy';
        $good = File::get($site);
        File::put($site, str_replace($project->fresh()->currentDeployment->container_name.':3000', 'pc-public-app-99:3000', $good));

        $this->getJson("/api/v1/projects/{$project->slug}/production")->assertOk()->assertJsonPath('findings.0.code', 'route_mismatch');
        $this->artisan('privatecloud:production-status', ['project' => $project->slug])->assertExitCode(1);

        // While the project's deployment lock is held, the reconciler leaves routing alone.
        $lock = Cache::lock(Project::lockName($project->id), 60);
        $lock->get();
        $this->artisan('privatecloud:reconcile')->assertExitCode(0);
        $this->assertStringContainsString('pc-public-app-99', File::get($site));
        $lock->release();

        $this->artisan('privatecloud:reconcile')->assertExitCode(0);
        $this->assertSame($good, File::get($site));
        $this->artisan('privatecloud:production-status', ['project' => $project->slug])->assertExitCode(0);
    }

    public function test_worker_restart_recovers_a_claimed_deployment_and_keeps_the_github_link(): void
    {
        $project = $this->createProject('acme/private-app');
        $this->postJson("/api/v1/projects/{$project->slug}/deployments")->assertStatus(202);
        Bus::fake([RunDeployment::class]);
        [$b] = $this->push($project, self::app('B'), 'B');
        // The worker claimed it and died before the first stage.
        Deployment::query()->where('commit_sha', $b)->update(['started_at' => now()]);
        Cache::lock(Project::lockName($project->id), 3600)->get();

        $this->artisan('privatecloud:recover-interrupted', ['queue' => 'deployments'])->assertExitCode(0);

        $this->assertSame(DeploymentStatus::Failed, Deployment::query()->where('commit_sha', $b)->firstOrFail()->status);
        $this->assertTrue(Cache::lock(Project::lockName($project->id), 10)->get(), 'project lock released');
        $repository = $project->repository->fresh();
        $this->assertSame(['acme/private-app', 'main', true], [$repository->full_name, $repository->branch, $project->fresh()->auto_deploy]);
        $this->assertNotNull($repository->webhook_id);
    }

    public function test_job_waits_while_another_deployment_of_the_project_is_running(): void
    {
        $project = $this->createProject('acme/public-app');
        $running = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Building, 'started_at' => now()]);
        $waiting = Deployment::factory()->create(['project_id' => $project->id, 'status' => DeploymentStatus::Queued, 'number' => $running->number + 1]);

        $job = new RunDeployment($waiting->id, $project->id);
        $fakeJob = new class extends Job implements \Illuminate\Contracts\Queue\Job
        {
            public ?int $releasedFor = null;

            public function release($delay = 0)
            {
                $this->releasedFor = $delay;
            }

            public function getJobId()
            {
                return '1';
            }

            public function getRawBody()
            {
                return '{}';
            }

            public function attempts()
            {
                return 1;
            }
        };
        $job->setJob($fakeJob);
        $job->handle(app(DeploymentPipeline::class));

        $this->assertSame(10, $fakeJob->releasedFor);
        $this->assertSame(DeploymentStatus::Queued, $waiting->fresh()->status);
        $this->assertNull($waiting->fresh()->started_at);
    }
}
