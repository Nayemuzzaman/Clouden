import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Link, type LinkProps } from 'react-router'
import { Loader2 } from 'lucide-react'
import { classNames } from '../../lib/format'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'outline-danger'
type Size = 'sm' | 'md' | 'lg'

const variants: Record<Variant, string> = {
  primary: 'bg-zinc-900 text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 shadow-xs',
  secondary: 'bg-white text-zinc-800 border border-zinc-200 hover:bg-zinc-50 dark:bg-zinc-900 dark:text-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800 shadow-xs',
  ghost: 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100',
  danger: 'bg-red-600 text-white hover:bg-red-700 shadow-xs',
  'outline-danger': 'border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-400 dark:hover:bg-red-950/40',
}

const sizes: Record<Size, string> = {
  sm: 'h-8 px-3 text-xs gap-1.5',
  md: 'h-9 px-3.5 text-sm gap-2',
  lg: 'h-11 px-5 text-sm gap-2',
}

const base = 'inline-flex items-center justify-center rounded-lg font-medium whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-50 cursor-pointer'

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  icon?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...props },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || loading} className={classNames(base, variants[variant], sizes[size], className)} {...props}>
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {children}
    </button>
  )
})

export function ButtonLink({ variant = 'secondary', size = 'md', icon, className, children, ...props }: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
  return (
    <Link className={classNames(base, variants[variant], sizes[size], className)} {...props}>
      {icon}
      {children}
    </Link>
  )
}

export function ExternalButton({ href, children, icon, variant = 'secondary', size = 'md' }: { href: string; children: ReactNode; icon?: ReactNode; variant?: Variant; size?: Size }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={classNames(base, variants[variant], sizes[size])}>
      {icon}
      {children}
    </a>
  )
}
