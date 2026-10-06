import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Box, Cpu, Database, Globe, HardDrive, MemoryStick, Rocket } from 'lucide-react'
import { api } from '../../lib/api'
import { formatBytes, formatPercent, formatTime, percentOf, timeAgo } from '../../lib/format'
import type { Backup, LogLine, Paginated } from '../../lib/types'
import { useProject } from '../../hooks/project'
import { Card, CardBody, CardHeader, KeyValue } from '../../components/ui/Card'
import { Callout, Meter } from '../../components/ui/Feedback'
import { JobStatusBadge } from '../../components/ui/Status'
import { DeploymentTimeline } from '../../components/DeploymentTimeline'
import { DeployLatestButton, ProductionCard, SourceCard } from '../../components/Production'

interface ContainerInfo {
  container: { name: string; image: string; state: string; running: boolean; restart_count: number; started_at: string; oom_killed: boolean } | null
  usage: { cpu_percent: number; memory_used_bytes: number; memory_limit_bytes: number; pids: number } | null
  limits: { memory_bytes: number; cpu: number }
}

export default function Overview() {
  const project = useProject()
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

  const current = project.current_deployment
  const latest = project.latest_deployment
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
        {latest && latest.status === 'failed' && latest.id !== current?.id && project.sync?.state !== 'failed' && (
          <Callout tone="error" title={`Deployment #${latest.number} failed`} action={<Link to={`deployments/${latest.id}`} className="text-sm font-medium underline">Details</Link>}>
            {latest.failure?.reason}
            {current && <span className="block opacity-80">Deployment #{current.number} is still serving traffic.</span>}
          </Callout>
        )}

        {project.repository ? (
          <SourceCard project={project} />
        ) : (
          <Card>
            <CardHeader title="Source" icon={<Box className="size-4" />} actions={<DeployLatestButton project={project} size="sm" />} />
            <CardBody><KeyValue label="Image"><span className="mono">{project.image}</span></KeyValue></CardBody>
          </Card>
        )}

        <ProductionCard project={project} />

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
