import { useQuery } from '@tanstack/react-query'
import { Database, HardDrive, Package } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, formatDateTime, timeAgo } from '../lib/format'
import { Card, CardHeader, PageHeader } from '../components/ui/Card'
import { Callout } from '../components/ui/Feedback'
import { Segmented } from '../components/ui/Tabs'
import { BackupsTable } from '../components/BackupsTable'
import { useState } from 'react'

interface Summary { last_database_backup: string | null; last_volume_backup: string | null; total_size_bytes: number; count: number; running: number; storage: string; off_server: boolean; warning: string | null }

export default function BackupsPage() {
  const [type, setType] = useState<'all' | 'database' | 'volume'>('all')
  const { data } = useQuery({ queryKey: ['backups-summary'], queryFn: () => api<Summary>('/backups/summary'), refetchInterval: 30_000 })
  return (
    <>
      <PageHeader title="Backups" description="Database dumps and volume archives. Every backup is verified and checksummed before it is marked as completed." />
      {data?.warning && <div className="mb-6"><Callout tone="warning" title="Backups are only stored on this server">{data.warning}</Callout></div>}
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <div className="card p-4"><div className="muted flex items-center gap-2 text-xs font-medium uppercase"><Database className="size-3.5" /> Database</div><div className="mt-2 text-lg font-semibold">{data?.last_database_backup ? timeAgo(data.last_database_backup) : 'Never'}</div><div className="muted text-xs">{data?.last_database_backup ? `Last backup ${formatDateTime(data.last_database_backup)}` : 'No database backup yet'}</div></div>
        <div className="card p-4"><div className="muted flex items-center gap-2 text-xs font-medium uppercase"><HardDrive className="size-3.5" /> Files</div><div className="mt-2 text-lg font-semibold">{data?.last_volume_backup ? timeAgo(data.last_volume_backup) : 'Never'}</div><div className="muted text-xs">{data?.last_volume_backup ? `Last backup ${formatDateTime(data.last_volume_backup)}` : 'No volume backup yet'}</div></div>
        <div className="card p-4"><div className="muted flex items-center gap-2 text-xs font-medium uppercase"><Package className="size-3.5" /> Stored</div><div className="mt-2 text-lg font-semibold">{formatBytes(data?.total_size_bytes ?? 0)}</div><div className="muted text-xs">{data?.count ?? 0} backups · {data?.storage ?? 'local'} storage{data?.running ? ` · ${data.running} running` : ''}</div></div>
      </div>
      <Card>
        <CardHeader title="All backups" description="Start backups from a project's or database's page." actions={<Segmented value={type} onChange={setType} label="Backup type" options={[{ value: 'all', label: 'All' }, { value: 'database', label: 'Databases' }, { value: 'volume', label: 'Files' }]} />} />
        <BackupsTable showProject type={type === 'all' ? undefined : type} />
      </Card>
    </>
  )
}
