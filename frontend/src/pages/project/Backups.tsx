import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Archive } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { useProject } from '../../hooks/project'
import { Button } from '../../components/ui/Button'
import { Card, CardHeader } from '../../components/ui/Card'
import { BackupsTable } from '../../components/BackupsTable'

export default function ProjectBackups() {
  const project = useProject()
  const queryClient = useQueryClient()
  const backupNow = useMutation({
    mutationFn: () => api<{ data: unknown[] }>('/backups', { method: 'POST', body: { project: project.slug } }),
    onSuccess: (r) => {
      toast.success(`${r.data.length} backup(s) started`)
      queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const hasSources = !!project.database || (project.volumes ?? []).length > 0

  return (
    <Card>
      <CardHeader
        title="Backups"
        icon={<Archive className="size-4" />}
        description={
          <>
            Schedule: <strong>{project.backup_schedule === 'off' ? 'off' : `${project.backup_schedule} at ${project.backup_time} (server time), keep ${project.backup_retention}`}</strong> · <Link to="../settings" relative="path" className="underline">change</Link>
          </>
        }
        actions={<Button variant="primary" loading={backupNow.isPending} disabled={!hasSources} onClick={() => backupNow.mutate()} title={hasSources ? undefined : 'Add a database or volume first'}>Backup Now</Button>}
      />
      {!hasSources && <p className="muted px-5 py-3 text-sm">This project has no database or volume, so there is nothing to back up yet.</p>}
      <BackupsTable project={project.slug} />
    </Card>
  )
}
