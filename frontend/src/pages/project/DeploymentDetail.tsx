import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, ExternalLink, RotateCw, ScrollText, Undo2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatDateTime, formatDuration } from '../../lib/format'
import type { Deployment, LogLine } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button, ExternalButton } from '../../components/ui/Button'
import { Card, CardBody, KeyValue } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Badge, DeploymentStatusBadge } from '../../components/ui/Status'
import { Segmented } from '../../components/ui/Tabs'
import { DeploymentTimeline } from '../../components/DeploymentTimeline'
import { LogViewer, MAX_LOG_LINES } from '../../components/LogViewer'
import { productionUrl, sourceLabel, triggerLabel } from '../../lib/production'

const failureTitle: Record<string, string> = {
  cloning: 'Deployment failed: the source could not be fetched',
  building: 'Deployment failed: the Docker build failed',
  starting: 'Deployment failed: the container did not start',
  health_checking: 'Deployment failed: the health check failed',
  routing: 'Deployment failed: traffic could not be switched',
}

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
  const logRef = useRef<HTMLDivElement>(null)
  const live = project.current_deployment
  const url = productionUrl(project)

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

      {f && deployment.status === 'failed' && (
        <Callout tone="error" title={failureTitle[f.stage] ?? 'Deployment failed'}>
          <div className="space-y-2">
            <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-[auto_1fr]">
              {deployment.commit && <><dt className="font-medium">Commit</dt><dd className="mono">{deployment.commit.short_sha} <span className="font-sans opacity-80">{deployment.commit.message}</span></dd></>}
              {deployment.branch && <><dt className="font-medium">Branch</dt><dd>{deployment.branch}</dd></>}
              <dt className="font-medium">Failed while</dt><dd>{stageNames[f.stage] ?? f.stage}{deployment.finished_at ? ` · ${formatDateTime(deployment.finished_at)}` : ''}</dd>
              {f.step && <><dt className="font-medium">Step</dt><dd><code className="mono">{f.step}</code></dd></>}
            </dl>
            <p><span className="font-medium">Error:</span> <span className="mono break-all">{f.reason}</span></p>
            {live && live.id !== deployment.id ? (
              <p className="font-medium">Production is still running: <span className="mono">{live.commit?.short_sha ?? `#${live.number}`}</span> (deployment #{live.number}).</p>
            ) : !live ? <p>Nothing is live yet.</p> : null}
            {f.excerpt && (
              <details>
                <summary className="cursor-pointer font-medium">Show error output</summary>
                <pre className="mono mt-2 max-h-64 overflow-auto rounded-md bg-zinc-950 p-3 text-xs whitespace-pre-wrap text-zinc-200">{f.excerpt}</pre>
              </details>
            )}
            <div className="flex flex-wrap gap-2 pt-1">
              <Button size="sm" icon={<ScrollText className="size-3.5" />} onClick={() => { setView('build'); logRef.current?.scrollIntoView({ behavior: 'smooth' }) }}>View build log</Button>
              {deployment.type === 'deploy' && deployment.commit && (
                <Button size="sm" icon={<RotateCw className="size-3.5" />} loading={deploy.isPending} onClick={() => deploy.mutate({ type: 'commit', sha: deployment.commit!.sha })}>Retry</Button>
              )}
              {url && live && <ExternalButton size="sm" href={url} icon={<ExternalLink className="size-3.5" />}>Open production</ExternalButton>}
            </div>
          </div>
        </Callout>
      )}

      <Card>
        <CardBody className="space-y-5">
          <DeploymentTimeline deployment={deployment} />
          <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <KeyValue label="Commit">{deployment.commit ? <span className="mono break-all" title={deployment.commit.sha}>{deployment.commit.sha}</span> : '—'} <span className="muted block">{deployment.commit?.message}</span></KeyValue>
            <KeyValue label="Source">{sourceLabel(deployment)}</KeyValue>
            <KeyValue label="Repository">{deployment.source?.repository ?? '—'}{deployment.source?.visibility && <span className="muted"> · {deployment.source.visibility === 'private' ? 'Private' : 'Public'}</span>}</KeyValue>
            <KeyValue label="Branch">{deployment.branch ?? '—'}</KeyValue>
            <KeyValue label="Trigger">{triggerLabel(deployment)}</KeyValue>
            <KeyValue label="Production">{deployment.is_production ? <Badge tone="green">Yes, live</Badge> : 'No'}</KeyValue>
            <KeyValue label="Initiated by">{deployment.initiated_by ?? 'System'}</KeyValue>
            <KeyValue label="Started">{formatDateTime(deployment.started_at ?? deployment.queued_at)}</KeyValue>
            <KeyValue label="Completed">{formatDateTime(deployment.finished_at)}</KeyValue>
            <KeyValue label="Duration">{formatDuration(deployment.duration_seconds)}{deployment.build_duration_seconds !== null && <span className="muted"> · build {formatDuration(deployment.build_duration_seconds)}</span>}</KeyValue>
            <KeyValue label="Image" mono>{deployment.image_tag ?? '—'}{deployment.image_id && <span className="muted"> ({deployment.image_id})</span>}</KeyValue>
            <KeyValue label="Container" mono>{deployment.container_name ?? '—'}</KeyValue>
          </dl>
          {deployment.status === 'superseded' && <p className="muted text-sm">{deployment.failure?.reason ?? 'A newer deployment replaced this one before it started.'}</p>}
        </CardBody>
      </Card>

      <div className="space-y-3" ref={logRef}>
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
