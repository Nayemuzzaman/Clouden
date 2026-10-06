import { classNames } from '../../lib/format'
import type { DeploymentStatus, JobStatus, ProjectStatus } from '../../lib/types'

type Tone = 'green' | 'amber' | 'red' | 'gray' | 'blue'

const tones: Record<Tone, { badge: string; dot: string }> = {
  green: { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20', dot: 'bg-emerald-500' },
  amber: { badge: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', dot: 'bg-amber-500' },
  red: { badge: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20', dot: 'bg-red-500' },
  gray: { badge: 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-600/30', dot: 'bg-zinc-400' },
  blue: { badge: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20', dot: 'bg-sky-500' },
}

export function Badge({ tone = 'gray', children, pulse = false, className }: { tone?: Tone; children: React.ReactNode; pulse?: boolean; className?: string }) {
  return (
    <span className={classNames('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap', tones[tone].badge, className)}>
      <span className={classNames('size-1.5 rounded-full', tones[tone].dot, pulse && 'animate-pulse-dot')} aria-hidden />
      {children}
    </span>
  )
}

const projectStatus: Record<ProjectStatus, [Tone, string]> = {
  created: ['gray', 'Not deployed'],
  deploying: ['amber', 'Deploying'],
  running: ['green', 'Running'],
  stopped: ['gray', 'Stopped'],
  crashed: ['red', 'Crashed'],
  failed: ['red', 'Failed'],
  deleting: ['amber', 'Deleting'],
}

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
  const [tone, label] = projectStatus[status] ?? ['gray', status]
  return <Badge tone={tone} pulse={status === 'deploying' || status === 'deleting'}>{label}</Badge>
}

const deploymentStatus: Record<DeploymentStatus, [Tone, string]> = {
  queued: ['gray', 'Queued'],
  cloning: ['blue', 'Fetching source'],
  building: ['blue', 'Building'],
  starting: ['blue', 'Starting'],
  health_checking: ['blue', 'Health checking'],
  routing: ['blue', 'Routing'],
  success: ['green', 'Successful'],
  failed: ['red', 'Failed'],
  cancelled: ['gray', 'Cancelled'],
}

export function DeploymentStatusBadge({ status }: { status: DeploymentStatus }) {
  const [tone, label] = deploymentStatus[status] ?? ['gray', status]
  const active = !['success', 'failed', 'cancelled'].includes(status)
  return <Badge tone={tone} pulse={active}>{label}</Badge>
}

const jobStatus: Record<JobStatus, [Tone, string]> = {
  queued: ['gray', 'Queued'],
  running: ['blue', 'Running'],
  success: ['green', 'Completed'],
  failed: ['red', 'Failed'],
}

export function JobStatusBadge({ status }: { status: JobStatus }) {
  const [tone, label] = jobStatus[status] ?? ['gray', status]
  return <Badge tone={tone} pulse={status === 'running' || status === 'queued'}>{label}</Badge>
}

export function ServiceDot({ status }: { status: string }) {
  const tone: Tone = status === 'ok' ? 'green' : status === 'down' ? 'red' : status === 'degraded' ? 'amber' : 'gray'
  return <span className={classNames('inline-block size-2 rounded-full', tones[tone].dot)} aria-hidden />
}
