import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Activity, Archive, ArrowRight, Boxes, Clock, Cpu, Globe, HardDrive, MemoryStick, Plus, Rocket } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, formatPercent, formatRate, formatUptime, percentOf, timeAgo } from '../lib/format'
import type { DashboardData } from '../lib/types'
import { ButtonLink } from '../components/ui/Button'
import { Card, CardHeader, PageHeader } from '../components/ui/Card'
import { Callout, EmptyState, ErrorState, Meter, Skeleton } from '../components/ui/Feedback'
import { DeploymentStatusBadge, ProjectStatusBadge, ServiceDot } from '../components/ui/Status'

function Stat({ icon, label, value, detail, percent, threshold }: { icon: React.ReactNode; label: string; value: string; detail?: string; percent?: number | null; threshold?: number }) {
  return (
    <div className="card p-4">
      <div className="muted flex items-center gap-2 text-xs font-medium tracking-wide uppercase">
        {icon}
        {label}
      </div>
      <div className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
      {detail && <div className="muted mt-0.5 text-xs tabular-nums">{detail}</div>}
      {percent !== undefined && <Meter value={percent ?? null} threshold={threshold} className="mt-3" label={label} />}
    </div>
  )
}

export default function Dashboard() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<DashboardData>('/dashboard'),
    refetchInterval: 10_000,
  })

  if (error) return <ErrorState error={error} retry={refetch} />

  const s = data?.server
  const t = data?.thresholds
  const memPct = percentOf(s?.memory_used_bytes, s?.memory_total_bytes)
  const diskPct = percentOf(s?.disk_used_bytes, s?.disk_total_bytes)
  const downServices = data?.services.filter((x) => x.status === 'down') ?? []

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={s?.hostname ? `Server ${s.hostname}` : 'Overview of your server and applications'}
        actions={<ButtonLink to="/projects/new" variant="primary" icon={<Plus className="size-4" />}>New Project</ButtonLink>}
      />

      {downServices.length > 0 && (
        <div className="mb-6">
          <Callout tone="error" title={`${downServices.length === 1 ? 'A platform service is' : 'Some platform services are'} not healthy`}>
            {downServices.map((x) => `${x.name}: ${x.detail ?? 'down'}`).join(' · ')}
          </Callout>
        </div>
      )}

      <h2 className="mb-3 text-sm font-semibold">Server health</h2>
      {isLoading || !s ? (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28" />)}</div>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <Stat icon={<Cpu className="size-3.5" />} label="CPU" value={formatPercent(s.cpu_percent)} detail={`${s.cpu_cores ?? '?'} cores · load ${s.load ? s.load[0].toFixed(2) : '—'}`} percent={s.cpu_percent} threshold={t?.cpu} />
          <Stat icon={<MemoryStick className="size-3.5" />} label="RAM" value={`${formatBytes(s.memory_used_bytes)}`} detail={`of ${formatBytes(s.memory_total_bytes)} · ${formatPercent(memPct)}`} percent={memPct} threshold={t?.memory} />
          <Stat icon={<HardDrive className="size-3.5" />} label="Disk" value={formatBytes(s.disk_used_bytes, 0)} detail={`of ${formatBytes(s.disk_total_bytes, 0)} · ${formatPercent(diskPct)}`} percent={diskPct} threshold={t?.disk} />
          <Stat icon={<Clock className="size-3.5" />} label="Uptime" value={formatUptime(s.uptime_seconds)} detail={s.network ? `↓ ${formatRate(s.network.rx_rate)} · ↑ ${formatRate(s.network.tx_rate)}` : undefined} />
        </div>
      )}

      {data && (
        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
          {data.services.map((svc) => (
            <span key={svc.key} className="flex items-center gap-2" title={svc.detail ?? undefined}>
              <ServiceDot status={svc.status} />
              <span className="muted">{svc.name}</span>
            </span>
          ))}
          <Link to="/server" className="ml-auto flex items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
            Server details <ArrowRight className="size-3.5" />
          </Link>
        </div>
      )}

      <div className="mt-8 grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
        <div className="xl:col-span-2">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-semibold">Projects</h2>
            <Link to="/projects" className="muted text-sm hover:text-zinc-900 dark:hover:text-white">View all</Link>
          </div>
          {isLoading ? (
            <div className="grid gap-4 sm:grid-cols-2">{Array.from({ length: 2 }).map((_, i) => <Skeleton key={i} className="h-36" />)}</div>
          ) : data && data.projects.length === 0 ? (
            <Card>
              <EmptyState
                icon={<Boxes className="size-5" />}
                title="No projects yet"
                description="Create a project from a GitHub repository or Docker image. PrivateCloud builds it, runs it and puts it on your domain with HTTPS."
                action={<ButtonLink to="/projects/new" variant="primary" icon={<Plus className="size-4" />}>New Project</ButtonLink>}
              />
            </Card>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2">
              {data?.projects.map((p) => {
                const memPct = p.memory_used_bytes !== null ? percentOf(p.memory_used_bytes, p.memory_limit_mb * 1024 * 1024) : null
                return (
                  <Link key={p.slug} to={`/projects/${p.slug}`} className="card group p-4 transition-shadow hover:shadow-md">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <h3 className="truncate font-semibold group-hover:underline">{p.name}</h3>
                        <p className="muted mt-0.5 flex items-center gap-1 truncate text-sm">
                          <Globe className="size-3.5 shrink-0" /> {p.domain ?? 'No domain'}
                        </p>
                      </div>
                      <ProjectStatusBadge status={p.status} />
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                      <div>
                        <div className="muted text-xs">CPU</div>
                        <div className="tabular-nums">{formatPercent(p.cpu_percent, 1)}</div>
                      </div>
                      <div>
                        <div className="muted text-xs">RAM</div>
                        <div className="tabular-nums">{formatBytes(p.memory_used_bytes, 0)} <span className="muted">/ {formatBytes(p.memory_limit_mb * 1024 * 1024, 0)}</span></div>
                        {memPct !== null && <Meter value={memPct} className="mt-1" label="Memory" />}
                      </div>
                    </div>
                    {p.latest_deployment && (
                      <div className="muted mt-4 flex items-center gap-2 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800">
                        <Rocket className="size-3.5" /> #{p.latest_deployment.number}
                        {p.latest_deployment.commit && <span className="mono">{p.latest_deployment.commit}</span>}
                        <span>· {timeAgo(p.latest_deployment.finished_at ?? p.latest_deployment.created_at)}</span>
                        <span className="ml-auto"><DeploymentStatusBadge status={p.latest_deployment.status} /></span>
                      </div>
                    )}
                  </Link>
                )
              })}
              <Link to="/projects/new" className="flex min-h-36 items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-200 text-sm font-medium text-zinc-500 hover:border-zinc-300 hover:text-zinc-800 dark:border-zinc-800 dark:hover:border-zinc-700 dark:hover:text-zinc-200">
                <Plus className="size-4" /> New Project
              </Link>
            </div>
          )}
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Recent deployments" icon={<Activity className="size-4" />} />
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {(data?.recent_deployments ?? []).length === 0 && <li className="muted px-5 py-6 text-center text-sm">No deployments yet.</li>}
              {data?.recent_deployments.map((d) => (
                <li key={d.id}>
                  <Link to={`/projects/${d.project?.slug}/deployments/${d.id}`} className="flex items-center gap-3 px-5 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{d.project?.name} <span className="muted font-normal">#{d.number}</span></p>
                      <p className="muted truncate text-xs">{d.commit?.message ?? d.type} · {timeAgo(d.created_at)}</p>
                    </div>
                    <DeploymentStatusBadge status={d.status} />
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
          <Card>
            <CardHeader title="Backups" icon={<Archive className="size-4" />} actions={<Link to="/backups" className="muted text-xs hover:underline">Manage</Link>} />
            <div className="space-y-1 px-5 py-4 text-sm">
              <p>Last successful backup: <span className="font-medium">{data?.backups.last_success ? timeAgo(data.backups.last_success) : 'never'}</span></p>
              {data && data.backups.failed_last_24h > 0 && <p className="text-red-600 dark:text-red-400">{data.backups.failed_last_24h} backup(s) failed in the last 24 hours</p>}
              {data && data.domains.problems > 0 && <p className="text-amber-700 dark:text-amber-400">{data.domains.problems} domain(s) need attention</p>}
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}
