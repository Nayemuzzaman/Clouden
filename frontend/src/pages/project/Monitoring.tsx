import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { formatBytes, formatDateTime, formatPercent } from '../../lib/format'
import { useProject } from '../../hooks/project'
import { Card, CardBody, CardHeader, KeyValue } from '../../components/ui/Card'
import { Segmented } from '../../components/ui/Tabs'
import { MetricChart } from '../../components/MetricChart'

interface Point { t: string; cpu: number | null; memory: number | null; memory_limit: number | null; rx: number | null; tx: number | null }
type Range = '1h' | '6h' | '24h' | '3d'

export default function Monitoring() {
  const project = useProject()
  const [range, setRange] = useState<Range>('6h')
  const metrics = useQuery({
    queryKey: ['project-metrics', project.slug, range],
    queryFn: () => api<{ points: Point[] }>(`/projects/${project.slug}/metrics`, { query: { range } }),
    refetchInterval: 60_000,
  })
  const container = useQuery({
    queryKey: ['container', project.slug],
    queryFn: () => api<{ container: Record<string, unknown> | null; usage: { cpu_percent: number; memory_used_bytes: number; pids: number } | null }>(`/projects/${project.slug}/container`),
    refetchInterval: 10_000,
  })
  const points = metrics.data?.points ?? []
  const c = container.data?.container as { name: string; image: string; state: string; created_at: string; started_at: string; restart_count: number; ports: string[]; networks: string[]; oom_killed: boolean } | null | undefined
  // Network counters are cumulative: convert to per-minute deltas.
  const rx = points.slice(1).map((p, i) => ({ t: p.t, value: p.rx !== null && points[i].rx !== null ? Math.max(0, (p.rx - (points[i].rx ?? 0)) / 60) : null }))

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <p className="muted text-sm">Sampled every minute while the application runs.</p>
        <Segmented value={range} onChange={setRange} label="Time range" options={[{ value: '1h', label: '1h' }, { value: '6h', label: '6h' }, { value: '24h', label: '24h' }, { value: '3d', label: '3d' }]} />
      </div>
      <div className="grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
        <Card>
          <CardHeader title="CPU" description={`Limit ${project.cpu_limit} ${project.cpu_limit === 1 ? 'core' : 'cores'} (100% = one full core)`} />
          <CardBody><MetricChart label="CPU" points={points.map((p) => ({ t: p.t, value: p.cpu }))} format={(v) => formatPercent(v, 0)} color="#6366f1" /></CardBody>
        </Card>
        <Card>
          <CardHeader title="Memory" description={`Limit ${formatBytes(project.memory_limit_mb * 1048576, 0)}`} />
          <CardBody><MetricChart label="Memory" points={points.map((p) => ({ t: p.t, value: p.memory }))} format={(v) => formatBytes(v, 0)} max={project.memory_limit_mb * 1048576} color="#10b981" /></CardBody>
        </Card>
        <Card>
          <CardHeader title="Network in" />
          <CardBody><MetricChart label="Network in" points={rx} format={(v) => `${formatBytes(v, 0)}/s`} color="#0ea5e9" /></CardBody>
        </Card>
        <Card>
          <CardHeader title="Container" />
          <CardBody>
            {c ? (
              <dl className="grid grid-cols-2 gap-4">
                <KeyValue label="Name" mono>{c.name}</KeyValue>
                <KeyValue label="State">{c.state}{c.oom_killed ? ' (out of memory)' : ''}</KeyValue>
                <KeyValue label="Image" mono>{c.image}</KeyValue>
                <KeyValue label="Restarts">{c.restart_count}</KeyValue>
                <KeyValue label="Created">{formatDateTime(c.created_at)}</KeyValue>
                <KeyValue label="Started">{formatDateTime(c.started_at)}</KeyValue>
                <KeyValue label="Ports" mono>{c.ports.join(', ') || '—'}</KeyValue>
                <KeyValue label="Processes">{container.data?.usage?.pids ?? '—'}</KeyValue>
              </dl>
            ) : (
              <p className="muted text-sm">No running container.</p>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
