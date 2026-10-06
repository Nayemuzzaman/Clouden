<?php

namespace App\Services\Domains;

use App\Jobs\CheckDomain;
use App\Models\Domain;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Docker\DockerClient;
use App\Services\Notifier;
use App\Services\Routing\CaddyConfigurator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DomainService
{
    public function __construct(
        private readonly CaddyConfigurator $caddy,
        private readonly DnsChecker $dns,
        private readonly CertificateChecker $certificates,
        private readonly DockerClient $docker,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function add(Project $project, string $input): Domain
    {
        $hostname = DomainValidator::normalize($input);
        if ($hostname === null) {
            throw new DomainException('Enter a valid domain name such as app.example.com (no http://, paths, ports or wildcards).');
        }
        if ($violation = DomainValidator::policyViolation($hostname)) {
            throw new DomainException($violation);
        }
        if (Domain::query()->where('hostname', $hostname)->exists()) {
            throw new DomainException('This domain is already used by a project.');
        }

        $domain = DB::transaction(fn () => $project->domains()->create([
            'hostname' => $hostname,
            'is_primary' => ! $project->domains()->exists(),
            'cert_status' => $this->httpsEnabled() ? 'pending' : 'disabled',
        ]));

        try {
            $this->caddy->sync();
        } catch (Throwable $e) {
            $domain->delete();
            $this->audit->log('domain.added', $project, 'failure', ['hostname' => $hostname], $project->name);

            throw new DomainException('The domain could not be added to the web server: '.$e->getMessage());
        }

        $this->refreshDns($domain);
        $this->audit->log('domain.added', $domain, metadata: ['project' => $project->slug], label: $hostname);
        CheckDomain::dispatch($domain->id)->delay(now()->addSeconds(30));

        return $domain->fresh();
    }

    public function remove(Domain $domain): void
    {
        $project = $domain->project;
        $wasPrimary = $domain->is_primary;
        $domain->delete();
        if ($wasPrimary && ($next = $project->domains()->oldest()->first())) {
            $next->update(['is_primary' => true]);
        }
        $this->caddy->sync();
        $this->audit->log('domain.removed', $project, metadata: ['hostname' => $domain->hostname], label: $domain->hostname);
    }

    public function makePrimary(Domain $domain): void
    {
        DB::transaction(function () use ($domain) {
            $domain->project->domains()->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);
        });
    }

    public function refreshDns(Domain $domain): Domain
    {
        $result = $this->dns->check($domain->hostname);
        $domain->update(['dns_status' => $result['status'], 'dns_records' => $result['records'], 'dns_checked_at' => now()]);

        return $domain;
    }

    /** Full check: DNS + certificate. Called by the scheduler and the "Check now" button. */
    public function check(Domain $domain): Domain
    {
        $this->refreshDns($domain);
        if (! $this->httpsEnabled()) {
            $domain->update(['cert_status' => 'disabled', 'cert_error' => null, 'cert_checked_at' => now()]);

            return $domain->fresh();
        }

        $previous = $domain->cert_status;
        $result = $this->certificates->check($domain->hostname);
        if ($result['valid']) {
            $domain->update(['cert_status' => 'active', 'cert_error' => null, 'cert_expires_at' => $result['expires_at'], 'cert_checked_at' => now()]);
        } else {
            $caddyError = $this->caddyCertificateError($domain->hostname);
            $age = $domain->created_at?->diffInMinutes(now()) ?? 0;
            // Give ACME time before calling it a failure; DNS problems are reported immediately.
            $status = ($caddyError !== null && $age > 5) || ($domain->dns_status !== 'ok' && $age > 15) || $age > 30 ? 'failed' : 'pending';
            $domain->update([
                'cert_status' => $status,
                'cert_error' => $this->explain($domain, $caddyError ?? $result['error']),
                'cert_checked_at' => now(),
            ]);
            if ($status === 'failed' && $previous !== 'failed') {
                $this->notifier->notify('certificate.failed', 'HTTPS certificate problem', "{$domain->hostname}: ".$domain->cert_error, 'error', "/projects/{$domain->project->slug}/domains");
            }
        }

        return $domain->fresh();
    }

    public function httpsEnabled(): bool
    {
        return config('privatecloud.caddy.auto_https') !== 'off';
    }

    private function explain(Domain $domain, ?string $error): string
    {
        if ($domain->dns_status === 'missing') {
            return 'The domain has no DNS records yet. Create an A record pointing to this server; the certificate is requested automatically once DNS is correct.';
        }
        if ($domain->dns_status === 'mismatch') {
            return 'The domain points to a different IP address than this server, so Let\'s Encrypt cannot verify it. Update the A record, then wait for DNS to propagate.';
        }

        return $error ?: 'The certificate has not been issued yet.';
    }

    /** Look for a recent ACME error for this hostname in Caddy's log. */
    private function caddyCertificateError(string $hostname): ?string
    {
        try {
            $lines = $this->docker->containerLogs((string) config('privatecloud.docker.caddy_container'), 400, timestamps: false);
        } catch (Throwable) {
            return null;
        }
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i]['line'];
            if (! str_contains($line, $hostname) || ! str_contains($line, '"error"')) {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data) && in_array($data['level'] ?? '', ['error', 'warn'], true) && isset($data['error'])) {
                return 'Let\'s Encrypt: '.mb_substr((string) $data['error'], 0, 400);
            }
        }

        return null;
    }
}
