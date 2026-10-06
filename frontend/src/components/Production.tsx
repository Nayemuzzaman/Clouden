import { useState } from 'react'
import { Link } from 'react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, GitBranch, GitCommitHorizontal, Globe2, Lock, RefreshCw, Rocket, RotateCw } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../lib/api'
import { timeAgo } from '../lib/format'
import { productionUrl } from '../lib/production'
import type { Project } from '../lib/types'
import { isUpToDate, useDeploy } from '../hooks/project'
import { Button, ButtonLink, ExternalButton } from './ui/Button'
import { Card, CardBody, CardHeader, KeyValue } from './ui/Card'
import { Dialog } from './ui/Dialog'
import { Callout } from './ui/Feedback'
import { Badge, SyncBadge } from './ui/Status'
import { GitHubIcon } from './GitHubIcon'

function ShortSha({ sha }: { sha: string | null | undefined }) {
  return sha ? <span className="mono shrink-0 rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{sha.slice(0, 7)}</span> : <span className="muted">—</span>
}

/**
 * "Deploy Latest": deploys the current head of the production branch. When
 * production already runs it, offers to redeploy or rebuild instead.
 */
export function DeployLatestButton({ project, size = 'md', variant = 'primary', label }: { project: Project; size?: 'sm' | 'md'; variant?: 'primary' | 'secondary'; label?: string }) {
  const deploy = useDeploy(project.slug)
  const [upToDate, setUpToDate] = useState<string | null>(null)
  const run = (force = false) =>
    deploy.mutate({ type: 'latest', force }, {
      onError: (e) => {
        if (isUpToDate(e)) setUpToDate(e.message)
      },
      onSuccess: () => setUpToDate(null),
    })

  return (
    <>
      <Button variant={variant} size={size} icon={<Rocket className={size === 'sm' ? 'size-3.5' : 'size-4'} />} loading={deploy.isPending} disabled={project.deleting} onClick={() => run()}>
        {label ?? (project.source_type === 'image' ? 'Deploy' : 'Deploy Latest')}
      </Button>
      <Dialog
        open={upToDate !== null}
        onOpenChange={(open) => !open && setUpToDate(null)}
        title="Production is already up to date"
        description={upToDate ?? undefined}
        footer={
          <>
            <Button onClick={() => setUpToDate(null)}>Close</Button>
            <Button icon={<RotateCw className="size-4" />} loading={deploy.isPending && deploy.variables?.type === 'latest'} onClick={() => run(true)}>Rebuild from source</Button>
            <Button variant="primary" icon={<Rocket className="size-4" />} loading={deploy.isPending && deploy.variables?.type === 'redeploy'} onClick={() => deploy.mutate({ type: 'redeploy' }, { onSuccess: () => setUpToDate(null) })}>
              Redeploy current version
            </Button>
          </>
        }
      >
        <p className="text-sm">Redeploying restarts the live version with the current environment variables and settings. Rebuilding builds the same commit again from source.</p>
      </Dialog>
    </>
  )
}

const visibilityLabel = { public: 'Public', private: 'Private' } as const

function WebhookState({ project }: { project: Project }) {
  const repo = project.repository
  if (!repo || repo.provider !== 'github') return <span className="muted">Not used</span>
  if (!project.auto_deploy) return <span className="muted">Off (auto deploy is off)</span>
  switch (repo.webhook_status) {
    case 'active':
      return <Badge tone="green">Connected</Badge>
    case 'failed':
      return <span title={repo.webhook_error ?? undefined}><Badge tone="red">Could not be created</Badge></span>
    case 'orphaned':
      return <span title={repo.webhook_error ?? undefined}><Badge tone="amber">Needs removal in GitHub</Badge></span>
    case 'manual':
      return <Badge tone="amber">{repo.webhook_last_delivery_at ? 'Connected (manual)' : 'Add it in GitHub'}</Badge>
    default:
      return repo.webhook_installed ? <Badge tone="green">Installed</Badge> : <Badge tone="gray">Not installed</Badge>
  }
}

