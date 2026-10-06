import { useNavigate } from 'react-router'
import * as Dropdown from '@radix-ui/react-dropdown-menu'
import { LogOut, Monitor, Moon, Settings, Sun } from 'lucide-react'
import { useAuth } from '../../hooks/auth'
import { useTheme, type ThemePreference } from '../../hooks/theme'
import { classNames } from '../../lib/format'

const themeOptions: { value: ThemePreference; label: string; icon: typeof Sun }[] = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'System', icon: Monitor },
]

export function UserMenu() {
  const { user, logout } = useAuth()
  const { preference, setPreference, resolved } = useTheme()
  const navigate = useNavigate()
  const initials = (user?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()
  const itemClass = 'flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm outline-none data-[highlighted]:bg-zinc-100 dark:data-[highlighted]:bg-zinc-800'

  return (
    <Dropdown.Root>
      <Dropdown.Trigger className="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-violet-500 text-xs font-semibold text-white" aria-label="Account menu">
        {initials}
      </Dropdown.Trigger>
      <Dropdown.Portal>
        <Dropdown.Content align="end" sideOffset={8} className="z-50 w-60 rounded-xl border border-zinc-200 bg-white p-1.5 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
          <div className="px-2.5 py-2">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="muted truncate text-xs">{user?.email}</p>
          </div>
          <Dropdown.Separator className="my-1 h-px bg-zinc-100 dark:bg-zinc-800" />
          <div className="px-2.5 py-1.5">
            <p className="muted mb-1.5 text-xs">Theme</p>
            <div className="grid grid-cols-3 gap-1">
              {themeOptions.map((o) => (
                <button
                  key={o.value}
                  onClick={() => setPreference(o.value)}
                  className={classNames('flex flex-col items-center gap-1 rounded-md border px-2 py-1.5 text-[11px]', preference === o.value ? 'border-brand-500 text-brand-600 dark:text-brand-500' : 'border-zinc-200 text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800')}
                  aria-pressed={preference === o.value}
                >
                  <o.icon className="size-3.5" /> {o.label}
                </button>
              ))}
            </div>
            <p className="sr-only">Current theme: {resolved}</p>
          </div>
          <Dropdown.Separator className="my-1 h-px bg-zinc-100 dark:bg-zinc-800" />
          <Dropdown.Item className={itemClass} onSelect={() => navigate('/settings')}>
            <Settings className="size-4 text-zinc-400" /> Settings
          </Dropdown.Item>
          <Dropdown.Item className={itemClass} onSelect={() => logout().then(() => navigate('/login'))}>
            <LogOut className="size-4 text-zinc-400" /> Log out
          </Dropdown.Item>
        </Dropdown.Content>
      </Dropdown.Portal>
    </Dropdown.Root>
  )
}
