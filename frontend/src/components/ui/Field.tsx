import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import * as SwitchPrimitive from '@radix-ui/react-switch'
import { classNames } from '../../lib/format'

const control =
  'block w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm shadow-xs placeholder:text-zinc-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-950 dark:placeholder:text-zinc-600'

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(function Input({ className, invalid, ...props }, ref) {
  return <input ref={ref} aria-invalid={invalid || undefined} className={classNames(control, 'h-9', invalid && 'border-red-400 focus:border-red-500 focus:ring-red-500/20', className)} {...props} />
})

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea({ className, invalid, ...props }, ref) {
  return <textarea ref={ref} aria-invalid={invalid || undefined} className={classNames(control, 'py-2', invalid && 'border-red-400', className)} {...props} />
})

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select({ className, children, ...props }, ref) {
  return (
    <select ref={ref} className={classNames(control, 'h-9 pr-8', className)} {...props}>
      {children}
    </select>
  )
})

/** Label + control + help/error text, wired up for accessibility. */
export function Field({ label, help, error, children, htmlFor, optional }: { label: ReactNode; help?: ReactNode; error?: string; children: (id: string) => ReactNode; htmlFor?: string; optional?: boolean }) {
  const generated = useId()
  const id = htmlFor ?? generated
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="flex items-center gap-2 text-sm font-medium">
        {label}
        {optional && <span className="muted text-xs font-normal">Optional</span>}
      </label>
      {children(id)}
      {error ? <p className="text-xs text-red-600 dark:text-red-400" role="alert">{error}</p> : help ? <p className="muted text-xs">{help}</p> : null}
    </div>
  )
}

export function Switch({ checked, onCheckedChange, label, description, disabled, id }: { checked: boolean; onCheckedChange: (value: boolean) => void; label: ReactNode; description?: ReactNode; disabled?: boolean; id?: string }) {
  const generated = useId()
  const switchId = id ?? generated
  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        <label htmlFor={switchId} className="text-sm font-medium">{label}</label>
        {description && <p className="muted mt-0.5 text-xs">{description}</p>}
      </div>
      <SwitchPrimitive.Root
        id={switchId}
        checked={checked}
        disabled={disabled}
        onCheckedChange={onCheckedChange}
        className="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full bg-zinc-300 transition-colors data-[state=checked]:bg-emerald-500 disabled:opacity-50 dark:bg-zinc-700"
      >
        <SwitchPrimitive.Thumb className="block size-4 translate-x-0.5 rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-[18px]" />
      </SwitchPrimitive.Root>
    </div>
  )
}
