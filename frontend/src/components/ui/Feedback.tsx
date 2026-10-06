import type { ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, Loader2, XCircle } from 'lucide-react'
import { classNames } from '../../lib/format'

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={classNames('size-4 animate-spin text-zinc-400', className)} aria-label="Loading" />
}

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-16 text-sm text-zinc-500" role="status">
      <Spinner /> {label}
    </div>
  )
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={classNames('animate-pulse rounded-md bg-zinc-200/70 dark:bg-zinc-800', className)} />
}

export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: ReactNode; description?: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      {icon && <div className="mb-3 rounded-full bg-zinc-100 p-3 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{icon}</div>}
      <h3 className="text-sm font-semibold">{title}</h3>
      {description && <p className="muted mt-1 max-w-sm text-sm">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

type CalloutTone = 'info' | 'warning' | 'error' | 'success'

export function Callout({ tone = 'info', title, children, action }: { tone?: CalloutTone; title?: ReactNode; children?: ReactNode; action?: ReactNode }) {
  const styles: Record<CalloutTone, string> = {
    info: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200',
    error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200',
  }
  const Icon = { info: Info, warning: AlertTriangle, error: XCircle, success: CheckCircle2 }[tone]
  return (
    <div className={classNames('flex gap-3 rounded-lg border px-4 py-3 text-sm', styles[tone])} role={tone === 'error' ? 'alert' : undefined}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div className="min-w-0 flex-1">
        {title && <p className="font-medium">{title}</p>}
        {children && <div className={classNames(title ? 'mt-1 opacity-90' : '')}>{children}</div>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  const message = error instanceof Error ? error.message : 'Something went wrong.'
  return (
    <div className="p-4">
      <Callout tone="error" title="Could not load this page" action={retry ? <button className="text-sm font-medium underline" onClick={retry}>Retry</button> : undefined}>
        {message}
      </Callout>
    </div>
  )
}

/** Horizontal usage bar. Turns amber/red as it approaches the threshold. */
export function Meter({ value, threshold = 90, className, label }: { value: number | null; threshold?: number; className?: string; label?: string }) {
  const pct = value === null ? 0 : Math.max(0, Math.min(100, value))
  const color = value === null ? 'bg-zinc-300' : pct >= threshold ? 'bg-red-500' : pct >= threshold - 15 ? 'bg-amber-500' : 'bg-emerald-500'
  return (
    <div className={classNames('h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800', className)} role="meter" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={classNames('h-full rounded-full transition-all duration-500', color)} style={{ width: `${pct}%` }} />
    </div>
  )
}
