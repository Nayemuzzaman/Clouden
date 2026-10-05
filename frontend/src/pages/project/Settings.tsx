import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Eye } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../../lib/api'
import { formatBytes } from '../../lib/format'
import type { Operation, Project } from '../../lib/types'
import { useInvalidateProject, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { CopyButton } from '../../components/ui/Copy'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout } from '../../components/ui/Feedback'
import { Field, Input, Select, Switch } from '../../components/ui/Field'
import { CPU_OPTIONS, MEMORY_OPTIONS, memoryLabel } from '../NewProject'

type Form = Record<string, string | number>

function initialForm(p: Project): Form {
  return {
    name: p.name,
    branch: p.repository?.branch ?? '',
    repository: p.repository?.full_name ?? '',
    repository_url: p.repository?.url ?? '',
    image: p.image ?? '',
    dockerfile_path: p.dockerfile_path,
    build_context: p.build_context,
    port: p.port,
    memory_limit_mb: p.memory_limit_mb,
    cpu_limit: p.cpu_limit,
    health_check_type: p.health_check.type,
    health_check_path: p.health_check.path,
    health_check_status_min: p.health_check.status_min,
    health_check_status_max: p.health_check.status_max,
    health_check_timeout: p.health_check.timeout,
    health_check_retries: p.health_check.retries,
    health_check_interval: p.health_check.interval,
    image_retention: p.image_retention,
    backup_schedule: p.backup_schedule,
    backup_time: p.backup_time,
    backup_retention: p.backup_retention,
  }
}

function Section({ title, description, fields, form, setForm, errors, onSave, saving, children }: {
  title: string
  description?: string
  fields: string[]
  form: Form
  setForm: (f: Form) => void
  errors: Record<string, string[]>
  onSave: (fields: string[]) => void
  saving: boolean
  children: (bind: (name: string) => { value: string | number; onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void; invalid?: boolean }, err: (n: string) => string | undefined) => React.ReactNode
}) {
  const bind = (name: string) => ({
    value: form[name],
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [name]: e.target.type === 'number' ? Number(e.target.value) : e.target.value }),
    invalid: !!errors[name],
  })
  return (
    <Card>
      <CardHeader title={title} description={description} />
      <CardBody className="space-y-4">
        {children(bind, (n) => errors[n]?.[0])}
        <div className="flex justify-end"><Button variant="primary" loading={saving} onClick={() => onSave(fields)}>Save</Button></div>
      </CardBody>
    </Card>
  )
}

