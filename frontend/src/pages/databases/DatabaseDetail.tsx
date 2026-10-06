import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatBytes } from '../../lib/format'
import type { Database } from '../../lib/types'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Badge } from '../../components/ui/Status'
import { Segmented } from '../../components/ui/Tabs'
import { DatabaseConnection } from '../../components/DatabaseConnection'
import { BackupsTable } from '../../components/BackupsTable'
import TableBrowser from './TableBrowser'
import SqlEditor from './SqlEditor'

type View = 'tables' | 'sql' | 'backups' | 'connection'

export default function DatabaseDetail() {
  const { databaseId = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [view, setView] = useState<View>('tables')
  const [resetOpen, setResetOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const { data: db, error, refetch } = useQuery({ queryKey: ['database', databaseId], queryFn: () => api<{ data: Database }>(`/databases/${databaseId}`).then((r) => r.data) })

  const backup = useMutation({
    mutationFn: () => api('/backups', { method: 'POST', body: { database: databaseId } }),
    onSuccess: () => {
      toast.success('Backup started')
      queryClient.invalidateQueries({ queryKey: ['backups'] })
      setView('backups')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const reset = useMutation({
    mutationFn: () => api<{ message: string }>(`/databases/${databaseId}/reset-credentials`, { method: 'POST' }),
    onSuccess: (r) => {
      toast.success(r.message, { duration: 10000 })
      setResetOpen(false)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const retry = useMutation({ mutationFn: () => api(`/databases/${databaseId}/retry`, { method: 'POST' }), onSuccess: () => refetch(), onError: (e) => toast.error(errorMessage(e)) })
  const remove = useMutation({
    mutationFn: (confirm: string) => api(`/databases/${databaseId}`, { method: 'DELETE', body: { confirm } }),
    onSuccess: () => {
      toast.success('Database deleted')
      queryClient.invalidateQueries({ queryKey: ['databases'] })
      navigate('/databases')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (!db) return <LoadingBlock />

  return (
    <>
      <div className="mb-5">
        <div className="muted mb-1 text-sm"><Link to="/databases" className="hover:underline">Databases</Link></div>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h1 className="mono text-2xl font-semibold tracking-tight">{db.name}</h1>
            <Badge tone={db.status === 'ready' ? 'green' : db.status === 'failed' ? 'red' : 'amber'}>{db.status}</Badge>
            {db.size_bytes !== null && <span className="muted text-sm">{formatBytes(db.size_bytes)}</span>}
          </div>
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => backup.mutate()} loading={backup.isPending} disabled={db.status !== 'ready'}>Backup Now</Button>
          </div>
        </div>
        {db.project && <p className="muted mt-1 text-sm">Used by <Link to={`/projects/${db.project.slug}`} className="underline">{db.project.name}</Link></p>}
      </div>

      {db.status === 'failed' && (
        <div className="mb-4"><Callout tone="error" title="Provisioning failed" action={<Button size="sm" onClick={() => retry.mutate()} loading={retry.isPending}>Retry</Button>}>{db.last_error}</Callout></div>
      )}

      <div className="mb-4">
        <Segmented value={view} onChange={setView} label="Database section" options={[{ value: 'tables', label: 'Tables' }, { value: 'sql', label: 'SQL Editor' }, { value: 'backups', label: 'Backups' }, { value: 'connection', label: 'Connection & settings' }]} />
      </div>

      {db.status === 'ready' && view === 'tables' && <TableBrowser databaseId={db.id} />}
      {db.status === 'ready' && view === 'sql' && <SqlEditor databaseId={db.id} />}
      {view === 'backups' && <Card><CardHeader title="Backups of this database" /><BackupsTable type="database" project={db.project?.slug} /></Card>}
      {view === 'connection' && (
        <div className="space-y-6">
          <Card><CardHeader title="Connection" /><CardBody><DatabaseConnection database={db} /></CardBody></Card>
          <Card>
            <CardHeader title="Reset credentials" description="Generates a new password. If the database belongs to a project, its DB_PASSWORD and DATABASE_URL are updated; redeploy the project afterwards." />
            <CardBody><Button onClick={() => setResetOpen(true)}>Reset password</Button></CardBody>
          </Card>
          <Card className="border-red-200 dark:border-red-900/60">
            <CardHeader title={<span className="text-red-700 dark:text-red-400">Delete database</span>} description="Permanently deletes the database and its user. Existing backups are kept." />
            <CardBody><Button variant="outline-danger" onClick={() => setDeleteOpen(true)}>Delete database</Button></CardBody>
          </Card>
        </div>
      )}

      <ConfirmDialog open={resetOpen} onOpenChange={setResetOpen} title="Reset database password?" description="Applications using the old password lose access until they are redeployed with the new one." confirmLabel="Reset password" destructive loading={reset.isPending} onConfirm={() => reset.mutate()} />
      <ConfirmDialog open={deleteOpen} onOpenChange={setDeleteOpen} title={`Delete database ${db.name}?`} description="All tables and data in this database are permanently deleted. Take a backup first if you might need the data." confirmLabel="Delete database" destructive confirmText={db.name} loading={remove.isPending} onConfirm={(typed) => remove.mutate(typed)} />
    </>
  )
}
