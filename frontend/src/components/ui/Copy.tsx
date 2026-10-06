import { useState } from 'react'
import { Check, Copy, Eye, EyeOff } from 'lucide-react'
import { toast } from 'sonner'
import { classNames } from '../../lib/format'

export async function copyToClipboard(text: string, label = 'Copied to clipboard') {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(label)
  } catch {
    toast.error('Could not access the clipboard')
  }
}

export function CopyButton({ value, label = 'Copy', className }: { value: string | (() => Promise<string>); label?: string; className?: string }) {
  const [done, setDone] = useState(false)
  return (
    <button
      type="button"
      className={classNames('inline-flex items-center gap-1 rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200', className)}
      aria-label={label}
      title={label}
      onClick={async () => {
        const text = typeof value === 'string' ? value : await value()
        await copyToClipboard(text)
        setDone(true)
        setTimeout(() => setDone(false), 1500)
      }}
    >
      {done ? <Check className="size-3.5 text-emerald-500" /> : <Copy className="size-3.5" />}
    </button>
  )
}

/** Masked secret with reveal + copy. `reveal` fetches the value on demand (password confirmation is handled by the API client). */
export function SecretValue({ reveal, className }: { reveal: () => Promise<string>; className?: string }) {
  const [value, setValue] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const load = async () => {
    setLoading(true)
    try {
      const v = await reveal()
      setValue(v)
      return v
    } finally {
      setLoading(false)
    }
  }

  return (
    <span className={classNames('inline-flex min-w-0 items-center gap-1', className)}>
      <span className="mono truncate">{value ?? '••••••••••••'}</span>
      <button
        type="button"
        className="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
        aria-label={value ? 'Hide' : 'Reveal'}
        title={value ? 'Hide' : 'Reveal'}
        disabled={loading}
        onClick={() => (value ? setValue(null) : load().catch(() => undefined))}
      >
        {value ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
      </button>
      <CopyButton value={() => (value ? Promise.resolve(value) : load())} />
    </span>
  )
}
