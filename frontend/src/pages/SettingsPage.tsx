import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError, errorMessage } from '../lib/api'
import { formatDateTime, timeAgo } from '../lib/format'
import type { AuditEntry, Paginated, Thresholds } from '../lib/types'
import { Button } from '../components/ui/Button'
import { Card, CardBody, CardHeader, KeyValue, PageHeader } from '../components/ui/Card'
import { Callout } from '../components/ui/Feedback'
import { Field, Input } from '../components/ui/Field'
import { Badge } from '../components/ui/Status'
import { Segmented } from '../components/ui/Tabs'
import { GitHubIcon } from '../components/GitHubIcon'

interface SettingsData {
  name: string
  dashboard_domain: string | null
  webhook_base_url: string | null
  public_ipv4: string | null
  https: boolean
  thresholds: Thresholds
  metrics_retention_days: number
  backup_storage: { driver: string; off_server: boolean }
  github: { connected: boolean; login?: string; name?: string | null; avatar_url?: string | null; scopes?: string | null; last_verified_at?: string | null }
}

function GitHubSettings({ settings }: { settings: SettingsData }) {
  const queryClient = useQueryClient()
  const [token, setToken] = useState('')
  const [error, setError] = useState<string | null>(null)
  const connect = useMutation({
    mutationFn: () => api<{ login: string }>('/settings/github', { method: 'POST', body: { token } }),
    onSuccess: (r) => { toast.success(`Connected as ${r.login}`); setToken(''); setError(null); queryClient.invalidateQueries({ queryKey: ['settings'] }); queryClient.invalidateQueries({ queryKey: ['github-repos'] }) },
    onError: (e) => setError(e instanceof ApiError ? e.field('token') ?? e.message : errorMessage(e)),
  })
  const disconnect = useMutation({
    mutationFn: () => api('/settings/github', { method: 'DELETE' }),
    onSuccess: () => { toast.success('GitHub disconnected'); queryClient.invalidateQueries({ queryKey: ['settings'] }) },
  })
  const gh = settings.github
  return (
    <Card>
      <CardHeader title={<span className="flex items-center gap-2"><GitHubIcon /> GitHub</span>} description="Needed for private repositories and automatic webhooks. The token is encrypted and never sent to the browser." />
      <CardBody className="space-y-4">
        {gh.connected ? (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              {gh.avatar_url && <img src={gh.avatar_url} alt="" className="size-9 rounded-full" />}
              <div><p className="font-medium">{gh.name ?? gh.login} <span className="muted font-normal">@{gh.login}</span></p><p className="muted text-xs">Verified {timeAgo(gh.last_verified_at)}{gh.scopes ? ` · scopes: ${gh.scopes}` : ' · fine-grained token'}</p></div>
            </div>
            <Button variant="outline-danger" size="sm" loading={disconnect.isPending} onClick={() => disconnect.mutate()}>Disconnect</Button>
          </div>
        ) : (
          <>
            <Callout tone="info" title="Create a fine-grained personal access token">
              On GitHub: Settings → Developer settings → Personal access tokens → Fine-grained tokens. Give it access to the repositories you deploy with <strong>Contents: Read</strong> and <strong>Metadata: Read</strong>. Add <strong>Webhooks: Read and write</strong> to let PrivateCloud install auto-deploy webhooks.
            </Callout>
            <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); connect.mutate() }}>
              <Input type="password" className="mono max-w-md" placeholder="github_pat_…" value={token} onChange={(e) => setToken(e.target.value.trim())} aria-label="GitHub token" autoComplete="off" invalid={!!error} />
              <Button type="submit" variant="primary" loading={connect.isPending} disabled={token.length < 20}>Connect GitHub</Button>
              {error && <p className="w-full text-xs text-red-600">{error}</p>}
            </form>
          </>
        )}
      </CardBody>
    </Card>
  )
}

