import { useEffect, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { Archive, Boxes, Cloud, Container, Database, Globe, LayoutDashboard, Menu, Search, Server, Settings, X } from 'lucide-react'
import { classNames } from '../../lib/format'
import { CommandPalette } from './CommandPalette'
import { NotificationsMenu } from './NotificationsMenu'
import { UserMenu } from './UserMenu'

const nav = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/projects', label: 'Projects', icon: Boxes },
  { to: '/databases', label: 'Databases', icon: Database },
  { to: '/containers', label: 'Containers', icon: Container },
  { to: '/domains', label: 'Domains', icon: Globe },
  { to: '/backups', label: 'Backups', icon: Archive },
  { to: '/server', label: 'Server', icon: Server },
  { to: '/settings', label: 'Settings', icon: Settings },
]

export const appName = (import.meta.env.VITE_APP_NAME as string | undefined) ?? 'PrivateCloud'

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <div className="flex h-full flex-col">
      <div className="flex h-14 items-center gap-2 px-4">
        <div className="flex size-7 items-center justify-center rounded-lg bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
          <Cloud className="size-4" aria-hidden />
        </div>
        <span className="text-[15px] font-semibold tracking-tight">{appName}</span>
      </div>
      <nav className="flex-1 space-y-0.5 px-2 py-2" aria-label="Main">
        {nav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            onClick={onNavigate}
            className={({ isActive }) =>
              classNames(
                'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors',
                isActive ? 'bg-zinc-200/70 text-zinc-900 dark:bg-zinc-800 dark:text-white' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100',
              )
            }
          >
            <item.icon className="size-4" aria-hidden />
            {item.label}
          </NavLink>
        ))}
      </nav>
      <div className="muted px-4 py-3 text-xs">Self-hosted · V1</div>
    </div>
  )
}

export function AppShell({ children }: { children?: ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [paletteOpen, setPaletteOpen] = useState(false)
  const location = useLocation()

  useEffect(() => setMobileOpen(false), [location.pathname])

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPaletteOpen((v) => !v)
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  return (
    <div className="flex h-full">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Skip to content</a>
      <aside className="hidden w-60 shrink-0 border-r border-zinc-200 bg-zinc-50/80 lg:block dark:border-zinc-800 dark:bg-zinc-950">
        <Sidebar />
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="absolute inset-0 bg-zinc-950/40" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-64 border-r border-zinc-200 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-950">
            <button className="absolute top-3 right-3 rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" onClick={() => setMobileOpen(false)} aria-label="Close navigation">
              <X className="size-4" />
            </button>
            <Sidebar onNavigate={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-zinc-200 bg-white/80 px-3 backdrop-blur sm:px-6 dark:border-zinc-800 dark:bg-zinc-950/80">
          <button className="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800" onClick={() => setMobileOpen(true)} aria-label="Open navigation">
            <Menu className="size-5" />
          </button>
          <button
            onClick={() => setPaletteOpen(true)}
            className="flex h-9 w-full max-w-md items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-500 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
          >
            <Search className="size-4" aria-hidden />
            <span className="min-w-0 flex-1 truncate text-left"><span className="sm:hidden">Search…</span><span className="hidden sm:inline">Search projects, domains, deployments…</span></span>
            <kbd className="hidden rounded border border-zinc-200 bg-white px-1.5 font-sans text-[11px] sm:inline dark:border-zinc-700 dark:bg-zinc-800">⌘K</kbd>
          </button>
          <div className="ml-auto flex items-center gap-1">
            <NotificationsMenu />
            <UserMenu />
          </div>
        </header>
        <main id="main" className="flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{children ?? <Outlet />}</div>
        </main>
      </div>
      <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
    </div>
  )
}