export default function ProjectSettings() {
  const project = useProject()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const invalidate = useInvalidateProject(project.slug)
  const [form, setForm] = useState<Form>(() => initialForm(project))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteOptions, setDeleteOptions] = useState({ delete_databases: false, delete_volumes: false, delete_backups: false })
  const [operationId, setOperationId] = useState<string | null>(null)
  const [secret, setSecret] = useState<string | null>(null)

  useEffect(() => setForm(initialForm(project)), [project.updated_at]) // eslint-disable-line react-hooks/exhaustive-deps

  const save = useMutation({
    mutationFn: (fields: string[]) => {
      const body: Record<string, unknown> = {}
      fields.forEach((f) => (body[f] = form[f]))
      return api<{ data: Project }>(`/projects/${project.slug}`, { method: 'PATCH', body })
    },
    onSuccess: () => {
      setErrors({})
      toast.success('Settings saved. They apply to the next deployment.')
      invalidate()
    },
    onError: (e) => {
      if (e instanceof ApiError) setErrors(e.errors)
      toast.error(errorMessage(e))
    },
  })

  const webhook = useQuery({ queryKey: ['webhook', project.slug], queryFn: () => api<{ url: string | null; installed: boolean; recent_events: { delivery_id: string; event: string; status: string; reason: string | null; created_at: string }[] }>(`/projects/${project.slug}/webhook`) })
  const autoDeploy = useMutation({
    mutationFn: (enabled: boolean) => api<{ auto_deploy: boolean; message: string | null }>(`/projects/${project.slug}/auto-deploy`, { method: 'PUT', body: { enabled } }),
    onSuccess: (r) => {
      toast[r.message ? 'warning' : 'success'](r.message ?? (r.auto_deploy ? 'Auto deploy enabled' : 'Auto deploy disabled'), { duration: r.message ? 12000 : 4000 })
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['webhook', project.slug] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const impact = useQuery({
    queryKey: ['deletion-impact', project.slug],
    queryFn: () => api<{ containers: number; deployments: number; domains: string[]; databases: { name: string; size_bytes: number | null }[]; volumes: { name: string; mount_path: string; size_bytes: number | null }[]; backups: number; environment_variables: number }>(`/projects/${project.slug}/deletion-impact`),
    enabled: deleteOpen,
  })
  const remove = useMutation({
    mutationFn: (confirm: string) => api<{ data: Operation }>(`/projects/${project.slug}`, { method: 'DELETE', body: { confirm, ...deleteOptions } }),
    onSuccess: (r) => {
      setOperationId(r.data.id)
      setDeleteOpen(false)
      toast.info('Deleting project…')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  useQuery({
    queryKey: ['operation', operationId],
    enabled: !!operationId,
    refetchInterval: 1500,
    queryFn: async () => {
      const op = (await api<{ data: Operation }>(`/operations/${operationId}`)).data
      if (op.status === 'success') {
        toast.success(`${project.name} was deleted`)
        queryClient.invalidateQueries({ queryKey: ['projects'] })
        navigate('/projects')
      } else if (op.status === 'failed') {
        toast.error(op.error ?? 'Deletion failed')
        setOperationId(null)
        invalidate()
      }
      return op
    },
  })

  const common = { form, setForm, errors, onSave: (f: string[]) => save.mutate(f), saving: save.isPending }

  return (
    <div className="space-y-6">
      <Section title="General" fields={['name']} {...common}>
        {(bind, err) => <Field label="Project name" error={err('name')}>{(id) => <Input id={id} {...bind('name')} />}</Field>}
      </Section>

      {project.source_type === 'image' ? (
        <Section title="Image" fields={['image']} {...common}>
          {(bind, err) => <Field label="Docker image" error={err('image')}>{(id) => <Input id={id} className="mono" {...bind('image')} />}</Field>}
        </Section>
      ) : (
        <Section title="Source & build" description="Changes apply to the next deployment." fields={project.source_type === 'github' ? ['repository', 'branch', 'dockerfile_path', 'build_context', 'port'] : ['repository_url', 'branch', 'dockerfile_path', 'build_context', 'port']} {...common}>
          {(bind, err) => (
            <div className="grid gap-4 sm:grid-cols-2">
              {project.source_type === 'github' ? (
                <Field label="Repository" error={err('repository')}>{(id) => <Input id={id} {...bind('repository')} />}</Field>
              ) : (
                <Field label="Repository URL" error={err('repository_url')}>{(id) => <Input id={id} {...bind('repository_url')} />}</Field>
              )}
              <Field label="Branch" error={err('branch')}>{(id) => <Input id={id} {...bind('branch')} />}</Field>
              <Field label="Dockerfile path" error={err('dockerfile_path')}>{(id) => <Input id={id} className="mono" {...bind('dockerfile_path')} />}</Field>
              <Field label="Build context" error={err('build_context')}>{(id) => <Input id={id} className="mono" {...bind('build_context')} />}</Field>
              <Field label="Application port" error={err('port')}>{(id) => <Input id={id} type="number" {...bind('port')} />}</Field>
            </div>
          )}
        </Section>
      )}

      <Section title="Resources" description="Limits protect the server from a single application using all CPU or memory." fields={['memory_limit_mb', 'cpu_limit', 'image_retention']} {...common}>
        {(bind, err) => (
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="RAM limit" error={err('memory_limit_mb')}>{(id) => <Select id={id} {...bind('memory_limit_mb')} onChange={(e) => setForm({ ...form, memory_limit_mb: Number(e.target.value) })}>{MEMORY_OPTIONS.map((m) => <option key={m} value={m}>{memoryLabel(m)}</option>)}</Select>}</Field>
            <Field label="CPU limit" error={err('cpu_limit')}>{(id) => <Select id={id} {...bind('cpu_limit')} onChange={(e) => setForm({ ...form, cpu_limit: Number(e.target.value) })}>{CPU_OPTIONS.map((c) => <option key={c} value={c}>{c} {c === 1 ? 'core' : 'cores'}</option>)}</Select>}</Field>
            <Field label="Images kept for rollback" error={err('image_retention')}>{(id) => <Input id={id} type="number" min={1} max={50} {...bind('image_retention')} />}</Field>
          </div>
        )}
      </Section>

      <Section title="Health check" description="A new version only receives traffic after passing this check." fields={['health_check_type', 'health_check_path', 'health_check_status_min', 'health_check_status_max', 'health_check_timeout', 'health_check_retries', 'health_check_interval']} {...common}>
        {(bind, err) => (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-[14rem_1fr]">
              <Field label="Type">{(id) => <Select id={id} {...bind('health_check_type')}><option value="http">HTTP request</option><option value="container">Container running only</option></Select>}</Field>
              {form.health_check_type === 'http' && <Field label="Path" error={err('health_check_path')}>{(id) => <Input id={id} className="mono" {...bind('health_check_path')} />}</Field>}
            </div>
            {form.health_check_type === 'http' ? (
              <div className="grid gap-4 sm:grid-cols-5">
                <Field label="Status from" error={err('health_check_status_min')}>{(id) => <Input id={id} type="number" {...bind('health_check_status_min')} />}</Field>
                <Field label="Status to" error={err('health_check_status_max')}>{(id) => <Input id={id} type="number" {...bind('health_check_status_max')} />}</Field>
                <Field label="Timeout (s)" error={err('health_check_timeout')}>{(id) => <Input id={id} type="number" {...bind('health_check_timeout')} />}</Field>
                <Field label="Attempts" error={err('health_check_retries')}>{(id) => <Input id={id} type="number" {...bind('health_check_retries')} />}</Field>
                <Field label="Interval (s)" error={err('health_check_interval')}>{(id) => <Input id={id} type="number" {...bind('health_check_interval')} />}</Field>
              </div>
            ) : (
              <Callout tone="warning">The container check only confirms that the process keeps running for a few seconds. It cannot tell whether your application actually responds to requests.</Callout>
            )}
          </div>
        )}
      </Section>

      <Card>
        <CardHeader title="Auto deploy" description="Deploy automatically when commits are pushed to the configured branch." />
        <CardBody className="space-y-4">
          <Switch checked={project.auto_deploy} disabled={autoDeploy.isPending || project.source_type === 'image'} onCheckedChange={(v) => autoDeploy.mutate(v)} label={project.auto_deploy ? 'On' : 'Off'} description={project.source_type === 'image' ? 'Not available for image-based projects.' : `Pushes to ${project.repository?.branch} trigger a deployment. Other branches are ignored.`} />
          {project.auto_deploy && webhook.data && (
            <div className="space-y-3 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-800">
              <p>{webhook.data.installed ? 'The GitHub webhook is installed.' : 'Add this webhook in your repository settings (Settings → Webhooks), content type application/json, event “push”:'}</p>
              <div className="flex items-center gap-2"><span className="muted w-16 shrink-0">URL</span><span className="mono truncate">{webhook.data.url ?? 'Set PC_DASHBOARD_DOMAIN first'}</span>{webhook.data.url && <CopyButton value={webhook.data.url} />}</div>
              <div className="flex items-center gap-2"><span className="muted w-16 shrink-0">Secret</span>
                {secret ? <><span className="mono truncate">{secret}</span><CopyButton value={secret} /></> : <Button size="sm" variant="ghost" icon={<Eye className="size-3.5" />} onClick={() => api<{ secret: string }>(`/projects/${project.slug}/webhook/reveal`, { method: 'POST' }).then((r) => setSecret(r.secret)).catch((e) => toast.error(errorMessage(e)))}>Reveal</Button>}
              </div>
              {webhook.data.recent_events.length > 0 && (
                <div>
                  <p className="muted mb-1 text-xs">Recent deliveries</p>
                  <ul className="space-y-1 text-xs">
                    {webhook.data.recent_events.map((e) => <li key={e.delivery_id} className="flex gap-2"><span className="mono">{e.event}</span><span className={e.status === 'accepted' ? 'text-emerald-600' : 'muted'}>{e.status}</span><span className="muted truncate">{e.reason}</span></li>)}
                  </ul>
                </div>
              )}
            </div>
          )}
        </CardBody>
      </Card>

      <Section title="Scheduled backups" description="Backs up the project's database and volumes. Times are in the server's time zone." fields={['backup_schedule', 'backup_time', 'backup_retention']} {...common}>
        {(bind, err) => (
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Schedule">{(id) => <Select id={id} {...bind('backup_schedule')}><option value="off">Off</option><option value="daily">Daily</option><option value="weekly">Weekly</option></Select>}</Field>
            <Field label="Time" error={err('backup_time')}>{(id) => <Input id={id} type="time" {...bind('backup_time')} />}</Field>
            <Field label="Keep last" error={err('backup_retention')} help="Scheduled backups per source">{(id) => <Input id={id} type="number" min={1} max={365} {...bind('backup_retention')} />}</Field>
          </div>
        )}
      </Section>

      <Card className="border-red-200 dark:border-red-900/60">
        <CardHeader title={<span className="flex items-center gap-2 text-red-700 dark:text-red-400"><AlertTriangle className="size-4" /> Danger zone</span>} />
        <CardBody className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p className="text-sm font-medium">Delete this project</p>
            <p className="muted text-sm">Stops the application and removes its containers, images, routing and history. Databases, volumes and backups are kept unless you choose to delete them.</p>
          </div>
          <Button variant="outline-danger" onClick={() => setDeleteOpen(true)} loading={!!operationId} disabled={project.deleting}>Delete project</Button>
        </CardBody>
      </Card>

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title={`Delete ${project.name}?`}
        destructive
        confirmText={project.name}
        confirmLabel="Delete project"
        loading={remove.isPending}
        onConfirm={(typed) => remove.mutate(typed)}
      >
        <div className="space-y-3 text-sm">
          <p>This will permanently remove:</p>
          <ul className="muted list-disc space-y-1 pl-5">
            <li>The running application and {impact.data?.deployments ?? '…'} deployment(s) with their images</li>
            <li>Routing for {impact.data?.domains.length ? impact.data.domains.join(', ') : 'no domains'}</li>
            <li>{impact.data?.environment_variables ?? '…'} environment variable(s)</li>
          </ul>
          {(impact.data?.databases.length ?? 0) + (impact.data?.volumes.length ?? 0) + (impact.data?.backups ?? 0) > 0 && <p className="font-medium">Data (kept unless selected):</p>}
          {impact.data?.databases.map((d) => (
            <label key={d.name} className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 size-4" checked={deleteOptions.delete_databases} onChange={(e) => setDeleteOptions({ ...deleteOptions, delete_databases: e.target.checked })} /> Also delete database <span className="mono">{d.name}</span> ({formatBytes(d.size_bytes)})</label>
          ))}
          {impact.data && impact.data.volumes.length > 0 && (
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 size-4" checked={deleteOptions.delete_volumes} onChange={(e) => setDeleteOptions({ ...deleteOptions, delete_volumes: e.target.checked })} /> Also delete volume data: {impact.data.volumes.map((v) => v.name).join(', ')}</label>
          )}
          {impact.data && impact.data.backups > 0 && (
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 size-4" checked={deleteOptions.delete_backups} onChange={(e) => setDeleteOptions({ ...deleteOptions, delete_backups: e.target.checked })} /> Also delete {impact.data.backups} backup(s)</label>
          )}
        </div>
      </ConfirmDialog>
    </div>
  )
}
