import { useState, type ReactNode } from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { AlertTriangle, X } from 'lucide-react'
import { Button } from './Button'
import { Input } from './Field'
import { classNames } from '../../lib/format'

export function Dialog({ open, onOpenChange, title, description, children, footer, size = 'md' }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: ReactNode
  description?: ReactNode
  children?: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
}) {
  const width = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }[size]
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-zinc-950/40 backdrop-blur-[2px]" />
        <DialogPrimitive.Content className={classNames('fixed top-1/2 left-1/2 z-50 flex max-h-[90vh] w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-900', width)}>
          <div className="flex items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <div>
              <DialogPrimitive.Title className="text-base font-semibold">{title}</DialogPrimitive.Title>
              {description ? <DialogPrimitive.Description className="muted mt-1 text-sm">{description}</DialogPrimitive.Description> : <DialogPrimitive.Description className="sr-only">{title}</DialogPrimitive.Description>}
            </div>
            <DialogPrimitive.Close className="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800" aria-label="Close">
              <X className="size-4" />
            </DialogPrimitive.Close>
          </div>
          {children && <div className="overflow-y-auto px-5 py-4">{children}</div>}
          {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">{footer}</div>}
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}

/**
 * Confirmation dialog. With `confirmText`, the administrator must type that
 * exact value (e.g. the project name) before the action is enabled.
 */
export function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel = 'Confirm', destructive = false, confirmText, onConfirm, loading = false, children, error }: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: ReactNode
  description?: ReactNode
  confirmLabel?: string
  destructive?: boolean
  confirmText?: string
  onConfirm: (typed: string) => void
  loading?: boolean
  children?: ReactNode
  error?: string | null
}) {
  const [typed, setTyped] = useState('')
  const ready = !confirmText || typed === confirmText
  return (
    <Dialog
      open={open}
      onOpenChange={(value) => {
        if (!value) setTyped('')
        onOpenChange(value)
      }}
      title={
        <span className="flex items-center gap-2">
          {destructive && <AlertTriangle className="size-4 text-red-500" aria-hidden />}
          {title}
        </span>
      }
      description={description}
      footer={
        <>
          <Button onClick={() => onOpenChange(false)} disabled={loading}>Cancel</Button>
          <Button variant={destructive ? 'danger' : 'primary'} disabled={!ready} loading={loading} onClick={() => onConfirm(typed)}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {children}
        {confirmText && (
          <div className="space-y-1.5">
            <label className="text-sm">
              Type <span className="mono rounded bg-zinc-100 px-1.5 py-0.5 font-semibold dark:bg-zinc-800">{confirmText}</span> to confirm
            </label>
            <Input value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" autoFocus aria-label="Confirmation text" />
          </div>
        )}
        {error && <p className="text-sm text-red-600 dark:text-red-400" role="alert">{error}</p>}
      </div>
    </Dialog>
  )
}
