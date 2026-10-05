import { useState } from 'react'
import { Link, Outlet, useLocation, useParams } from 'react-router'
import { useMutation } from '@tanstack/react-query'
import { ExternalLink, Play, RotateCw, Rocket, ScrollText, Square } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { useDeploy, useInvalidateProject, useProjectQuery } from '../../hooks/project'
import { Button, ButtonLink, ExternalButton } from '../../components/ui/Button'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { ProjectStatusBadge } from '../../components/ui/Status'
import { RouteTabs } from '../../components/ui/Tabs'

export default function ProjectLayout() {
  const { slug = '' } = useParams()
  const location = useLocation()
  const { data: project, isLoading, error, refetch } = useProjectQuery(slug)
  const deploy = useDeploy(slug)
  const invalidate = useInvalidateProject(slug)
  const [confirmStop, setConfirmStop] = useState(false)

  const action = useMutation({
    mutationFn: (kind: 'start' | 'stop' | 'restart') => api<{ status: string }>(`/projects/${slug}/${kind}`, { method: 'POST' }),
    onSuccess: (_, kind) => {
      invalidate()
      setConfirmStop(false)
      toast.success({ start: 'Application started', stop: 'Application stopped', restart: 'Application restarted' }[kind])
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading) return <LoadingBlock />
  if (error || !project) return <ErrorState error={error ?? new Error('Project not found')} retry={refetch} />

  const base = `/projects/${slug}`
  const deployed = !!project.current_deployment
  const primary = project.domains?.find((d) => d.is_primary)
  const url = project.primary_domain ? `${primary?.certificate.status === 'disabled' ? 'http' : 'https'}://${project.primary_domain}` : null
  const justCreated = (location.state as { justCreated?: boolean } | null)?.justCreated && !project.latest_deployment

  return (
    <>
      <div className="mb-5">
        <div className="muted mb-1 text-sm"><Link to="/projects" className="hover:underline">Projects</Link></div>
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex min-w-0 items-center gap-3">
            <h1 className="truncate text-2xl font-semibold tracking-tight">{project.name}</h1>
            <ProjectStatusBadge status={project.status} />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" icon={<Rocket className="size-4" />} loading={deploy.isPending} disabled={project.deleting} onClick={() => deploy.mutate({ type: 'latest' })}>
              {project.source_type === 'image' ? 'Deploy' : 'Deploy Latest'}
            </Button>
            {deployed && (
              <>
                <Button icon={<RotateCw className="size-4" />} loading={action.isPending && action.variables === 'restart'} onClick={() => action.mutate('restart')}>Restart</Button>
                {project.status === 'stopped' ? (
                  <Button icon={<Play className="size-4" />} loading={action.isPending && action.variables === 'start'} onClick={() => action.mutate('start')}>Start</Button>
                ) : (
                  <Button icon={<Square className="size-4" />} onClick={() => setConfirmStop(true)}>Stop</Button>
                )}
              </>
            )}
            {url && deployed && <ExternalButton href={url} icon={<ExternalLink className="size-4" />}>Open Website</ExternalButton>}
            <ButtonLink to={`${base}/logs`} icon={<ScrollText className="size-4" />}>View Logs</ButtonLink>
          </div>
        </div>
      </div>

      {project.deleting && <div className="mb-4"><Callout tone="warning" title="This project is being deleted">Resources are being removed in the background.</Callout></div>}
      {justCreated && (
        <div className="mb-4">
          <Callout tone="success" title="Project created" action={<Button size="sm" variant="primary" onClick={() => deploy.mutate({ type: 'latest' })} loading={deploy.isPending}>Deploy now</Button>}>
            Everything is set up. Deploy to build and start the application.
          </Callout>
        </div>
      )}

      <RouteTabs
        tabs={[
          { to: base, label: 'Overview', end: true },
          { to: `${base}/deployments`, label: 'Deployments' },
          { to: `${base}/database`, label: 'Database' },
          { to: `${base}/storage`, label: 'Storage' },
          { to: `${base}/domains`, label: 'Domains' },
          { to: `${base}/environment`, label: 'Environment' },
          { to: `${base}/logs`, label: 'Logs' },
          { to: `${base}/backups`, label: 'Backups' },
          { to: `${base}/monitoring`, label: 'Monitoring' },
          { to: `${base}/settings`, label: 'Settings' },
        ]}
      />
      <div className="pt-6">
        <Outlet context={{ project }} />
      </div>

      <ConfirmDialog
        open={confirmStop}
        onOpenChange={setConfirmStop}
        title="Stop application?"
        description="The website will show a “not running” page until you start it again. Data is not affected."
        confirmLabel="Stop application"
        destructive
        loading={action.isPending}
        onConfirm={() => action.mutate('stop')}
      />
    </>
  )
}
