import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { HardDrive, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../../lib/api'
import { formatBytes } from '../../lib/format'
import type { Volume } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { Callout, EmptyState, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'

export default function Storage() {
  const project = useProject()
  const queryClient = useQueryClient()
  const deploy = useDeploy(project.slug)
  const base = `/projects/${project.slug}/volumes`
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['volumes', project.slug], queryFn: () => api<{ data: Volume[] }>(base) })
  const [name, setName] = useState('')
  const [mountPath, setMountPath] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [added, setAdded] = useState(false)
  const [removing, setRemoving] = useState<Volume | null>(null)
  const [deleteData, setDeleteData] = useState(false)

  const add = useMutation({
    mutationFn: () => api(base, { method: 'POST', body: { name, mount_path: mountPath } }),
    onSuccess: () => {
      toast.success('Volume added')
      setName('')
      setMountPath('')
      setErrors({})
      setAdded(true)
      queryClient.invalidateQueries({ queryKey: ['volumes', project.slug] })
    },
    onError: (e) => (e instanceof ApiError ? setErrors(e.errors) : toast.error(errorMessage(e))),
  })
  const remove = useMutation({
    mutationFn: ({ volume, confirm }: { volume: Volume; confirm: string }) => api(`${base}/${volume.id}`, { method: 'DELETE', body: { delete_data: deleteData, confirm } }),
    onSuccess: () => {
      toast.success('Volume removed')
      setRemoving(null)
      queryClient.invalidateQueries({ queryKey: ['volumes', project.slug] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (isLoading || !data) return <LoadingBlock />

  return (
    <div className="space-y-6">
      {added && (
        <Callout tone="info" title="Redeploy to mount new volumes" action={<Button size="sm" variant="primary" onClick={() => deploy.mutate({ type: 'redeploy' })} loading={deploy.isPending}>Redeploy</Button>}>
          Volumes are attached when the container is created.
        </Callout>
      )}
      <Card>
        <CardHeader title="Persistent storage" description="Files written to a volume survive deployments and restarts. Everything else in the container is replaced on each deploy." />
        <form className="grid gap-3 border-b border-zinc-100 px-5 py-4 sm:grid-cols-[12rem_1fr_auto] sm:items-end dark:border-zinc-800" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
          <Field label="Name" error={errors.name?.[0]}>{(id) => <Input id={id} placeholder="uploads" value={name} onChange={(e) => setName(e.target.value.toLowerCase())} />}</Field>
          <Field label="Mount path in container" error={errors.mount_path?.[0]}>{(id) => <Input id={id} className="mono" placeholder="/app/storage" value={mountPath} onChange={(e) => setMountPath(e.target.value)} />}</Field>
          <Button type="submit" variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={!name || !mountPath}>Add volume</Button>
        </form>
        {data.data.length === 0 ? (
          <EmptyState icon={<HardDrive className="size-5" />} title="No volumes" description="Add a volume for uploads, caches or any data your application must keep." />
        ) : (
          <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="muted text-left text-xs"><tr><th className="px-5 py-2 font-medium">Volume</th><th className="px-5 py-2 font-medium">Mount path</th><th className="px-5 py-2 font-medium">Disk usage</th><th /></tr></thead>
            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {data.data.map((v) => (
                <tr key={v.id}>
                  <td className="px-5 py-3 font-medium">{v.name}</td>
                  <td className="mono px-5 py-3">{v.mount_path}</td>
                  <td className="px-5 py-3 tabular-nums">{formatBytes(v.size_bytes)}</td>
                  <td className="px-5 py-3 text-right"><Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => { setDeleteData(false); setRemoving(v) }} aria-label={`Remove ${v.name}`} /></td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        )}
      </Card>
      <ConfirmDialog
        open={!!removing}
        onOpenChange={(o) => !o && setRemoving(null)}
        title={`Remove volume ${removing?.name}?`}
        description="The volume is detached on the next deployment."
        confirmLabel={deleteData ? 'Remove and delete data' : 'Remove volume'}
        destructive
        confirmText={deleteData ? removing?.name : undefined}
        loading={remove.isPending}
        onConfirm={(typed) => removing && remove.mutate({ volume: removing, confirm: typed })}
      >
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4" checked={deleteData} onChange={(e) => setDeleteData(e.target.checked)} />
          <span>Also permanently delete the data stored in this volume. <span className="muted">Without this, the data is kept on the server.</span></span>
        </label>
      </ConfirmDialog>
    </div>
  )
}
