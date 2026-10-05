import { Check, Loader2, X } from 'lucide-react'
import { classNames } from '../lib/format'
import type { Deployment, DeploymentStatus } from '../lib/types'

const buildStages: { key: DeploymentStatus; label: string }[] = [
  { key: 'queued', label: 'Queued' },
  { key: 'cloning', label: 'Fetching source' },
  { key: 'building', label: 'Building' },
  { key: 'starting', label: 'Starting' },
  { key: 'health_checking', label: 'Health checking' },
  { key: 'routing', label: 'Routing' },
  { key: 'success', label: 'Live' },
]

/** Visual progress of a deployment through its real backend states. */
export function DeploymentTimeline({ deployment }: { deployment: Deployment }) {
  // Rollbacks and redeploys reuse an existing image: no fetch/build stages.
  const stages = deployment.type === 'deploy' ? buildStages : buildStages.filter((s) => s.key !== 'cloning' && s.key !== 'building')
  const failedAt = deployment.status === 'failed' ? deployment.failure?.stage ?? null : null
  const currentIndex = (() => {
    if (deployment.status === 'success') return stages.length - 1
    if (deployment.status === 'failed' || deployment.status === 'cancelled') {
      const idx = stages.findIndex((s) => s.key === failedAt)
      return idx === -1 ? 0 : idx
    }
    return Math.max(0, stages.findIndex((s) => s.key === deployment.status))
  })()

  return (
    <ol className="flex flex-wrap items-center gap-x-1 gap-y-2" aria-label="Deployment progress">
      {stages.map((stage, i) => {
        const done = i < currentIndex || deployment.status === 'success'
        const current = i === currentIndex && deployment.is_active
        const failed = i === currentIndex && (deployment.status === 'failed' || deployment.status === 'cancelled')
        return (
          <li key={stage.key} className="flex items-center gap-1" aria-current={current ? 'step' : undefined}>
            <span
              className={classNames(
                'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                done && 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                current && 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
                failed && 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
                !done && !current && !failed && 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500',
              )}
            >
              {done && <Check className="size-3" aria-hidden />}
              {current && <Loader2 className="size-3 animate-spin" aria-hidden />}
              {failed && <X className="size-3" aria-hidden />}
              {failed && deployment.status === 'cancelled' ? 'Cancelled' : stage.label}
            </span>
            {i < stages.length - 1 && <span className="h-px w-3 bg-zinc-200 dark:bg-zinc-700" aria-hidden />}
          </li>
        )
      })}
    </ol>
  )
}
