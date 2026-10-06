import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Database as DatabaseIcon, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../../lib/api'
import { formatBytes, timeAgo } from '../../lib/format'
import type { Database, Project } from '../../lib/types'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Dialog } from '../../components/ui/Dialog'
import { EmptyState, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Badge, JobStatusBadge } from '../../components/ui/Status'

export default function Databases() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['databases'], queryFn: () => api<{ data: Database[] }>('/databases') })
  const projects = useQuery({ queryKey: ['projects'], queryFn: () => api<{ data: Project[] }>('/projects') })
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [project, setProject] = useState('')
  const [fieldError, setFieldError] = useState<string | undefined>()

  const create = useMutation({
    mutationFn: () => api<{ data: Database }>('/databases', { method: 'POST', body: { name, project: project || null } }),
    onSuccess: (r) => {
      toast.success(`Database ${r.data.name} created`)
      queryClient.invalidateQueries({ queryKey: ['databases'] })
      setOpen(false)
      navigate(`/databases/${r.data.id}`)
    },
    onError: (e) => setFieldError(e instanceof ApiError ? e.field('name') ?? e.message : errorMessage(e)),
  })

  const availableProjects = (projects.data?.data ?? []).filter((p) => !p.database)

  return (
    <>
      <PageHeader title="Databases" description="PostgreSQL databases on this server. Each has its own user and can only be reached from inside the server." actions={<Button variant="primary" icon={<Plus className="size-4" />} onClick={() => { setName(''); setProject(''); setFieldError(undefined); setOpen(true) }}>Create Database</Button>} />
      {error ? <ErrorState error={error} retry={refetch} /> : isLoading || !data ? <LoadingBlock /> : data.data.length === 0 ? (
        <Card><EmptyState icon={<DatabaseIcon className="size-5" />} title="No databases yet" description="Create a database here or from a project's Database tab." /></Card>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="muted border-b border-zinc-100 text-left text-xs dark:border-zinc-800">
                <tr><th className="px-5 py-3 font-medium">Database</th><th className="px-5 py-3 font-medium">Project</th><th className="px-5 py-3 font-medium">Size</th><th className="px-5 py-3 font-medium">Last backup</th><th className="px-5 py-3 font-medium">Status</th></tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {data.data.map((db) => (
                  <tr key={db.id} className="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                    <td className="px-5 py-3"><Link to={`/databases/${db.id}`} className="mono font-medium hover:underline">{db.name}</Link><div className="muted text-xs">PostgreSQL · user {db.username}</div></td>
                    <td className="px-5 py-3">{db.project ? <Link to={`/projects/${db.project.slug}`} className="hover:underline">{db.project.name}</Link> : <span className="muted">Standalone</span>}</td>
                    <td className="px-5 py-3 tabular-nums">{formatBytes(db.size_bytes)}</td>
                    <td className="px-5 py-3">{db.last_backup ? <span className="flex items-center gap-2"><JobStatusBadge status={db.last_backup.status} /> <span className="muted text-xs">{timeAgo(db.last_backup.created_at)}</span></span> : <span className="muted">Never</span>}</td>
                    <td className="px-5 py-3"><Badge tone={db.status === 'ready' ? 'green' : db.status === 'failed' ? 'red' : 'amber'}>{db.status}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <Dialog open={open} onOpenChange={setOpen} title="Create Database" description="A PostgreSQL database and a dedicated user with a strong random password are created." footer={<><Button onClick={() => setOpen(false)}>Cancel</Button><Button variant="primary" loading={create.isPending} disabled={!name} onClick={() => create.mutate()}>Create Database</Button></>}>
        <div className="space-y-4">
          <Field label="Name" error={fieldError} help="Lowercase letters, digits and underscores. Also used as the username.">
            {(id) => <Input id={id} className="mono" value={name} onChange={(e) => setName(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))} placeholder="my_app" autoFocus />}
          </Field>
          <Field label="Attach to project" optional help="Connection variables are added to the project's environment.">
            {(id) => <Select id={id} value={project} onChange={(e) => setProject(e.target.value)}><option value="">No project (standalone)</option>{availableProjects.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}</Select>}
          </Field>
        </div>
      </Dialog>
    </>
  )
}
