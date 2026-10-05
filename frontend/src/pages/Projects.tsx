import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Boxes, GitBranch, Globe, Plus, Search } from 'lucide-react'
import { api } from '../lib/api'
import { timeAgo } from '../lib/format'
import type { Project } from '../lib/types'
import { ButtonLink } from '../components/ui/Button'
import { Card, PageHeader } from '../components/ui/Card'
import { EmptyState, ErrorState, LoadingBlock } from '../components/ui/Feedback'
import { Input } from '../components/ui/Field'
import { DeploymentStatusBadge, ProjectStatusBadge } from '../components/ui/Status'

export default function Projects() {
  const [search, setSearch] = useState('')
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: () => api<{ data: Project[] }>('/projects'),
    refetchInterval: 15_000,
  })

  const projects = (data?.data ?? []).filter((p) => {
    const term = search.toLowerCase()
    return !term || p.name.toLowerCase().includes(term) || (p.primary_domain ?? '').includes(term) || (p.repository?.full_name ?? '').toLowerCase().includes(term)
  })

  return (
    <>
      <PageHeader title="Projects" description="Applications running on this server." actions={<ButtonLink to="/projects/new" variant="primary" icon={<Plus className="size-4" />}>New Project</ButtonLink>} />
      {error ? (
        <ErrorState error={error} retry={refetch} />
      ) : isLoading ? (
        <LoadingBlock />
      ) : data?.data.length === 0 ? (
        <Card>
          <EmptyState icon={<Boxes className="size-5" />} title="No projects yet" description="Create your first project to deploy an application." action={<ButtonLink to="/projects/new" variant="primary" icon={<Plus className="size-4" />}>New Project</ButtonLink>} />
        </Card>
      ) : (
        <>
          <div className="relative mb-4 max-w-sm">
            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" />
            <Input className="pl-9" placeholder="Filter projects" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Filter projects" />
          </div>
          <Card className="overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="muted border-b border-zinc-100 text-left text-xs dark:border-zinc-800">
                  <tr>
                    <th className="px-5 py-3 font-medium">Project</th>
                    <th className="px-5 py-3 font-medium">Status</th>
                    <th className="hidden px-5 py-3 font-medium md:table-cell">Source</th>
                    <th className="px-5 py-3 font-medium">Latest deployment</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                  {projects.map((p) => (
                    <tr key={p.slug} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                      <td className="px-5 py-3">
                        <Link to={`/projects/${p.slug}`} className="font-medium hover:underline">{p.name}</Link>
                        <div className="muted flex items-center gap-1 text-xs"><Globe className="size-3" /> {p.primary_domain ?? 'No domain'}</div>
                      </td>
                      <td className="px-5 py-3"><ProjectStatusBadge status={p.status} /></td>
                      <td className="muted hidden px-5 py-3 md:table-cell">
                        {p.repository ? (
                          <span className="flex items-center gap-1"><GitBranch className="size-3.5" /> {p.repository.full_name ?? p.repository.url} · {p.repository.branch}</span>
                        ) : (
                          <span className="mono">{p.image}</span>
                        )}
                      </td>
                      <td className="px-5 py-3">
                        {p.latest_deployment ? (
                          <div className="flex items-center gap-2">
                            <DeploymentStatusBadge status={p.latest_deployment.status} />
                            <span className="muted text-xs">#{p.latest_deployment.number} · {timeAgo(p.latest_deployment.created_at)}</span>
                          </div>
                        ) : (
                          <span className="muted text-xs">Never deployed</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </>
  )
}
