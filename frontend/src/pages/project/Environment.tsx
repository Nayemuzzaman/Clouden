import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileUp, KeyRound, Lock, Pencil, Plus, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../../lib/api'
import type { EnvVar } from '../../lib/types'
import { useDeploy, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardHeader } from '../../components/ui/Card'
import { CopyButton, SecretValue } from '../../components/ui/Copy'
import { ConfirmDialog, Dialog } from '../../components/ui/Dialog'
import { Callout, EmptyState, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Input, Switch, Textarea } from '../../components/ui/Field'
import { Badge } from '../../components/ui/Status'

function looksSecret(key: string) {
  return /(KEY|SECRET|PASSWORD|PASSWD|TOKEN|PRIVATE|CREDENTIAL|DSN|DATABASE_URL|_PASS$)/i.test(key)
}

export default function Environment() {
  const project = useProject()
  const queryClient = useQueryClient()
  const deploy = useDeploy(project.slug)
  const base = `/projects/${project.slug}/environment`
  const queryKey = ['environment', project.slug]
  const { data, isLoading, error, refetch } = useQuery({ queryKey, queryFn: () => api<{ data: EnvVar[]; pending_redeploy: boolean }>(base) })
  const invalidate = () => queryClient.invalidateQueries({ queryKey })

  const [newKey, setNewKey] = useState('')
  const [newValue, setNewValue] = useState('')
  const [newSecret, setNewSecret] = useState<boolean | null>(null)
  const [addError, setAddError] = useState<string | null>(null)
  const [editing, setEditing] = useState<number | null>(null)
  const [editValue, setEditValue] = useState('')
  const [deleting, setDeleting] = useState<EnvVar | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [importText, setImportText] = useState('')
  const [overwrite, setOverwrite] = useState(false)

  const add = useMutation({
    mutationFn: () => api(base, { method: 'POST', body: { key: newKey.trim(), value: newValue, is_secret: newSecret ?? looksSecret(newKey) } }),
    onSuccess: () => {
      toast.success(`${newKey} added`)
      setNewKey('')
      setNewValue('')
      setNewSecret(null)
      setAddError(null)
      invalidate()
    },
    onError: (e) => setAddError(e instanceof ApiError ? e.field('key') ?? e.message : errorMessage(e)),
  })
  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) => api(`${base}/${id}`, { method: 'PUT', body }),
    onSuccess: () => {
      setEditing(null)
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (id: number) => api(`${base}/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      toast.success('Variable deleted')
      setDeleting(null)
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const bulk = useMutation({
    mutationFn: () => api<{ created: number; updated: number; skipped: number; errors: string[] }>(`${base}/import`, { method: 'POST', body: { content: importText, overwrite } }),
    onSuccess: (r) => {
      toast.success(`${r.created} added, ${r.updated} updated${r.skipped ? `, ${r.skipped} skipped (already exist)` : ''}`)
      r.errors.forEach((e) => toast.warning(e))
      setImportOpen(false)
      setImportText('')
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const reveal = (id: number) => api<{ value: string }>(`${base}/${id}/reveal`, { method: 'POST' }).then((r) => r.value)

  if (error) return <ErrorState error={error} retry={refetch} />
  if (isLoading || !data) return <LoadingBlock />

  return (
    <div className="space-y-6">
      {data.pending_redeploy && (
        <Callout tone="warning" title="Changes are not live yet" action={<Button size="sm" variant="primary" loading={deploy.isPending} onClick={() => deploy.mutate({ type: 'redeploy' })}>Redeploy</Button>}>
          Environment variables are applied when a container is created. Redeploy to restart the current version with the new values.
        </Callout>
      )}

      <Card>
        <CardHeader
          title="Environment variables"
          description="Encrypted at rest. Secret values stay hidden until you reveal them with your password."
          actions={<Button size="sm" icon={<FileUp className="size-3.5" />} onClick={() => setImportOpen(true)}>Import .env</Button>}
        />
        <form
          className="flex flex-wrap items-start gap-2 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800"
          onSubmit={(e) => {
            e.preventDefault()
            add.mutate()
          }}
        >
          <div className="w-full sm:w-64">
            <Input className="mono" placeholder="NEW_VARIABLE" value={newKey} onChange={(e) => setNewKey(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} aria-label="New variable name" invalid={!!addError} />
          </div>
          <div className="min-w-48 flex-1">
            <Input className="mono" placeholder="value" type={newSecret ?? looksSecret(newKey) ? 'password' : 'text'} value={newValue} onChange={(e) => setNewValue(e.target.value)} aria-label="New variable value" autoComplete="off" />
          </div>
          <label className="flex h-9 items-center gap-2 text-sm">
            <input type="checkbox" checked={newSecret ?? looksSecret(newKey)} onChange={(e) => setNewSecret(e.target.checked)} className="size-4" /> Secret
          </label>
          <Button type="submit" variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={!newKey}>Add</Button>
          {addError && <p className="w-full text-xs text-red-600">{addError}</p>}
        </form>

        {data.data.length === 0 ? (
          <EmptyState icon={<KeyRound className="size-5" />} title="No variables" description="Add configuration such as APP_ENV or API keys. They are passed to the container at start." />
        ) : (
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {data.data.map((v) => (
              <li key={v.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                <div className="flex w-full min-w-0 items-center gap-2 sm:w-64">
                  <span className="mono truncate font-medium">{v.key}</span>
                  <CopyButton value={v.key} label="Copy name" />
                </div>
                <div className="min-w-0 flex-1">
                  {editing === v.id ? (
                    <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); update.mutate({ id: v.id, body: { value: editValue } }) }}>
                      <Input className="mono" value={editValue} onChange={(e) => setEditValue(e.target.value)} autoFocus aria-label={`New value for ${v.key}`} type={v.is_secret ? 'password' : 'text'} autoComplete="off" />
                      <Button type="submit" size="md" variant="primary" icon={<Check className="size-4" />} loading={update.isPending} aria-label="Save" />
                      <Button size="md" icon={<X className="size-4" />} onClick={() => setEditing(null)} aria-label="Cancel" />
                    </form>
                  ) : v.is_secret ? (
                    <SecretValue reveal={() => reveal(v.id)} />
                  ) : (
                    <span className="flex min-w-0 items-center gap-1"><span className="mono truncate">{v.value || <span className="muted italic">empty</span>}</span>{v.value && <CopyButton value={v.value} />}</span>
                  )}
                </div>
                <div className="flex items-center gap-1">
                  {v.is_system && <Badge tone="blue">Managed</Badge>}
                  {v.available_at_build && <Badge tone="gray">Build</Badge>}
                  <button title={v.is_secret ? 'Marked as secret' : 'Mark as secret'} aria-label={v.is_secret ? 'Unmark secret' : 'Mark as secret'} className={'rounded-md p-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800 ' + (v.is_secret ? 'text-amber-600' : 'text-zinc-400')} onClick={() => update.mutate({ id: v.id, body: { is_secret: !v.is_secret } })}>
                    <Lock className="size-3.5" />
                  </button>
                  <button title="Available at build time" aria-label="Toggle build-time availability" className="rounded-md p-1.5 text-xs font-medium text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" onClick={() => update.mutate({ id: v.id, body: { available_at_build: !v.available_at_build } })}>B</button>
                  <button aria-label={`Edit ${v.key}`} className="rounded-md p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800" onClick={() => { setEditing(v.id); setEditValue(v.is_secret ? '' : v.value ?? '') }}>
                    <Pencil className="size-3.5" />
                  </button>
                  <button aria-label={`Delete ${v.key}`} className="rounded-md p-1.5 text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" onClick={() => setDeleting(v)}>
                    <Trash2 className="size-3.5" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <p className="muted text-xs">“Managed” variables were created by PrivateCloud (for example database credentials). Editing one by hand stops it from being updated automatically. “B” makes a variable available as a Docker build argument; build arguments can end up in image layers, so avoid it for secrets.</p>

      <ConfirmDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)} title={`Delete ${deleting?.key}?`} description="The variable is removed from the next deployment." confirmLabel="Delete" destructive loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting.id)} />
      <Dialog
        open={importOpen}
        onOpenChange={setImportOpen}
        title="Import from .env"
        description="Paste the contents of a .env file. Lines starting with # are ignored."
        footer={<><Button onClick={() => setImportOpen(false)}>Cancel</Button><Button variant="primary" loading={bulk.isPending} disabled={!importText.trim()} onClick={() => bulk.mutate()}>Import</Button></>}
      >
        <div className="space-y-4">
          <Textarea rows={10} className="mono" value={importText} onChange={(e) => setImportText(e.target.value)} placeholder={'APP_ENV=production\nAPP_KEY=base64:…\nMAIL_HOST="smtp.example.com"'} aria-label=".env content" />
          <Switch checked={overwrite} onCheckedChange={setOverwrite} label="Overwrite existing variables" description="When off, variables that already exist are skipped." />
        </div>
      </Dialog>
    </div>
  )
}
