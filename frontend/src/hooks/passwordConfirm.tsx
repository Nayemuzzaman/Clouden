import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ShieldCheck } from 'lucide-react'
import { api, errorMessage, onPasswordConfirmationRequired } from '../lib/api'
import { Dialog } from '../components/ui/Dialog'
import { Button } from '../components/ui/Button'
import { Input } from '../components/ui/Field'

/**
 * When the API answers 423 (password confirmation required) — e.g. revealing a
 * secret — this provider asks for the password once, confirms it, and lets the
 * original request retry automatically.
 */
export function PasswordConfirmProvider({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const resolver = useRef<((ok: boolean) => void) | null>(null)

  useEffect(() => {
    onPasswordConfirmationRequired(
      () =>
        new Promise<boolean>((resolve) => {
          resolver.current = resolve
          setPassword('')
          setError(null)
          setOpen(true)
        }),
    )
    return () => onPasswordConfirmationRequired(null)
  }, [])

  const finish = (ok: boolean) => {
    resolver.current?.(ok)
    resolver.current = null
    setOpen(false)
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/auth/confirm-password', { method: 'POST', body: { password } })
      finish(true)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {children}
      <Dialog
        open={open}
        onOpenChange={(value) => !value && finish(false)}
        size="sm"
        title={<span className="flex items-center gap-2"><ShieldCheck className="size-4 text-brand-500" /> Confirm your password</span>}
        description="For your security, enter your password to view or change sensitive information."
      >
        <form onSubmit={submit} className="space-y-3">
          <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Password" autoComplete="current-password" autoFocus aria-label="Password" invalid={!!error} />
          {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button onClick={() => finish(false)}>Cancel</Button>
            <Button type="submit" variant="primary" loading={busy} disabled={!password}>Confirm</Button>
          </div>
        </form>
      </Dialog>
    </>
  )
}
