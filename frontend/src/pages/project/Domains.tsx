import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Globe, Plus, RefreshCw, Star, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatDateTime, timeAgo } from '../../lib/format'
import type { Domain } from '../../lib/types'
import { useInvalidateProject, useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardHeader } from '../../components/ui/Card'
import { CopyButton } from '../../components/ui/Copy'
import { ConfirmDialog } from '../../components/ui/Dialog'
import { EmptyState, ErrorState, LoadingBlock } from '../../components/ui/Feedback'
import { Input } from '../../components/ui/Field'
import { Badge } from '../../components/ui/Status'

export function DomainBadges({ domain }: { domain: Domain }) {
  const dns = domain.dns.status
  const cert = domain.certificate.status
  return (
    <div className="flex flex-wrap gap-1.5">
      {dns === 'ok' ? <Badge tone="green">DNS OK</Badge> : dns === 'missing' ? <Badge tone="amber">DNS not ready</Badge> : dns === 'mismatch' ? <Badge tone="red">DNS points elsewhere</Badge> : <Badge>DNS unknown</Badge>}
      {cert === 'active' ? <Badge tone="green">HTTPS active</Badge> : cert === 'pending' ? <Badge tone="amber" pulse>HTTPS pending</Badge> : cert === 'failed' ? <Badge tone="red">HTTPS failed</Badge> : <Badge>HTTP only</Badge>}
    </div>
  )
}

export default function Domains() {
  const project = useProject()
  const queryClient = useQueryClient()
  const invalidateProject = useInvalidateProject(project.slug)
  const base = `/projects/${project.slug}/domains`
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['domains', project.slug],
    queryFn: () => api<{ data: Domain[]; server_ip: string | null; https_enabled: boolean }>(base),
    refetchInterval: (q) => (q.state.data?.data.some((d) => d.certificate.status === 'pending') ? 15_000 : false),
  })
  const [hostname, setHostname] = useState('')
  const [addError, setAddError] = useState<string | null>(null)
  const [removing, setRemoving] = useState<Domain | null>(null)
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['domains', project.slug] })
    invalidateProject()
  }

  const add = useMutation({
    mutationFn: () => api<{ data: Domain }>(base, { method: 'POST', body: { hostname } }),
    onSuccess: (r) => {
      toast.success(`${r.data.hostname} added`)
      setHostname('')
      setAddError(null)
      invalidate()
    },
    onError: (e) => setAddError(errorMessage(e)),
  })
  const check = useMutation({
    mutationFn: (id: number) => api(`${base}/${id}/check`, { method: 'POST' }),
    onSuccess: invalidate,
    onError: (e) => toast.error(errorMessage(e)),
  })
  const primary = useMutation({ mutationFn: (id: number) => api(`${base}/${id}/primary`, { method: 'POST' }), onSuccess: invalidate })
  const remove = useMutation({
    mutationFn: (id: number) => api(`${base}/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      toast.success('Domain removed')
      setRemoving(null)
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (error) return <ErrorState error={error} retry={refetch} />
  if (isLoading || !data) return <LoadingBlock />

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Domains" description={data.https_enabled ? 'HTTPS certificates are issued automatically by Let’s Encrypt once DNS points to this server.' : 'HTTPS is disabled on this installation (development mode).'} />
        <form className="flex flex-wrap gap-2 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
          <Input className="max-w-sm" placeholder="app.example.com" value={hostname} onChange={(e) => setHostname(e.target.value)} aria-label="Domain name" invalid={!!addError} />
          <Button type="submit" variant="primary" icon={<Plus className="size-4" />} loading={add.isPending} disabled={!hostname.trim()}>Add Domain</Button>
          {addError && <p className="w-full text-xs text-red-600">{addError}</p>}
        </form>
        {data.data.length === 0 ? (
          <EmptyState icon={<Globe className="size-5" />} title="No domains" description="Add a domain to make the application reachable from the internet." />
        ) : (
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {data.data.map((d) => (
              <li key={d.id} className="space-y-3 px-5 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <a href={`${data.https_enabled ? 'https' : 'http'}://${d.hostname}`} target="_blank" rel="noopener noreferrer" className="truncate font-medium hover:underline">{d.unicode_hostname}</a>
                      {d.is_primary && <Badge tone="blue">Primary</Badge>}
                    </div>
                    <p className="muted mt-0.5 text-xs">Checked {timeAgo(d.certificate.checked_at ?? d.dns.checked_at)}{d.certificate.expires_at && ` · certificate valid until ${formatDateTime(d.certificate.expires_at)}`}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <DomainBadges domain={d} />
                    <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} loading={check.isPending && check.variables === d.id} onClick={() => check.mutate(d.id)}>Check now</Button>
                    {!d.is_primary && <Button size="sm" variant="ghost" icon={<Star className="size-3.5" />} onClick={() => primary.mutate(d.id)}>Make primary</Button>}
                    <Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => setRemoving(d)} aria-label={`Remove ${d.hostname}`} />
                  </div>
                </div>
                {d.dns.status !== 'ok' && (
                  <div className="rounded-lg border border-amber-200 bg-amber-50/60 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20">
                    <p className="font-medium">{d.dns.status === 'mismatch' ? 'This domain points to a different server' : 'Point this domain to your server'}</p>
                    <p className="muted mt-1">At your DNS provider, create this record (changes can take a few minutes to an hour to propagate):</p>
                    <div className="mt-3 grid max-w-xl grid-cols-[5rem_1fr_1fr] gap-2 text-xs">
                      <span className="muted">Type</span><span className="muted">Name</span><span className="muted">Value</span>
                      <span className="mono">A</span>
                      <span className="mono flex items-center gap-1 truncate">{d.hostname} <CopyButton value={d.hostname} /></span>
                      <span className="mono flex items-center gap-1">{d.dns.expected_ip ?? 'your server IP'} {d.dns.expected_ip && <CopyButton value={d.dns.expected_ip} />}</span>
                    </div>
                    {d.dns.records && (d.dns.records.a.length > 0 || d.dns.records.aaaa.length > 0) && (
                      <p className="muted mt-3 text-xs">Currently resolves to: <span className="mono">{[...d.dns.records.a, ...d.dns.records.aaaa].join(', ')}</span></p>
                    )}
                  </div>
                )}
                {d.certificate.status === 'failed' && d.certificate.error && (
                  <p className="text-sm text-red-700 dark:text-red-400">{d.certificate.error}</p>
                )}
                {d.certificate.status === 'pending' && d.dns.status === 'ok' && (
                  <p className="muted text-sm">DNS is correct. The certificate is being requested — this usually takes less than a minute.</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
      <ConfirmDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)} title={`Remove ${removing?.hostname}?`} description="The domain stops routing to this application immediately." confirmLabel="Remove domain" destructive loading={remove.isPending} onConfirm={() => removing && remove.mutate(removing.id)} />
    </div>
  )
}
