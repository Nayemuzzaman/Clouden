import { Link } from 'react-router'
import { useMutation } from '@tanstack/react-query'
import { Database as DatabaseIcon, Table2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '../../lib/api'
import { formatBytes } from '../../lib/format'
import { useInvalidateProject, useProject } from '../../hooks/project'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Callout, EmptyState } from '../../components/ui/Feedback'
import { Badge } from '../../components/ui/Status'
import { DatabaseConnection } from '../../components/DatabaseConnection'

export default function DatabaseTab() {
  const project = useProject()
  const invalidate = useInvalidateProject(project.slug)
  const create = useMutation({
    mutationFn: () => api<{ message: string }>(`/projects/${project.slug}/database`, { method: 'POST' }),
    onSuccess: (r) => {
      toast.success(r.message, { duration: 8000 })
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const db = project.database

  if (!db) {
    return (
      <Card>
        <EmptyState
          icon={<DatabaseIcon className="size-5" />}
          title="No database"
          description="Create a private PostgreSQL database for this project. Its connection details are added to the environment automatically."
          action={<Button variant="primary" loading={create.isPending} onClick={() => create.mutate()}>Create PostgreSQL Database</Button>}
        />
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      {db.status === 'failed' && <Callout tone="error" title="The database could not be created">{db.last_error}</Callout>}
      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2">PostgreSQL <Badge tone={db.status === 'ready' ? 'green' : db.status === 'failed' ? 'red' : 'amber'}>{db.status}</Badge></span>}
          description={db.size_bytes ? `Size ${formatBytes(db.size_bytes)}` : undefined}
          actions={<ButtonLink to={`/databases/${db.id}`} variant="primary" icon={<Table2 className="size-4" />}>Open tables & SQL</ButtonLink>}
        />
        <CardBody><DatabaseConnection database={db} /></CardBody>
      </Card>
      <p className="muted text-sm">Reset credentials, back up, restore or delete the database from the <Link to={`/databases/${db.id}`} className="underline">database page</Link>.</p>
    </div>
  )
}
