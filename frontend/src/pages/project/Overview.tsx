import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Box, Cpu, Database, GitBranch, GitCommitHorizontal, Globe, HardDrive, MemoryStick, RefreshCw, Rocket } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatBytes, formatPercent, formatTime, percentOf, timeAgo } from '../../lib/format'
import type { Backup, LogLine, Paginated } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader, KeyValue } from '../../components/ui/Card'
import { Callout, Meter } from '../../components/ui/Feedback'
import { DeploymentStatusBadge, JobStatusBadge } from '../../components/ui/Status'
import { DeploymentTimeline } from '../../components/DeploymentTimeline'
import { GitHubIcon } from '../../components/GitHubIcon'

interface ContainerInfo {
  container: { name: string; image: string; state: string; running: boolean; restart_count: number; started_at: string; oom_killed: boolean } | null
  usage: { cpu_percent: number; memory_used_bytes: number; memory_limit_bytes: number; pids: number } | null
  limits: { memory_bytes: number; cpu: number }
}

export default function Overview() {
  const project = useProject()
  const queryClient = useQueryClient()
  const deploy = useDeploy(project.slug)
  const container = useQuery({
    queryKey: ['container', project.slug],
    queryFn: () => api<ContainerInfo>(`/projects/${project.slug}/container`),
    refetchInterval: 10_000,
    enabled: !!project.current_deployment,
  })
  const logs = useQuery({
    queryKey: ['logs-preview', project.slug],
    queryFn: () => api<{ lines: LogLine[] }>(`/projects/${project.slug}/logs`, { query: { tail: 8 } }),
    refetchInterval: 10_000,
    enabled: !!project.current_deployment,
  })
  const backups = useQuery({
    queryKey: ['backups', project.slug, 'latest'],
    queryFn: () => api<Paginated<Backup>>('/backups', { query: { project: project.slug, per_page: 1 } }),
  })
  const refresh = useMutation({
    mutationFn: () => api(`/projects/${project.slug}/refresh-commit`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['project', project.slug] }),
    onError: (e) => toast.error(errorMessage(e)),
  })

  const current = project.current_deployment
  const latest = project.latest_deployment
  const latestCommit = project.repository?.latest_commit
  const behind = latestCommit && current?.commit && latestCommit.sha !== current.commit.sha
  const usage = container.data?.usage
  const memPct = usage ? percentOf(usage.memory_used_bytes, container.data?.limits.memory_bytes) : null
  const cpuPct = usage ? Math.min(100, usage.cpu_percent / Math.max(project.cpu_limit, 0.01)) : null
  const lastBackup = backups.data?.data[0]

  return (
    <div className="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
      <div className="space-y-6 lg:col-span-2">
        {latest && latest.is_active && (
          <Card>
            <CardHeader title={`Deployment #${latest.number} in progress`} icon={<Rocket className="size-4" />} actions={<Link to={`deployments/${latest.id}`} className="text-sm font-medium hover:underline">View progress</Link>} />
            <CardBody><DeploymentTimeline deployment={latest} /></CardBody>
          </Card>
        )}
        {latest && latest.status === 'failed' && latest.id !== current?.id && (
          <Callout tone="error" title={`Deployment #${latest.number} failed`} action={<Link to={`deployments/${latest.id}`} className="text-sm font-medium underline">Details</Link>}>
            {latest.failure?.reason}
            {current && <span className="block opacity-80">Deployment #{current.number} is still serving traffic.</span>}
          </Callout>
        )}

        <Card>
          <CardHeader
            title="Source"
            icon={project.source_type === 'github' ? <GitHubIcon /> : project.source_type === 'image' ? <Box className="size-4" /> : <GitBranch className="size-4" />}
            actions={project.repository && <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} loading={refresh.isPending} onClick={() => refresh.mutate()}>Check for new commits</Button>}
          />
          <CardBody>
            {project.repository ? (
              <dl className="grid gap-5 sm:grid-cols-2">
                <KeyValue label="Repository">{project.repository.full_name ?? project.repository.url}</KeyValue>
                <KeyValue label="Branch"><span className="inline-flex items-center gap-1"><GitBranch className="size-3.5" /> {project.repository.branch}</span></KeyValue>
                <KeyValue label="Latest commit">
                  {latestCommit ? (
                    <span className="flex min-w-0 items-center gap-2"><span className="mono shrink-0 rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{latestCommit.short_sha}</span> <span className="truncate">{latestCommit.message ?? ''}</span></span>
                  ) : project.repository.last_check_error ? <span className="text-red-600">{project.repository.last_check_error}</span> : '—'}
                </KeyValue>
                <KeyValue label="Production commit">
                  {current?.commit ? (
                    <span className="flex min-w-0 items-center gap-2"><span className="mono shrink-0 rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{current.commit.short_sha}</span> <span className="truncate">{current.commit.message ?? ''}</span></span>
                  ) : 'Not deployed'}
                </KeyValue>
              </dl>
            ) : (
              <KeyValue label="Image"><span className="mono">{project.image}</span></KeyValue>
            )}
            {behind && (
              <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-3 text-sm dark:bg-brand-500/10">
                <span className="flex items-center gap-2"><GitCommitHorizontal className="size-4" /> A newer commit is available ({latestCommit?.short_sha}{latestCommit?.author ? ` by ${latestCommit.author}` : ''}).</span>
                <Button size="sm" variant="primary" icon={<Rocket className="size-3.5" />} loading={deploy.isPending} onClick={() => deploy.mutate({ type: 'latest' })}>Deploy Latest</Button>
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Current deployment" icon={<Rocket className="size-4" />} actions={<Link to="deployments" className="muted text-sm hover:underline">History</Link>} />
          <CardBody>
            {current ? (
              <dl className="grid gap-5 sm:grid-cols-3">
                <KeyValue label="Deployment"><Link to={`deployments/${current.id}`} className="hover:underline">#{current.number}</Link> <DeploymentStatusBadge status={current.status} /></KeyValue>
                <KeyValue label="Deployed">{timeAgo(current.finished_at)}</KeyValue>
                <KeyValue label="By">{current.initiated_by ?? '—'}</KeyValue>
              </dl>
            ) : (
              <p className="muted text-sm">This project has not been deployed yet. Click <strong>Deploy</strong> to build and start it.</p>
            )}
          </CardBody>
        </Card>

        {project.current_deployment && (
          <Card>
            <CardHeader title="Recent logs" actions={<Link to="logs" className="flex items-center gap-1 text-sm font-medium hover:underline">All logs <ArrowRight className="size-3.5" /></Link>} />
            <div className="overflow-x-auto bg-zinc-950 px-4 py-3 font-mono text-xs leading-5 text-zinc-300">
              {(logs.data?.lines ?? []).length === 0 && <p className="text-zinc-600">No recent output.</p>}
              {logs.data?.lines.map((l, i) => (
                <div key={i} className="truncate"><span className="text-zinc-600">{formatTime(l.timestamp)}</span> <span className={l.level === 'error' ? 'text-red-400' : l.level === 'warn' ? 'text-amber-300' : ''}>{l.message}</span></div>
              ))}
            </div>
          </Card>
        )}
      </div>

      <div className="space-y-6">
        <Card>
          <CardHeader title="Resources" />
          <CardBody className="space-y-4">
            <div>
              <div className="mb-1.5 flex items-center justify-between text-sm"><span className="flex items-center gap-1.5"><Cpu className="size-3.5 text-zinc-400" /> CPU</span><span className="tabular-nums">{usage ? formatPercent(usage.cpu_percent, 1) : '—'} <span className="muted">of {project.cpu_limit} {project.cpu_limit === 1 ? 'core' : 'cores'}</span></span></div>
              <Meter value={cpuPct} label="CPU" />
            </div>
            <div>
              <div className="mb-1.5 flex items-center justify-between text-sm"><span className="flex items-center gap-1.5"><MemoryStick className="size-3.5 text-zinc-400" /> RAM</span><span className="tabular-nums">{usage ? formatBytes(usage.memory_used_bytes) : '—'} <span className="muted">/ {formatBytes(project.memory_limit_mb * 1048576, 0)}</span></span></div>
              <Meter value={memPct} label="Memory" />
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-1.5"><HardDrive className="size-3.5 text-zinc-400" /> Volumes</span>
              <span className="tabular-nums">{(project.volumes ?? []).length ? formatBytes((project.volumes ?? []).reduce((a, v) => a + (v.size_bytes ?? 0), 0)) : 'None'}</span>
            </div>
            {container.data?.container && (
              <p className="muted text-xs">Container {container.data.container.state}{container.data.container.restart_count > 0 ? ` · restarted ${container.data.container.restart_count}×` : ''}{container.data.container.oom_killed ? ' · killed for exceeding the memory limit' : ''}</p>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Domains" icon={<Globe className="size-4" />} actions={<Link to="domains" className="muted text-sm hover:underline">Manage</Link>} />
          <CardBody className="space-y-2">
            {(project.domains ?? []).length === 0 && <p className="muted text-sm">No domain yet. <Link to="domains" className="underline">Add one</Link> to publish the app.</p>}
            {project.domains?.map((d) => (
              <div key={d.id} className="flex items-center justify-between gap-2 text-sm">
                <a href={`${d.certificate.status === 'disabled' ? 'http' : 'https'}://${d.hostname}`} target="_blank" rel="noopener noreferrer" className="truncate hover:underline">{d.unicode_hostname}</a>
                <span className="muted text-xs">{d.certificate.status === 'active' ? 'HTTPS active' : d.dns.status !== 'ok' && d.dns.status !== 'unknown' ? 'DNS not ready' : d.certificate.status === 'disabled' ? 'HTTP' : 'HTTPS pending'}</span>
              </div>
            ))}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Database" icon={<Database className="size-4" />} actions={<Link to="database" className="muted text-sm hover:underline">Open</Link>} />
          <CardBody className="text-sm">
            {project.database ? (
              <div className="space-y-1">
                <p className="mono">{project.database.name}</p>
                <p className="muted">PostgreSQL · {project.database.status}{project.database.size_bytes ? ` · ${formatBytes(project.database.size_bytes)}` : ''}</p>
              </div>
            ) : (
              <p className="muted">No database. <Link to="database" className="underline">Create one</Link>.</p>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Last backup" actions={<Link to="backups" className="muted text-sm hover:underline">Backups</Link>} />
          <CardBody className="text-sm">
            {lastBackup ? (
              <div className="flex items-center justify-between gap-2"><span>{lastBackup.label} · {timeAgo(lastBackup.created_at)}</span><JobStatusBadge status={lastBackup.status} /></div>
            ) : (
              <p className="muted">No backups yet.</p>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
