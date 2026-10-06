import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Globe } from 'lucide-react'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { Domain } from '../lib/types'
import { Card, PageHeader } from '../components/ui/Card'
import { EmptyState, ErrorState, LoadingBlock } from '../components/ui/Feedback'
import { DomainBadges } from './project/Domains'

export default function DomainsPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['all-domains'], queryFn: () => api<{ data: Domain[] }>('/domains'), refetchInterval: 30_000 })
  return (
    <>
      <PageHeader title="Domains" description="All domains routed by this server. Add domains from a project's Domains tab." />
      {error ? <ErrorState error={error} retry={refetch} /> : isLoading || !data ? <LoadingBlock /> : data.data.length === 0 ? (
        <Card><EmptyState icon={<Globe className="size-5" />} title="No domains yet" description="Open a project and add a domain to publish it with HTTPS." /></Card>
      ) : (
        <Card className="overflow-hidden">
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {data.data.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div className="min-w-0">
                  <a href={`https://${d.hostname}`} target="_blank" rel="noopener noreferrer" className="font-medium hover:underline">{d.unicode_hostname}</a>
                  <p className="muted text-xs">{d.project && <Link to={`/projects/${d.project.slug}/domains`} className="hover:underline">{d.project.name}</Link>}{d.certificate.expires_at && ` · certificate until ${formatDateTime(d.certificate.expires_at)}`}</p>
                  {d.certificate.status === 'failed' && d.certificate.error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{d.certificate.error}</p>}
                </div>
                <DomainBadges domain={d} />
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}