function ThresholdSettings({ settings }: { settings: SettingsData }) {
  const queryClient = useQueryClient()
  const [values, setValues] = useState(settings.thresholds)
  useEffect(() => setValues(settings.thresholds), [settings.thresholds])
  const save = useMutation({
    mutationFn: () => api('/settings/thresholds', { method: 'PUT', body: values }),
    onSuccess: () => { toast.success('Thresholds saved'); queryClient.invalidateQueries({ queryKey: ['settings'] }) },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Card>
      <CardHeader title="Resource warnings" description={`You get a notification when usage crosses these levels (CPU must stay above its threshold for 5 minutes). Metrics are kept for ${settings.metrics_retention_days} days.`} />
      <CardBody className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-3">
          {(['cpu', 'memory', 'disk'] as const).map((k) => (
            <Field key={k} label={`${k === 'cpu' ? 'CPU' : k[0].toUpperCase() + k.slice(1)} (%)`}>{(id) => <Input id={id} type="number" min={10} max={100} value={values[k]} onChange={(e) => setValues({ ...values, [k]: Number(e.target.value) })} />}</Field>
          ))}
        </div>
        <div className="flex justify-end"><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Save</Button></div>
      </CardBody>
    </Card>
  )
}

function PasswordSettings() {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const save = useMutation({
    mutationFn: () => api('/auth/password', { method: 'PUT', body: form }),
    onSuccess: () => { toast.success('Password changed. Other sessions were signed out.'); setForm({ current_password: '', password: '', password_confirmation: '' }); setErrors({}) },
    onError: (e) => (e instanceof ApiError ? setErrors(e.errors) : toast.error(errorMessage(e))),
  })
  return (
    <Card>
      <CardHeader title="Password" description="At least 12 characters with letters and numbers." />
      <CardBody>
        <form className="grid max-w-xl gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Field label="Current password" error={errors.current_password?.[0]}>{(id) => <Input id={id} type="password" autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} />}</Field>
          <Field label="New password" error={errors.password?.[0]}>{(id) => <Input id={id} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />}</Field>
          <Field label="Confirm new password">{(id) => <Input id={id} type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />}</Field>
          <div><Button type="submit" variant="primary" loading={save.isPending} disabled={!form.current_password || !form.password}>Change password</Button></div>
        </form>
      </CardBody>
    </Card>
  )
}

function AuditLog() {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<'all' | 'success' | 'failure'>('all')
  const { data } = useQuery({ queryKey: ['audit', page, result], queryFn: () => api<Paginated<AuditEntry>>('/audit-logs', { query: { page, result: result === 'all' ? undefined : result } }) })
  return (
    <Card>
      <CardHeader title="Audit log" description="Important actions taken in this dashboard and by the system. Secret values are never recorded." actions={<Segmented value={result} onChange={(v) => { setResult(v); setPage(1) }} label="Result" options={[{ value: 'all', label: 'All' }, { value: 'success', label: 'Succeeded' }, { value: 'failure', label: 'Failed' }]} />} />
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="muted text-left text-xs"><tr><th className="px-5 py-2 font-medium">Time</th><th className="px-5 py-2 font-medium">Action</th><th className="px-5 py-2 font-medium">Resource</th><th className="px-5 py-2 font-medium">By</th><th className="hidden px-5 py-2 font-medium md:table-cell">IP</th><th className="px-5 py-2 font-medium">Result</th></tr></thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {data?.data.map((a) => (
              <tr key={a.id}>
                <td className="muted px-5 py-2 text-xs whitespace-nowrap" title={formatDateTime(a.created_at)}>{timeAgo(a.created_at)}</td>
                <td className="mono px-5 py-2 text-xs">{a.action}</td>
                <td className="max-w-xs truncate px-5 py-2">{a.resource_label ?? '—'}</td>
                <td className="muted px-5 py-2 text-xs">{a.user}</td>
                <td className="mono muted hidden px-5 py-2 text-xs md:table-cell">{a.ip_address ?? '—'}</td>
                <td className="px-5 py-2"><Badge tone={a.result === 'success' ? 'green' : 'red'}>{a.result}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between border-t border-zinc-100 px-5 py-3 text-sm dark:border-zinc-800">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</Button>
          <span className="muted">Page {page} of {data.meta.last_page}</span>
          <Button size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Older</Button>
        </div>
      )}
    </Card>
  )
}

export default function SettingsPage() {
  const { data } = useQuery({ queryKey: ['settings'], queryFn: () => api<SettingsData>('/settings') })
  return (
    <>
      <PageHeader title="Settings" />
      {data && (
        <div className="space-y-6">
          <Card>
            <CardHeader title="Installation" />
            <CardBody>
              <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <KeyValue label="Dashboard domain">{data.dashboard_domain ?? '—'}</KeyValue>
                <KeyValue label="Server IPv4" mono>{data.public_ipv4 ?? 'Not configured'}</KeyValue>
                <KeyValue label="HTTPS for projects">{data.https ? 'Automatic (Let’s Encrypt)' : 'Disabled (development)'}</KeyValue>
                <KeyValue label="Backup storage">{data.backup_storage.driver}{data.backup_storage.off_server ? '' : ' (this server)'}</KeyValue>
              </dl>
              <p className="muted mt-4 text-xs">These values come from the installation's .env file. See docs/troubleshooting.md to change them.</p>
            </CardBody>
          </Card>
          <GitHubSettings settings={data} />
          <ThresholdSettings settings={data} />
          <PasswordSettings />
          <AuditLog />
        </div>
      )}
    </>
  )
}