/** Repository, production branch, branch head vs. production, and what to do about it. */
export function SourceCard({ project }: { project: Project }) {
  const queryClient = useQueryClient()
  const deploy = useDeploy(project.slug)
  const refresh = useMutation({
    mutationFn: () => api(`/projects/${project.slug}/refresh-commit`, { method: 'POST' }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['project', project.slug] }),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const repo = project.repository
  if (!repo) return null
  const sync = project.sync
  const desired = sync?.desired ?? repo.latest_commit
  const production = sync?.production
  const url = productionUrl(project)
  const isGitHub = repo.provider === 'github'

  return (
    <Card>
      <CardHeader
        title={isGitHub ? 'GitHub' : 'Source'}
        icon={isGitHub ? <GitHubIcon /> : <GitBranch className="size-4" />}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} loading={refresh.isPending} onClick={() => refresh.mutate()}>Check for new commits</Button>
            <DeployLatestButton project={project} size="sm" />
          </div>
        }
      />
      <CardBody className="space-y-4">
        <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <KeyValue label="Repository">
            {isGitHub && repo.full_name ? <a href={`https://github.com/${repo.full_name}`} target="_blank" rel="noopener noreferrer" className="hover:underline">{repo.full_name}</a> : <span className="break-all">{repo.url}</span>}
          </KeyValue>
          <KeyValue label="Visibility">
            {repo.visibility ? <span className="inline-flex items-center gap-1">{repo.visibility === 'private' ? <Lock className="size-3.5" /> : <Globe2 className="size-3.5" />} {visibilityLabel[repo.visibility]}</span> : <span className="muted">Unknown</span>}
          </KeyValue>
          <KeyValue label="Production branch"><span className="inline-flex items-center gap-1"><GitBranch className="size-3.5" /> {repo.branch}</span></KeyValue>
          <KeyValue label="Auto deploy">{project.auto_deploy ? <Badge tone="green">On</Badge> : <Badge tone="gray">Off</Badge>}</KeyValue>
          <KeyValue label="Webhook"><WebhookState project={project} /></KeyValue>
          <KeyValue label="Status">{sync ? <SyncBadge state={sync.state} rolledBack={sync.rolled_back} sha={sync.deploying?.short_sha} /> : <span className="muted">—</span>}</KeyValue>
          <KeyValue label={isGitHub ? `GitHub ${repo.branch}` : `Branch ${repo.branch}`}>
            {desired ? (
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="flex min-w-0 items-center gap-2"><ShortSha sha={desired.sha} /> <span className="truncate">{desired.message ?? ''}</span></span>
                <span className="muted text-xs">{[desired.author, desired.committed_at && timeAgo(desired.committed_at)].filter(Boolean).join(' · ')}</span>
              </span>
            ) : <span className="muted">{repo.last_check_error ?? 'Not checked yet'}</span>}
          </KeyValue>
          <KeyValue label="Production">
            {production ? (
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="flex min-w-0 items-center gap-2"><ShortSha sha={production.sha} /> <span className="truncate">{production.message ?? ''}</span></span>
                <span className="muted text-xs">Deployment #{production.number}{production.deployed_at ? ` · ${timeAgo(production.deployed_at)}` : ''}</span>
              </span>
            ) : <span className="muted">Not deployed</span>}
          </KeyValue>
          <KeyValue label="Last checked">{sync?.checked_at ? timeAgo(sync.checked_at) : <span className="muted">—</span>}</KeyValue>
        </dl>

        {sync?.attention && (
          <Callout
            tone="error"
            title={sync.attention === 'branch_missing' ? 'Production branch unavailable' : 'GitHub connection needs attention'}
            action={sync.attention === 'branch_missing'
              ? <ButtonLink size="sm" to={`/projects/${project.slug}/settings`}>Choose branch</ButtonLink>
              : <ButtonLink size="sm" to="/settings">Reconnect GitHub</ButtonLink>}
          >
            {sync.attention_message}
          </Callout>
        )}

        {sync?.state === 'failed' && sync.failed && (
          <Callout tone="error" title={`Latest ${repo.branch} commit failed to deploy`}>
            <p>{production ? <>Your previous production version is still running (<span className="mono">{production.short_sha ?? `#${production.number}`}</span>).</> : 'Nothing is live yet.'}</p>
            {sync.failed.reason && <p className="mt-1 opacity-80">{sync.failed.reason}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
              <ButtonLink size="sm" to={`/projects/${project.slug}/deployments/${sync.failed.deployment_id}`}>View failure</ButtonLink>
              {sync.failed.sha && <Button size="sm" icon={<RotateCw className="size-3.5" />} loading={deploy.isPending} onClick={() => deploy.mutate({ type: 'commit', sha: sync.failed!.sha! })}>Deploy latest again</Button>}
              {url && production && <ExternalButton size="sm" href={url} icon={<ExternalLink className="size-3.5" />}>Open production</ExternalButton>}
            </div>
          </Callout>
        )}

        {sync?.rolled_back && (
          <Callout tone="warning" title="Production was intentionally rolled back" action={<DeployLatestButton project={project} size="sm" variant="secondary" label={`Deploy ${repo.branch} again`} />}>
            {repo.branch} is at <span className="mono">{sync.desired?.short_sha}</span>, production runs <span className="mono">{production?.short_sha}</span>. Auto deploy resumes with the next push to {repo.branch}.
          </Callout>
        )}

        {sync?.state === 'out_of_sync' && !sync.rolled_back && production && desired && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-3 text-sm dark:bg-brand-500/10">
            <span className="flex items-center gap-2"><GitCommitHorizontal className="size-4" /> {repo.branch} has a newer commit ({desired.short_sha}{desired.author ? ` by ${desired.author}` : ''}).</span>
            <DeployLatestButton project={project} size="sm" />
          </div>
        )}
      </CardBody>
    </Card>
  )
}

