import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Container } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, formatPercent, timeAgo } from '../lib/format'
import type { ContainerSummary } from '../lib/types'
import { Card, PageHeader } from '../components/ui/Card'
import { Callout, EmptyState, ErrorState, LoadingBlock } from '../components/ui/Feedback'
import { Badge } from '../components/ui/Status'
import { Segmented } from '../components/ui/Tabs'

export default function Containers() {
  const [kind, setKind] = useState<'all' | 'project' | 'platform' | 'other'>('all')
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['containers'], queryFn: () => api<{ data: ContainerSummary[]; message?: string }>('/containers'), refetchInterval: 15_000 })
  const rows = (data?.data ?? []).filter((c) => kind === 'all' || c.kind === kind)

  return (
    <>
      <PageHeader title="Containers" description="Everything running in Docker on this server. Manage applications from their project page." />
      {data?.message && <div className="mb-4"><Callout tone="error">{data.message}</Callout></div>}
      <div className="mb-4"><Segmented value={kind} onChange={setKind} label="Container type" options={[{ value: 'all', label: 'All' }, { value: 'project', label: 'Applications' }, { value: 'platform', label: 'Platform' }, { value: 'other', label: 'Other' }]} /></div>
      {error ? <ErrorState error={error} retry={refetch} /> : isLoading ? <LoadingBlock /> : rows.length === 0 ? <Card><EmptyState icon={<Container className="size-5" />} title="No containers" /></Card> : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="muted border-b border-zinc-100 text-left text-xs dark:border-zinc-800">
                <tr><th className="px-5 py-3 font-medium">Container</th><th className="px-5 py-3 font-medium">State</th><th className="hidden px-5 py-3 font-medium md:table-cell">Image</th><th className="px-5 py-3 font-medium">CPU</th><th className="px-5 py-3 font-medium">RAM</th><th className="hidden px-5 py-3 font-medium lg:table-cell">Ports</th><th className="hidden px-5 py-3 font-medium lg:table-cell">Created</th></tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {rows.map((c) => (
                  <tr key={c.id}>
                    <td className="px-5 py-3">
                      <div className="mono font-medium">{c.name}</div>
                      <div className="muted text-xs">{c.project ? <Link to={`/projects/${c.project.slug}`} className="hover:underline">{c.project.name}</Link> : c.kind === 'platform' ? 'PrivateCloud platform' : 'Not managed by PrivateCloud'}</div>
                    </td>
                    <td className="px-5 py-3"><Badge tone={c.state === 'running' ? 'green' : c.state === 'restarting' ? 'amber' : c.state === 'exited' ? 'red' : 'gray'}>{c.state}</Badge><div className="muted mt-1 text-xs">{c.status}</div></td>
                    <td className="mono hidden max-w-[16rem] truncate px-5 py-3 text-xs md:table-cell" title={c.image ?? ''}>{c.image}</td>
                    <td className="px-5 py-3 tabular-nums">{formatPercent(c.cpu_percent, 1)}</td>
                    <td className="px-5 py-3 tabular-nums">{formatBytes(c.memory_used_bytes)}</td>
                    <td className="mono hidden px-5 py-3 text-xs lg:table-cell">{c.ports.join(', ') || '—'}</td>
                    <td className="muted hidden px-5 py-3 text-xs lg:table-cell">{timeAgo(c.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <p className="muted mt-3 text-xs">CPU and RAM are sampled every minute for applications. Arbitrary container commands are intentionally not available from the dashboard.</p>
    </>
  )
}
