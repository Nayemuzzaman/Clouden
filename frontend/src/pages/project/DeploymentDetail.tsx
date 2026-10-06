import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, Undo2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatDateTime, formatDuration } from '../../lib/format'
import type { Deployment, LogLine } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, KeyValue } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Badge, DeploymentStatusBadge } from '../../components/ui/Status'
import { Segmented } from '../../components/ui/Tabs'
import { DeploymentTimeline } from '../../components/DeploymentTimeline'
import { LogViewer, MAX_LOG_LINES } from '../../components/LogViewer'

const stageNames: Record<string, string> = {
  cloning: 'fetching the source code',
  building: 'building the Docker image',
  starting: 'starting the container',
  health_checking: 'the health check',
  routing: 'switching traffic',
}

/** Incrementally polls deployment logs using the last seen line id. */
function useDeploymentLogs(slug: string, id: number, active: boolean) {
  const [lines, setLines] = useState<LogLine[]>([])
  const lastId = useRef(0)
  const queryClient = useQueryClient()

  useEffect(() => {
    setLines([])
    lastId.current = 0
  }, [id])

  useQuery({
    queryKey: ['deployment-logs', slug, id],
    queryFn: async () => {
      const r = await api<{ lines: LogLine[]; status: string; has_more: boolean }>(`/projects/${slug}/deployments/${id}/logs`, { query: { after_id: lastId.current } })
      if (r.lines.length) {
        lastId.current = r.lines[r.lines.length - 1].id ?? lastId.current
        setLines((prev) => [...prev, ...r.lines].slice(-MAX_LOG_LINES))
      }
      if (r.has_more) queryClient.invalidateQueries({ queryKey: ['deployment-logs', slug, id] })
      return r.status
    },
    refetchInterval: active ? 1500 : false,
  })

  return lines
}

export default function DeploymentDetail() {
  const project = useProject()
  const { deploymentId } = useParams()
  const id = Number(deploymentId)
  const deploy = useDeploy(project.slug)
  const queryClient = useQueryClient()
  const [view, setView] = useState<'build' | 'container'>('build')
  const [confirmRollback, setConfirmRollback] = useState(false)

  const { data: deployment, error, refetch } = useQuery({
    queryKey: ['deployment', project.slug, id],
    queryFn: () => api<{ data: Deployment }>(`/projects/${project.slug}/deployments/${id}`).then((r) => r.data),
    refetchInterval: (q) => (q.state.data?.is_active ? 1500 : false),
  })
  const active = deployment?.is_active ?? true
  const lines = useDeploymentLogs(project.slug, id, active)

  useEffect(() => {
    if (deployment && !deployment.is_active) queryClient.invalidateQueries({ queryKey: ['project', project.slug] })
  }, [deployment?.is_active]) // eslint-disable-line react-hooks/exhaustive-deps

  const containerLogs = useQuery({
    queryKey: ['deployment-container-logs', project.slug, id],
    queryFn: () => api<{ lines: LogLine[]; message?: string }>(`/projects/${project.slug}/deployments/${id}/container-logs`),
    enabled: view === 'container',
    refetchInterval: view === 'container' ? 5000 : false,
  })

  const cancel = useMutation({
    mutationFn: () => api<{ message: string }>(`/projects/${project.slug}/deployments/${id}/cancel`, { method: 'POST' }),
    onSuccess: (r) => {
      toast.success(r.message)
      refetch()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (!deployment) return <LoadingBlock />
  const f = deployment.failure

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <Link to=".." relative="path" className="muted text-sm hover:underline">Deployments</Link>
          <span className="muted">/</span>
          <h2 className="text-lg font-semibold">Deployment #{deployment.number}</h2>
          <DeploymentStatusBadge status={deployment.status} />
          {deployment.is_production && <Badge tone="green">Production</Badge>}
        </div>
        <div className="flex gap-2">
          {deployment.is_active && <Button icon={<Ban className="size-4" />} loading={cancel.isPending} onClick={() => cancel.mutate()}>Cancel</Button>}
          {deployment.status === 'success' && !deployment.is_production && deployment.image_available && (
            <Button icon={<Undo2 className="size-4" />} onClick={() => setConfirmRollback(true)}>Rollback to this version</Button>
          )}
        </div>
      </div>

      <Card>
        <CardBody className="space-y-5">
          <DeploymentTimeline deployment={deployment} />
          <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <KeyValue label="Commit">{deployment.commit ? <span className="mono">{deployment.commit.short_sha}</span> : '—'} <span className="muted">{deployment.commit?.message}</span></KeyValue>
            <KeyValue label="Branch">{deployment.branch ?? '—'}</KeyValue>
            <KeyValue label="Started">{formatDateTime(deployment.started_at ?? deployment.queued_at)}</KeyValue>
            <KeyValue label="Duration">{formatDuration(deployment.duration_seconds)}{deployment.build_duration_seconds !== null && <span className="muted"> · build {formatDuration(deployment.build_duration_seconds)}</span>}</KeyValue>
            <KeyValue label="Initiated by">{deployment.initiated_by ?? 'System'}</KeyValue>
            <KeyValue label="Type">{deployment.type === 'rollback' ? `Rollback to #${deployment.rollback_of?.number}` : deployment.type === 'redeploy' ? 'Redeploy' : 'Deploy'} · {deployment.trigger}</KeyValue>
            <KeyValue label="Image" mono>{deployment.image_tag ?? '—'}{deployment.image_id && <span className="muted"> ({deployment.image_id})</span>}</KeyValue>
            <KeyValue label="Container" mono>{deployment.container_name ?? '—'}</KeyValue>
          </dl>
        </CardBody>
      </Card>

      {f && deployment.status === 'failed' && (
        <Callout tone="error" title="Deployment failed">
          <div className="space-y-2">
            <p>The deployment stopped while {stageNames[f.stage] ?? f.stage}.{project.current_deployment && project.current_deployment.id !== deployment.id && ` Deployment #${project.current_deployment.number} is still serving traffic.`}</p>
            {f.step && <p><span className="font-medium">Step:</span> <code className="mono">{f.step}</code></p>}
            <p><span className="font-medium">Error:</span> <span className="mono break-all">{f.reason}</span></p>
            {f.excerpt && (
              <details>
                <summary className="cursor-pointer font-medium">Show error output</summary>
                <pre className="mono mt-2 max-h-64 overflow-auto rounded-md bg-zinc-950 p-3 text-xs whitespace-pre-wrap text-zinc-200">{f.excerpt}</pre>
              </details>
            )}
          </div>
        </Callout>
      )}

      <div className="space-y-3">
        <Segmented value={view} onChange={setView} label="Log type" options={[{ value: 'build', label: 'Deployment log' }, { value: 'container', label: 'Container log' }]} />
        {view === 'build' ? (
          <LogViewer lines={lines} live={active} showStream emptyText={active ? 'Waiting for output…' : 'No output was recorded.'} />
        ) : (
          <LogViewer lines={containerLogs.data?.lines ?? []} emptyText={containerLogs.data?.message ?? 'No container output.'} />
        )}
      </div>

      <ConfirmDialog
        open={confirmRollback}
        onOpenChange={setConfirmRollback}
        title={`Roll back to deployment #${deployment.number}?`}
        description="This version is started, health checked and then receives the traffic. The current version keeps running until the switch succeeds."
        confirmLabel="Roll back"
        loading={deploy.isPending}
        onConfirm={() => deploy.mutate({ type: 'rollback', deploymentId: deployment.id })}
      />
    </div>
  )
}
