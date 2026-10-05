import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Download, RotateCcw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, buildUrl, errorMessage } from '../lib/api'
import { formatBytes, formatDateTime, timeAgo } from '../lib/format'
import type { Backup, Operation, Paginated } from '../lib/types'
import { Button } from './ui/Button'
import { ConfirmDialog } from './ui/Dialog'
import { EmptyState, ErrorState, LoadingBlock } from './ui/Feedback'
import { Badge, JobStatusBadge } from './ui/Status'

/** Polls a long-running operation (e.g. a restore) until it finishes. */
function useOperation(id: string | null, onDone: (op: Operation) => void) {
  const query = useQuery({
    queryKey: ['operation', id],
    queryFn: () => api<{ data: Operation }>(`/operations/${id}`).then((r) => r.data),
    enabled: !!id,
    refetchInterval: (q) => (q.state.data && ['success', 'failed'].includes(q.state.data.status) ? false : 1500),
  })
  useEffect(() => {
    if (query.data && ['success', 'failed'].includes(query.data.status)) onDone(query.data)
  }, [query.data?.status]) // eslint-disable-line react-hooks/exhaustive-deps
  return query.data
}

export function BackupsTable({ project, type, showProject = false }: { project?: string; type?: string; showProject?: boolean }) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [restoring, setRestoring] = useState<Backup | null>(null)
  const [deleting, setDeleting] = useState<Backup | null>(null)
  const [operationId, setOperationId] = useState<string | null>(null)
  const [restoreError, setRestoreError] = useState<string | null>(null)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['backups', project ?? 'all', type ?? 'all', page],
    queryFn: () => api<Paginated<Backup>>('/backups', { query: { project, type, page } }),
    refetchInterval: (q) => (q.state.data?.data.some((b) => b.status === 'queued' || b.status === 'running') ? 2000 : 30_000),
  })

  const operation = useOperation(operationId, (op) => {
    if (op.status === 'success') toast.success(op.message ?? 'Restore completed')
    else toast.error(`Restore failed: ${op.error}`, { duration: 15000 })
    setOperationId(null)
    queryClient.invalidateQueries({ queryKey: ['backups'] })
  })

  const restore = useMutation({
    mutationFn: ({ backup, confirm }: { backup: Backup; confirm: string }) => api<{ data: Operation }>(`/backups/${backup.id}/restore`, { method: 'POST', body: { confirm } }),
    onSuccess: (r) => {
      setRestoring(null)
      setOperationId(r.data.id)
      toast.info('Restore started. A safety backup of the current data is taken first.')
    },
    onError: (e) => setRestoreError(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (b: Backup) => api(`/backups/${b.id}`, { method: 'DELETE' }),
    onSuccess: () => {
      setDeleting(null)
      toast.success('Backup deleted')
      queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (isLoading || !data) return <LoadingBlock />
  if (data.data.length === 0) return <EmptyState icon={<Archive className="size-5" />} title="No backups yet" description="Create a backup now or enable scheduled backups in the project settings." />

  const sourceName = (b: Backup) => b.database ?? b.volume ?? ''

  // Backups contain secrets: confirm the password first (dialog if needed), then let the browser stream the file.
  const download = async (b: Backup) => {
    try {
      await api('/auth/recent', { method: 'POST' })
      window.location.assign(buildUrl(`/backups/${b.id}/download`))
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <>
      {operation && !['success', 'failed'].includes(operation.status) && (
        <div className="border-b border-zinc-100 bg-sky-50 px-5 py-3 text-sm text-sky-900 dark:border-zinc-800 dark:bg-sky-950/40 dark:text-sky-200">Restore in progress: {operation.message ?? 'starting…'}</div>
      )}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="muted text-left text-xs">
            <tr>
              <th className="px-5 py-2 font-medium">Backup</th>
              {showProject && <th className="px-5 py-2 font-medium">Project</th>}
              <th className="px-5 py-2 font-medium">Status</th>
              <th className="px-5 py-2 font-medium">Size</th>
              <th className="hidden px-5 py-2 font-medium lg:table-cell">Location</th>
              <th />
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {data.data.map((b) => (
              <tr key={b.id} className="align-top">
                <td className="px-5 py-3">
                  <div className="font-medium">{b.label}</div>
                  <div className="muted text-xs" title={formatDateTime(b.created_at)}>
                    {timeAgo(b.created_at)} · {b.trigger === 'pre_restore' ? 'safety backup before restore' : b.trigger}
                  </div>
                  {b.error && <div className="mt-1 max-w-md text-xs text-red-600 dark:text-red-400">{b.error}</div>}
                </td>
                {showProject && <td className="px-5 py-3">{b.project ? <Link className="hover:underline" to={`/projects/${b.project.slug}`}>{b.project.name}</Link> : <span className="muted">—</span>}</td>}
                <td className="px-5 py-3">
                  <div className="flex flex-col items-start gap-1">
                    <JobStatusBadge status={b.status} />
                    {b.verified_at && <Badge tone="green">Verified</Badge>}
                  </div>
                </td>
                <td className="px-5 py-3 tabular-nums">{formatBytes(b.size_bytes)}</td>
                <td className="hidden px-5 py-3 lg:table-cell">
                  <div className="mono max-w-xs truncate text-xs" title={b.location ?? ''}>{b.storage}: {b.location ?? '—'}</div>
                  {b.checksum_sha256 && <div className="muted mono text-[11px]" title={b.checksum_sha256}>sha256 {b.checksum_sha256.slice(0, 16)}…</div>}
                </td>
                <td className="px-5 py-3">
                  <div className="flex justify-end gap-1">
                    {b.status === 'success' && b.source_exists && b.trigger !== 'pre_restore' && (
                      <Button size="sm" icon={<RotateCcw className="size-3.5" />} onClick={() => { setRestoreError(null); setRestoring(b) }}>Restore</Button>
                    )}
                    {b.status === 'success' && (
                      <Button size="sm" variant="ghost" icon={<Download className="size-3.5" />} title="Download" aria-label="Download backup" onClick={() => download(b)} />
                    )}
                    {(b.status === 'success' || b.status === 'failed') && <Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => setDeleting(b)} aria-label="Delete backup" />}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data.meta.last_page > 1 && (
        <div className="flex items-center justify-between border-t border-zinc-100 px-5 py-3 text-sm dark:border-zinc-800">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</Button>
          <span className="muted">Page {page} of {data.meta.last_page}</span>
          <Button size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Older</Button>
        </div>
      )}

      <ConfirmDialog
        open={!!restoring}
        onOpenChange={(o) => !o && setRestoring(null)}
        title={`Restore ${restoring?.label}?`}
        description={`The current ${restoring?.type === 'database' ? 'database contents are replaced' : 'files in the volume are replaced'} with the backup from ${formatDateTime(restoring?.created_at)}. A safety backup of the current data is taken first.${restoring?.type === 'volume' ? ' The application is stopped while files are restored.' : ''}`}
        confirmLabel="Restore backup"
        destructive
        confirmText={restoring ? sourceName(restoring) : undefined}
        loading={restore.isPending}
        error={restoreError}
        onConfirm={(typed) => restoring && restore.mutate({ backup: restoring, confirm: typed })}
      />
      <ConfirmDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)} title="Delete this backup?" description="The backup file is permanently removed." confirmLabel="Delete backup" destructive loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} />
    </>
  )
}
