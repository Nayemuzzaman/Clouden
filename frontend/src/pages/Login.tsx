import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { Cloud } from 'lucide-react'
import { useAuth } from '../hooks/auth'
import { errorMessage } from '../lib/api'
import { Button } from '../components/ui/Button'
import { Field, Input } from '../components/ui/Field'
import { Callout } from '../components/ui/Feedback'
import { appName } from '../components/layout/AppShell'

export default function Login() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to="/" replace />

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email, password, remember)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from && from !== '/login' ? from : '/', { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center bg-gradient-to-b from-zinc-50 to-zinc-100 px-4 py-12 dark:from-zinc-950 dark:to-zinc-900">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex size-11 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-lg dark:bg-white dark:text-zinc-900">
            <Cloud className="size-6" aria-hidden />
          </div>
          <h1 className="text-xl font-semibold tracking-tight">Sign in to {appName}</h1>
          <p className="muted mt-1 text-sm">Manage your server, applications and databases.</p>
        </div>
        <form onSubmit={submit} className="card space-y-4 p-6" noValidate>
          {error && <Callout tone="error">{error}</Callout>}
          <Field label="Email">
            {(id) => <Input id={id} type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />}
          </Field>
          <Field label="Password">
            {(id) => <Input id={id} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />}
          </Field>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-4 rounded border-zinc-300" />
            Keep me signed in on this device
          </label>
          <Button type="submit" variant="primary" className="w-full" loading={busy} disabled={!email || !password}>
            Sign in
          </Button>
        </form>
        <p className="muted mt-6 text-center text-xs">
          Forgot the password? Reset it on the server with <code className="mono">privatecloud:admin --reset</code>.
        </p>
      </div>
    </div>
  )
}
