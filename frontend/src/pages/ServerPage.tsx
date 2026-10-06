import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../lib/api'
import { formatBytes, formatPercent, formatRate, formatUptime, percentOf } from '../lib/format'
import type { ServerSnapshot, ServiceStatus } from '../lib/types'
import { Button } from '../components/ui/Button'
import { Card, CardBody, CardHeader, KeyValue, PageHeader } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/Dialog'
import { LoadingBlock, Meter } from '../components/ui/Feedback'
import { ServiceDot } from '../components/ui/Status'
import { Segmented } from '../components/ui/Tabs'
import { MetricChart } from '../components/MetricChart'

interface HistoryPoint { t: string; cpu: number | null; memory: number | null; memory_total: number | null; disk: number | null; disk_total: number | null; load: number | null }
type Range = '1h' | '6h' | '24h' | '3d'

function Gauge({ label, value, detail, threshold }: { label: string; value: number | null; detail: string; threshold: number }) {
  return (
    <div>
      <div className="mb-1.5 flex items-baseline justify-between"><span className="text-sm font-medium">{label}</span><span className="text-xl font-semibold tabular-nums">{formatPercent(value)}</span></div>
      <Meter value={value} threshold={threshold} label={label} />
      <p className="muted mt-1.5 text-xs tabular-nums">{detail} · warning at {threshold}%</p>
    </div>
  )
}

export default function ServerPage() {
  const [range, setRange] = useState<Range>('6h')
  const [cleanupOpen, setCleanupOpen] = useState(false)
  const live = useQuery({ queryKey: ['server-live'], queryFn: () => api<ServerSnapshot>('/server/metrics'), refetchInterval: 5000 })
  const services = useQuery({ queryKey: ['server-services'], queryFn: () => api<{ data: ServiceStatus[] }>('/server/services'), refetchInterval: 20_000 })
  const history = useQuery({ queryKey: ['server-history', range], queryFn: () => api<{ points: HistoryPoint[] }>('/server/metrics/history', { query: { range } }), refetchInterval: 60_000 })
  const cleanup = useMutation({
    mutationFn: () => api<{ message: string }>('/server/cleanup', { method: 'POST' }),
    onSuccess: (r) => { toast.success(r.message); setCleanupOpen(false) },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const s = live.data
  const t = s?.thresholds ?? { cpu: 90, memory: 90, disk: 85 }
  const points = history.data?.points ?? []

  return (
    <>
      <PageHeader title="Server" description={s ? `${s.hostname ?? 'Server'}${s.kernel ? ` · Linux ${s.kernel}` : ''}${s.public_ipv4 ? ` · ${s.public_ipv4}` : ''}` : undefined} actions={<Button icon={<Trash2 className="size-4" />} onClick={() => setCleanupOpen(true)}>Free up disk space</Button>} />
      {!s ? <LoadingBlock /> : (
        <div className="space-y-6">
          <Card>
            <CardHeader title="Live usage" description="Refreshes every 5 seconds." />
            <CardBody className="grid gap-8 md:grid-cols-3">
              <Gauge label="CPU" value={s.cpu_percent} detail={`${s.cpu_cores ?? '?'} cores · load ${s.load?.map((l) => l.toFixed(2)).join(' / ') ?? '—'}`} threshold={t.cpu} />
              <Gauge label="Memory" value={percentOf(s.memory_used_bytes, s.memory_total_bytes)} detail={`${formatBytes(s.memory_used_bytes)} used · ${formatBytes(s.memory_available_bytes)} available · ${formatBytes(s.memory_total_bytes)} total`} threshold={t.memory} />
              <Gauge label="Disk" value={percentOf(s.disk_used_bytes, s.disk_total_bytes)} detail={`${formatBytes(s.disk_used_bytes, 0)} used · ${formatBytes(s.disk_free_bytes, 0)} free · ${formatBytes(s.disk_total_bytes, 0)} total`} threshold={t.disk} />
            </CardBody>
            <div className="grid gap-5 border-t border-zinc-100 px-5 py-4 sm:grid-cols-4 dark:border-zinc-800">
              <KeyValue label="Uptime">{formatUptime(s.uptime_seconds)}</KeyValue>
              <KeyValue label="Network in">{formatRate(s.network?.rx_rate)}</KeyValue>
              <KeyValue label="Network out">{formatRate(s.network?.tx_rate)}</KeyValue>
              <KeyValue label="Swap">{s.swap_total_bytes ? `${formatBytes(s.swap_used_bytes)} / ${formatBytes(s.swap_total_bytes)}` : 'None'}</KeyValue>
            </div>
          </Card>

          <Card>
            <CardHeader title="Services" />
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {services.data?.data.map((svc) => (
                <li key={svc.key} className="flex items-center gap-3 px-5 py-3 text-sm">
                  <ServiceDot status={svc.status} />
                  <span className="w-56 font-medium">{svc.name}</span>
                  <span className="muted flex-1">{svc.detail ?? (svc.status === 'ok' ? 'Healthy' : svc.status)}</span>
                </li>
              ))}
            </ul>
          </Card>

          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">History</h2>
            <Segmented value={range} onChange={setRange} label="Time range" options={[{ value: '1h', label: '1h' }, { value: '6h', label: '6h' }, { value: '24h', label: '24h' }, { value: '3d', label: '3d' }]} />
          </div>
          <div className="grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
            <Card><CardHeader title="CPU" /><CardBody><MetricChart label="CPU" points={points.map((p) => ({ t: p.t, value: p.cpu }))} format={(v) => formatPercent(v)} max={100} /></CardBody></Card>
            <Card><CardHeader title="Memory" /><CardBody><MetricChart label="Memory" points={points.map((p) => ({ t: p.t, value: p.memory }))} format={(v) => formatBytes(v, 0)} max={points[points.length - 1]?.memory_total ?? undefined} color="#10b981" /></CardBody></Card>
            <Card><CardHeader title="Disk" /><CardBody><MetricChart label="Disk" points={points.map((p) => ({ t: p.t, value: p.disk }))} format={(v) => formatBytes(v, 0)} max={points[points.length - 1]?.disk_total ?? undefined} color="#f59e0b" /></CardBody></Card>
            <Card><CardHeader title="Load average (1 min)" /><CardBody><MetricChart label="Load" points={points.map((p) => ({ t: p.t, value: p.load }))} format={(v) => v.toFixed(1)} color="#8b5cf6" /></CardBody></Card>
          </div>
        </div>
      )}
      <ConfirmDialog open={cleanupOpen} onOpenChange={setCleanupOpen} title="Free up disk space?" description="Removes Docker build cache older than 7 days, unused images built by PrivateCloud that are not kept for rollback, and leftover temporary build files. Running applications and their rollback images are not affected." confirmLabel="Clean up" loading={cleanup.isPending} onConfirm={() => cleanup.mutate()} />
    </>
  )
}
