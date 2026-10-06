import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { Rocket, Undo2 } from 'lucide-react'
import { api } from '../../lib/api'
import { formatDuration, timeAgo } from '../../lib/format'
import type { Deployment, Paginated } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { EmptyState, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Badge, DeploymentStatusBadge } from '../../components/ui/Status'

export default function Deployments() {
  const project = useProject()
  const deploy = useDeploy(project.slug)
  const [page, setPage] = useState(1)
  const [rollbackTarget, setRollbackTarget] = useState<Deployment | null>(null)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['deployments', project.slug, page],
    queryFn: () => api<Paginated<Deployment>>(`/projects/${project.slug}/deployments`, { query: { page } }),
    refetchInterval: (q) => (q.state.data?.data.some((d) => d.is_active) ? 2500 : 15_000),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (isLoading || !data) return <LoadingBlock />
  if (data.data.length === 0) {
    return (
      <Card>
        <EmptyState icon={<Rocket className="size-5" />} title="No deployments yet" description="Deploy to build the latest commit and make it live." action={<Button variant="primary" onClick={() => deploy.mutate({ type: 'latest' })} loading={deploy.isPending}>Deploy now</Button>} />
      </Card>
    )
  }

  return (
    <>
      <Card className="overflow-hidden">
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {data.data.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
              <Link to={`${d.id}`} className="w-12 shrink-0 text-sm font-semibold hover:underline">#{d.number}</Link>
              <div className="min-w-0 flex-1">
                <Link to={`${d.id}`} className="flex min-w-0 items-center gap-2 text-sm">
                  {d.commit && <span className="mono shrink-0 rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{d.commit.short_sha}</span>}
                  <span className="truncate font-medium">{d.commit?.message ?? (d.type === 'deploy' ? 'Deployment' : d.type)}</span>
                </Link>
                <p className="muted mt-0.5 truncate text-xs">
                  {d.type === 'rollback' ? `Rollback to #${d.rollback_of?.number} · ` : d.type === 'redeploy' ? 'Redeploy with current settings · ' : ''}
                  {d.branch && `${d.branch} · `}{d.initiated_by ?? 'system'} · {timeAgo(d.created_at)}
                  {d.duration_seconds !== null && ` · ${formatDuration(d.duration_seconds)}`}
                </p>
                {d.status === 'failed' && d.failure && <p className="mt-1 truncate text-xs text-red-600 dark:text-red-400">{d.failure.reason}</p>}
              </div>
              <div className="flex items-center gap-2">
                {d.is_production && <Badge tone="green">Production</Badge>}
                <DeploymentStatusBadge status={d.status} />
                {d.status === 'success' && !d.is_production && d.image_available && (
                  <Button size="sm" icon={<Undo2 className="size-3.5" />} onClick={() => setRollbackTarget(d)}>Rollback</Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </Card>
      {data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</Button>
          <span className="muted">Page {data.meta.current_page} of {data.meta.last_page}</span>
          <Button size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Older</Button>
        </div>
      )}
      <ConfirmDialog
        open={!!rollbackTarget}
        onOpenChange={(o) => !o && setRollbackTarget(null)}
        title={`Roll back to deployment #${rollbackTarget?.number}?`}
        description="The image of that deployment is started next to the current version, health checked, and then receives the traffic. The current version keeps running until the switch succeeds. History is preserved."
        confirmLabel="Roll back"
        loading={deploy.isPending}
        onConfirm={() => rollbackTarget && deploy.mutate({ type: 'rollback', deploymentId: rollbackTarget.id }, { onSettled: () => setRollbackTarget(null) })}
      />
    </>
  )
}
