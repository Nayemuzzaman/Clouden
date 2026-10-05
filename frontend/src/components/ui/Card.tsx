import type { ReactNode } from 'react'
import { classNames } from '../../lib/format'

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <section className={classNames('card', className)}>{children}</section>
}

export function CardHeader({ title, description, actions, icon }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
      <div className="flex min-w-0 items-start gap-3">
        {icon && <div className="mt-0.5 text-zinc-400">{icon}</div>}
        <div className="min-w-0">
          <h2 className="text-sm font-semibold">{title}</h2>
          {description && <p className="muted mt-0.5 text-sm">{description}</p>}
        </div>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function CardBody({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={classNames('px-5 py-4', className)}>{children}</div>
}

export function PageHeader({ title, description, actions, eyebrow }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; eyebrow?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0">
        {eyebrow && <div className="muted mb-1 text-sm">{eyebrow}</div>}
        <h1 className="truncate text-2xl font-semibold tracking-tight">{title}</h1>
        {description && <p className="muted mt-1 max-w-2xl text-sm">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function KeyValue({ label, children, mono = false }: { label: ReactNode; children: ReactNode; mono?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="muted text-xs font-medium tracking-wide uppercase">{label}</dt>
      <dd className={classNames('mt-1 truncate text-sm', mono && 'mono')}>{children}</dd>
    </div>
  )
}