/** What is live right now. */
export function ProductionCard({ project }: { project: Project }) {
  const current = project.current_deployment
  const sync = project.sync
  const url = productionUrl(project)

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2">Production {current && <Badge tone={project.status === 'running' ? 'green' : 'red'}>{project.status === 'running' ? 'Live' : project.status === 'stopped' ? 'Stopped' : 'Down'}</Badge>}</span>}
        icon={<Rocket className="size-4" />}
        actions={<div className="flex items-center gap-3">{url && current && <a href={url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-1 text-sm font-medium hover:underline">Open <ExternalLink className="size-3.5" /></a>}<Link to="deployments" className="muted text-sm hover:underline">History</Link></div>}
      />
      <CardBody>
        {current ? (
          <dl className="grid gap-5 sm:grid-cols-3">
            <KeyValue label="Commit">{current.commit ? <span className="flex min-w-0 items-center gap-2"><ShortSha sha={current.commit.sha} /> <span className="truncate">{current.commit.message ?? ''}</span></span> : current.image_tag ?? '—'}</KeyValue>
            <KeyValue label="Branch">{current.branch ?? '—'}</KeyValue>
            <KeyValue label="Deployed">{timeAgo(current.finished_at)} <span className="muted">· <Link to={`deployments/${current.id}`} className="hover:underline">#{current.number}</Link>{current.type === 'rollback' ? ' (rollback)' : ''}</span></KeyValue>
            {sync && (
              <>
                <KeyValue label={`GitHub ${sync.branch}`}><ShortSha sha={sync.desired?.sha} /></KeyValue>
                <KeyValue label="Status"><SyncBadge state={sync.state} rolledBack={sync.rolled_back} sha={sync.deploying?.short_sha} /></KeyValue>
              </>
            )}
            <KeyValue label="By">{current.initiated_by ?? '—'}</KeyValue>
          </dl>
        ) : (
          <p className="muted text-sm">Nothing is live yet. Click <strong>Deploy Latest</strong> to build and start {project.repository ? `the head of ${project.repository.branch}` : 'the application'}.</p>
        )}
      </CardBody>
    </Card>
  )
}

