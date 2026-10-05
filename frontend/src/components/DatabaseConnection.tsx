import { api } from '../lib/api'
import type { Database } from '../lib/types'
import { KeyValue } from './ui/Card'
import { CopyButton, SecretValue } from './ui/Copy'
import { Button } from './ui/Button'
import { Copy } from 'lucide-react'
import { copyToClipboard } from './ui/Copy'
import { toast } from 'sonner'
import { errorMessage } from '../lib/api'

/** Connection details with the password hidden until revealed (password-confirmed). */
export function DatabaseConnection({ database }: { database: Database }) {
  const reveal = () => api<{ password: string; connection_string: string }>(`/databases/${database.id}/reveal`, { method: 'POST' })
  return (
    <div className="space-y-4">
      <dl className="grid gap-5 sm:grid-cols-3">
        <KeyValue label="Host"><span className="mono inline-flex items-center gap-1">{database.host} <CopyButton value={database.host} /></span></KeyValue>
        <KeyValue label="Port"><span className="mono">{database.port}</span></KeyValue>
        <KeyValue label="Database"><span className="mono inline-flex items-center gap-1">{database.database} <CopyButton value={database.database} /></span></KeyValue>
        <KeyValue label="Username"><span className="mono inline-flex items-center gap-1">{database.username} <CopyButton value={database.username} /></span></KeyValue>
        <KeyValue label="Password"><SecretValue reveal={() => reveal().then((r) => r.password)} /></KeyValue>
      </dl>
      <div className="flex flex-wrap items-center gap-2">
        <code className="mono muted max-w-full truncate rounded bg-zinc-100 px-2 py-1 dark:bg-zinc-800">{database.connection_string}</code>
        <Button size="sm" icon={<Copy className="size-3.5" />} onClick={() => reveal().then((r) => copyToClipboard(r.connection_string, 'Connection string copied')).catch((e) => toast.error(errorMessage(e)))}>Copy Connection String</Button>
      </div>
      <p className="muted text-xs">The host <span className="mono">{database.host}</span> is reachable only from this project's containers. The database is not exposed to the internet.</p>
    </div>
  )
}
